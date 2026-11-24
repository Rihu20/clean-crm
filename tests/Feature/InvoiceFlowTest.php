<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\ManageInvoices;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\EInvoiceService;
use App\Services\InvoiceService;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InvoiceService::class);
        Setting::put(['company_name' => 'Test Co', 'company_gstin' => '07AAACT1234A1Z5', 'company_address' => 'Plot 1, New Delhi 110001', 'company_email' => 'a@test.test']);
        BankAccount::create(['bank_name' => 'Test Bank', 'currency' => 'USD', 'account_no' => '123', 'beneficiary_name' => 'Test Co', 'is_default' => true, 'is_active' => true]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create($attributes + ['ref_no' => 'C-'.uniqid(), 'name' => 'Acme Inc', 'client_type' => 'Foreign', 'country' => 'United States', 'currency' => 'USD', 'address' => '1 Main St, Boston']);
    }

    private function proforma(Client $client, string $rate = '1000'): Invoice
    {
        $draft = $this->service->saveDraft(['client_id' => $client->id, 'currency' => $client->currency, 'invoice_date' => now()->toDateString(), 'items' => [['description' => 'Patent filing', 'rate' => $rate]]]);

        return $this->service->finalise($draft);
    }

    private function pay(Invoice $invoice, string $amount, string $reference): void
    {
        $this->service->recordPayment($invoice, ['received_on' => now()->toDateString(), 'amount' => $amount, 'reference' => $reference]);
    }

    public function test_proforma_paid_in_two_halves_becomes_a_tax_invoice(): void
    {
        $proforma = $this->proforma($this->client());
        $this->assertSame('PI260001', $proforma->number);
        $this->assertSame('Unpaid', $proforma->status);
        $this->assertNotNull($proforma->bank_account_id);

        $this->pay($proforma, '500', 'UTR1');
        $this->assertSame('Partially paid', $proforma->fresh()->status);

        try {
            $this->pay($proforma, '600', 'UTR2');
            $this->fail('Paying more than the balance must be refused.');
        } catch (ValidationException) {
        }
        $this->expectsDuplicateRefused($proforma);

        $this->pay($proforma, '500', 'UTR2');
        $this->assertSame('Paid', $proforma->fresh()->status);

        $tax = $this->service->convertToTax($proforma, now()->toDateString());
        $this->assertSame('INV260001', $tax->number);
        $this->assertSame('Issued', $tax->status);
        $this->assertSame('1000.00', (string) $tax->grand_total);
        $this->assertSame('1000.00', $tax->paidTotal());

        $this->expectException(ValidationException::class);
        $this->service->deletePayment($proforma->payments()->first());
    }

    private function expectsDuplicateRefused(Invoice $proforma): void
    {
        try {
            $this->pay($proforma, '100', 'utr 1');
            $this->fail('A reference already recorded must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }
    }

    public function test_tax_invoice_needs_full_payment(): void
    {
        $proforma = $this->proforma($this->client());
        $this->pay($proforma, '400', 'UTR1');

        $this->expectException(ValidationException::class);
        $this->service->convertToTax($proforma, now()->toDateString());
    }

    public function test_only_an_unpaid_proforma_can_be_cancelled(): void
    {
        $unpaid = $this->proforma($this->client());
        $this->service->cancel($unpaid, 'Client withdrew');
        $this->assertSame('Cancelled', $unpaid->fresh()->status);

        $part = $this->proforma($this->client());
        $this->pay($part, '100', 'UTR9');
        $this->expectException(ValidationException::class);
        $this->service->cancel($part, 'Too late');
    }

    public function test_gst_is_split_within_our_state_and_igst_outside(): void
    {
        $delhi = $this->proforma($this->client(['client_type' => 'Indian', 'currency' => 'INR', 'state' => 'Delhi', 'gstin' => '07AAACB1234B1Z5']));
        $this->assertSame(['90.00', '90.00', '0.00', '1180.00'], [(string) $delhi->cgst, (string) $delhi->sgst, (string) $delhi->igst, (string) $delhi->grand_total]);

        $mumbai = $this->proforma($this->client(['client_type' => 'Indian', 'currency' => 'INR', 'state' => 'Maharashtra', 'gstin' => '27AAACB1234B1Z5']));
        $this->assertSame(['0.00', '180.00'], [(string) $mumbai->cgst, (string) $mumbai->igst]);

        $export = $this->proforma($this->client());
        $this->assertSame('1000.00', (string) $export->grand_total);
    }

    public function test_e_invoice_for_an_export_and_the_irn_on_the_pdf(): void
    {
        $proforma = $this->proforma($this->client());
        $this->pay($proforma, '1000', 'UTR1');
        $tax = $this->service->convertToTax($proforma, now()->toDateString());
        $einvoice = app(EInvoiceService::class);

        try {
            $einvoice->payload($tax);
            $this->fail('The INR rate must be asked for first.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('exchange rate', $e->getMessage().implode(' ', $e->errors()['einvoice']));
        }

        $tax->update(['inr_rate' => '83.5']);
        $payload = $einvoice->payload($tax->fresh());
        $this->assertSame('EXPWOP', $payload['TranDtls']['SupTyp']);
        $this->assertSame(83500.0, $payload['ValDtls']['TotInvVal']);
        $this->assertSame(110001, $payload['SellerDtls']['Pin']);
        $this->assertSame(['ForCur' => 'USD', 'CntCode' => 'US'], $payload['ExpDtls']);

        $irn = str_repeat('ab', 32);
        $einvoice->record($tax, ['response' => json_encode(['Data' => ['Irn' => $irn, 'AckNo' => '112410000000001', 'AckDt' => '2026-11-23 10:00:00', 'SignedQRCode' => 'signed.qr.text']])]);
        $this->assertSame($irn, $tax->fresh()->irn);

        $pdf = app(PdfService::class)->render($tax->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_the_invoice_screen_buttons_run_the_whole_flow(): void
    {
        $this->actingAs(User::factory()->create());
        $client = $this->client();
        $page = Livewire::test(ManageInvoices::class);

        $page->callAction('create', ['client_id' => $client->id, 'currency' => 'USD', 'invoice_date' => now()->toDateString(), 'items' => [['description' => 'Search report', 'sac' => '998213', 'qty' => 2, 'rate' => 300, 'taxable' => true]]])
            ->assertHasNoActionErrors();
        $invoice = Invoice::sole();
        $this->assertSame('600.00', (string) $invoice->grand_total);

        $page->callTableAction('finalise', $invoice)
            ->callTableAction('payment', $invoice, ['received_on' => now()->toDateString(), 'amount' => 300, 'tds' => 0, 'reference' => 'UTR-A'])
            ->assertTableActionHidden('cancel', $invoice);
        $this->assertSame('Partially paid', $invoice->fresh()->status);

        $page->callTableAction('payment', $invoice, ['received_on' => now()->toDateString(), 'amount' => 300, 'tds' => 0, 'reference' => 'UTR-B'])
            ->callTableAction('convert', $invoice, ['date' => now()->toDateString()]);
        $tax = $invoice->fresh()->taxInvoice;
        $this->assertSame('Issued', $tax->status);

        $page->callTableAction('inr_rate', $tax, ['inr_rate' => 84])
            ->callTableAction('einvoice_json', $tax)->assertFileDownloaded('einvoice-'.$tax->number.'.json')
            ->callTableAction('pdf', $tax)->assertFileDownloaded($tax->number.'.pdf');
    }

    public function test_screens_open(): void
    {
        $this->actingAs(User::factory()->create());
        $this->proforma($this->client());

        foreach (['/admin/clients', '/admin/invoices', '/admin/bank-accounts', '/admin/company-details'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
