<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A proforma (Draft → Unpaid → Partially paid → Paid, or Cancelled) or the tax invoice made from a paid proforma (Issued).
 */
class Invoice extends Model
{
    public const CURRENCIES = ['INR' => 'INR', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'JPY' => 'JPY'];

    public const STATUS_COLORS = ['Draft' => 'gray', 'Unpaid' => 'warning', 'Partially paid' => 'info', 'Paid' => 'success', 'Cancelled' => 'danger', 'Issued' => 'success'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date', 'due_date' => 'date', 'irn_ack_date' => 'datetime',
            'subtotal' => 'decimal:2', 'igst' => 'decimal:2', 'cgst' => 'decimal:2', 'sgst' => 'decimal:2', 'grand_total' => 'decimal:2',
            'gst_rate' => 'decimal:2', 'inr_rate' => 'decimal:6',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_on');
    }

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proforma_id');
    }

    public function taxInvoice(): HasOne
    {
        return $this->hasOne(self::class, 'proforma_id');
    }

    public function isProforma(): bool
    {
        return $this->doc_type === 'proforma';
    }

    /** Amount and TDS received; a tax invoice reads them from its proforma. */
    public function paidTotal(): string
    {
        $payments = $this->isProforma() ? $this->payments : ($this->proforma?->payments ?? collect());

        return $payments->reduce(fn (string $sum, Payment $p) => bcadd(bcadd($sum, (string) $p->amount, 2), (string) $p->tds, 2), '0.00');
    }

    public function balance(): string
    {
        return bcsub((string) $this->grand_total, $this->paidTotal(), 2);
    }
}
