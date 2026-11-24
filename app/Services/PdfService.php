<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfService
{
    public function render(Invoice $invoice): string
    {
        $invoice->load(['items', 'client', 'bankAccount', 'proforma', 'payments']);

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'settings' => Setting::allValues(),
            'logo' => Setting::get('logo') ?: null,
            'signature' => Setting::get('signature') ?: null,
            'qr' => $invoice->irn ? app(EInvoiceService::class)->qrImage($invoice) : null,
        ])->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])->output();
    }
}
