<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Proforma → payments (full, part, several) → tax invoice. Every rule about invoices lives here.
 */
class InvoiceService
{
    /**
     * Creates or updates a draft proforma with its lines and totals.
     *
     * @param  array{client_id: int, currency: string, bank_account_id?: ?int, invoice_date: string, due_date?: ?string, notes?: ?string, items: array<int, array{description: string, sac?: ?string, qty?: mixed, rate: mixed, taxable?: bool}>}  $data
     */
    public function saveDraft(array $data, ?Invoice $invoice = null): Invoice
    {
        if ($invoice && $invoice->status !== 'Draft') {
            throw ValidationException::withMessages(['items' => 'Only a draft can be changed.']);
        }
        $data = Validator::make($data, [
            'client_id' => 'required|exists:clients,id',
            'currency' => 'required|in:'.implode(',', Invoice::CURRENCIES),
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.sac' => 'nullable|string|max:10',
            'items.*.qty' => 'nullable|numeric|gt:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.taxable' => 'nullable|boolean',
        ], ['items.required' => 'Add at least one line.'])->validate();

        return DB::transaction(function () use ($data, $invoice): Invoice {
            $invoice ??= new Invoice(['doc_type' => 'proforma', 'status' => 'Draft', 'gst_rate' => Setting::get('gst_rate')]);
            $invoice->fill([
                'client_id' => $data['client_id'], 'currency' => $data['currency'], 'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? Carbon::parse($data['invoice_date'])->addDays((int) Setting::get('payment_days')),
                'bank_account_id' => $data['bank_account_id'] ?? BankAccount::where('currency', $data['currency'])->where('is_active', true)->orderByDesc('is_default')->value('id'),
                'notes' => $data['notes'] ?? null,
            ])->save();
            $invoice->items()->delete();
            foreach (array_values($data['items']) as $n => $line) {
                $qty = (string) ($line['qty'] ?? 1);
                $invoice->items()->create([
                    'description' => trim($line['description']), 'sac' => ($line['sac'] ?? '') ?: '998213', 'qty' => $qty, 'rate' => (string) $line['rate'],
                    'amount' => Money::mul($qty, (string) $line['rate']), 'taxable' => (bool) ($line['taxable'] ?? true), 'sort' => $n,
                ]);
            }
            $this->recalculate($invoice);

            return $invoice->fresh(['items']);
        });
    }

    /** Subtotal and GST: Indian client – CGST + SGST in our state, otherwise IGST; foreign client – export under LUT, no GST. */
    public function recalculate(Invoice $invoice): void
    {
        $invoice->load(['items', 'client']);
        $subtotal = '0.00';
        $taxable = '0.00';
        foreach ($invoice->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->amount, 2);
            if ($item->taxable) {
                $taxable = bcadd($taxable, (string) $item->amount, 2);
            }
        }
        $gst = $invoice->client->isIndian() ? Money::mul($taxable, bcdiv((string) $invoice->gst_rate, '100', 6)) : '0.00';
        $intra = $invoice->client->isIndian() && Gst::stateCode($invoice->client) === Gst::companyStateCode();
        $cgst = $intra ? Money::round(bcdiv($gst, '2', 4)) : '0.00';
        $sgst = $intra ? bcsub($gst, $cgst, 2) : '0.00';
        $invoice->forceFill(['subtotal' => $subtotal, 'igst' => $intra ? '0.00' : $gst, 'cgst' => $cgst, 'sgst' => $sgst, 'grand_total' => bcadd($subtotal, $gst, 2)])->save();
    }

    /** A draft gets its proforma number and can now be paid. */
    public function finalise(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'Draft' || ! $invoice->items()->exists()) {
                throw ValidationException::withMessages(['status' => 'Only a draft with lines can be finalised.']);
            }
            $invoice->update(['number' => $this->nextNumber(Setting::get('proforma_prefix'), $invoice->invoice_date), 'status' => 'Unpaid']);

            return $invoice;
        });
    }

    /**
     * Records money received against a proforma: the whole balance, or a part (for example 50% now, 50% later).
     *
     * @param  array{received_on: string, amount: mixed, tds?: mixed, reference: string, bank_account_id?: ?int, notes?: ?string}  $data
     */
    public function recordPayment(Invoice $invoice, array $data): Payment
    {
        $data = Validator::make($data, [
            'received_on' => 'required|date|before_or_equal:today',
            'amount' => 'required|numeric|min:0',
            'tds' => 'nullable|numeric|min:0',
            'reference' => 'required|string|max:255',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string|max:2000',
        ])->validate();

        return DB::transaction(function () use ($invoice, $data): Payment {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->isProforma() || ! in_array($invoice->status, ['Unpaid', 'Partially paid'], true)) {
                throw ValidationException::withMessages(['amount' => 'Payments are recorded on a finalised proforma that is not fully paid or cancelled.']);
            }
            if (Carbon::parse($data['received_on'])->lt($invoice->invoice_date)) {
                throw ValidationException::withMessages(['received_on' => 'The payment cannot be before the proforma date.']);
            }
            $received = Money::round(bcadd((string) $data['amount'], (string) ($data['tds'] ?? '0'), 2));
            if (bccomp($received, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
            }
            if (bccomp($received, $invoice->balance(), 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'More than the balance due ('.$invoice->balance().' '.$invoice->currency.').']);
            }
            $reference = mb_strtolower(preg_replace('/\s+/', '', $data['reference']));
            if ($invoice->payments->contains(fn (Payment $p) => mb_strtolower(preg_replace('/\s+/', '', $p->reference)) === $reference)) {
                throw ValidationException::withMessages(['reference' => 'This reference is already recorded on this proforma.']);
            }
            $payment = $invoice->payments()->create([
                'received_on' => $data['received_on'], 'amount' => Money::round((string) $data['amount']), 'tds' => Money::round((string) ($data['tds'] ?? '0')),
                'reference' => trim($data['reference']), 'bank_account_id' => $data['bank_account_id'] ?? $invoice->bank_account_id, 'notes' => $data['notes'] ?? null,
            ]);
            $this->refreshStatus($invoice);

            return $payment;
        });
    }

    /** Removes a wrongly entered payment; not once the tax invoice is made. */
    public function deletePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $invoice = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
            if ($invoice->taxInvoice()->exists()) {
                throw ValidationException::withMessages(['payment' => 'The tax invoice is already made from this proforma.']);
            }
            $payment->delete();
            $this->refreshStatus($invoice);
        });
    }

    /** Unpaid, Partially paid or Paid from what has been received. */
    public function refreshStatus(Invoice $invoice): void
    {
        $invoice->load('payments');
        $paid = $invoice->paidTotal();
        $status = bccomp($paid, '0', 2) <= 0 ? 'Unpaid' : (bccomp($paid, (string) $invoice->grand_total, 2) >= 0 ? 'Paid' : 'Partially paid');
        $invoice->update(['status' => $status]);
    }

    /** Cancels a proforma nothing has been paid on. Its number stays used; nothing is deleted. */
    public function cancel(Invoice $invoice, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason.']);
        }
        if (! $invoice->isProforma() || ! in_array($invoice->status, ['Draft', 'Unpaid'], true) || $invoice->payments()->exists()) {
            throw ValidationException::withMessages(['reason' => 'Only a proforma without payments can be cancelled.']);
        }
        $invoice->update(['status' => 'Cancelled', 'cancel_reason' => trim($reason)]);
    }

    /** The tax invoice for a fully paid proforma: same client, lines and totals, its own number. */
    public function convertToTax(Invoice $proforma, string $date): Invoice
    {
        return DB::transaction(function () use ($proforma, $date): Invoice {
            $proforma = Invoice::with(['items', 'payments'])->lockForUpdate()->findOrFail($proforma->id);
            if (! $proforma->isProforma() || $proforma->status !== 'Paid' || $proforma->taxInvoice()->exists()) {
                throw ValidationException::withMessages(['date' => 'A tax invoice is made once, from a fully paid proforma.']);
            }
            $on = Carbon::parse($date);
            $lastPayment = $proforma->payments->max('received_on');
            if ($on->isFuture() || ($lastPayment && $on->lt($lastPayment))) {
                throw ValidationException::withMessages(['date' => 'The tax invoice date must be between the last payment ('.$lastPayment?->format('d.m.Y').') and today.']);
            }
            $tax = $proforma->replicate(['number', 'status', 'due_date', 'cancel_reason', 'irn', 'irn_ack_no', 'irn_ack_date', 'irn_signed_qr']);
            $tax->fill(['doc_type' => 'tax', 'proforma_id' => $proforma->id, 'invoice_date' => $on->toDateString(), 'status' => 'Issued', 'number' => $this->nextNumber(Setting::get('tax_prefix'), $on)])->save();
            foreach ($proforma->items as $item) {
                $tax->items()->create($item->only(['description', 'sac', 'qty', 'rate', 'amount', 'taxable', 'sort']));
            }

            return $tax->fresh(['items']);
        });
    }

    /** PREFIX + two-digit financial year (April–March) + four-digit running number, e.g. PI260001. */
    public function nextNumber(string $prefix, mixed $date): string
    {
        $date = Carbon::parse($date);
        $year = (string) ($date->month < 4 ? $date->year - 1 : $date->year);
        $series = $prefix ?: 'INV';
        DB::table('number_sequences')->insertOrIgnore(['series' => $series, 'year' => $year, 'last_number' => 0]);
        $row = DB::table('number_sequences')->where('series', $series)->where('year', $year)->lockForUpdate()->first();
        DB::table('number_sequences')->where('id', $row->id)->update(['last_number' => $row->last_number + 1]);

        return $series.substr($year, -2).str_pad((string) ($row->last_number + 1), 4, '0', STR_PAD_LEFT);
    }

    /** "USD One Thousand Two Hundred and 50/100 Only"; rupees in lakh and crore. */
    public static function inWords(string $amount, string $currency): string
    {
        [$whole, $fraction] = explode('.', Money::round($amount));
        $spell = (new \NumberFormatter($currency === 'INR' ? 'en_IN' : 'en', \NumberFormatter::SPELLOUT))->format((int) $whole);
        $spell = ucwords(str_replace(['-', ','], [' ', ''], $spell));

        return ($currency === 'INR' ? 'Rupees' : $currency).' '.$spell.((int) $fraction ? ' and '.$fraction.'/100' : '').' Only';
    }
}
