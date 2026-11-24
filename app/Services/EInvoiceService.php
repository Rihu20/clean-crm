<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * e-Invoice for a tax invoice: the JSON in the GST e-invoice schema (INV-01, version 1.1) to upload on the
 * Invoice Registration Portal, and the IRN, acknowledgement and signed QR the portal returns.
 */
class EInvoiceService
{
    private const COUNTRY_CODES = ['united states' => 'US', 'usa' => 'US', 'united kingdom' => 'GB', 'uk' => 'GB', 'germany' => 'DE', 'france' => 'FR', 'japan' => 'JP', 'china' => 'CN', 'south korea' => 'KR', 'korea' => 'KR', 'taiwan' => 'TW', 'colombia' => 'CO', 'canada' => 'CA', 'australia' => 'AU', 'singapore' => 'SG', 'switzerland' => 'CH', 'netherlands' => 'NL', 'italy' => 'IT', 'spain' => 'ES', 'sweden' => 'SE', 'israel' => 'IL', 'united arab emirates' => 'AE', 'uae' => 'AE', 'brazil' => 'BR', 'ireland' => 'IE', 'belgium' => 'BE', 'denmark' => 'DK', 'finland' => 'FI', 'norway' => 'NO', 'austria' => 'AT', 'poland' => 'PL', 'hong kong' => 'HK', 'malaysia' => 'MY', 'thailand' => 'TH', 'vietnam' => 'VN', 'indonesia' => 'ID', 'philippines' => 'PH', 'new zealand' => 'NZ', 'south africa' => 'ZA', 'mexico' => 'MX'];

    /** @return array<string, mixed> */
    public function payload(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'client']);
        $client = $invoice->client;
        $export = ! $client->isIndian();
        $rate = $invoice->currency === 'INR' ? '1' : (string) ($invoice->inr_rate ?? '0');
        $sellerGstin = strtoupper(trim(Setting::get('company_gstin')));
        $sellerAddress = Setting::get('company_address');
        $sellerPin = self::pin($sellerAddress);
        $buyerPin = $export ? 999999 : self::pin((string) $client->address);

        $problems = [];
        if ($invoice->doc_type !== 'tax') {
            $problems[] = 'Only a tax invoice is registered.';
        }
        if (bccomp($rate, '0', 6) <= 0) {
            $problems[] = 'Enter the INR exchange rate first (the e-invoice is in rupees).';
        }
        if (! preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $sellerGstin)) {
            $problems[] = 'Company GSTIN in Company details is missing or not valid.';
        }
        if (! $sellerPin) {
            $problems[] = 'Add the 6-digit PIN code to the company address.';
        }
        if (! $export && blank($client->gstin)) {
            $problems[] = 'The client has no GSTIN; e-invoices are only for registered buyers and exports.';
        }
        if (! $buyerPin) {
            $problems[] = 'Add the 6-digit PIN code to the client address.';
        }
        if ($problems) {
            throw ValidationException::withMessages(['einvoice' => implode(' ', $problems)]);
        }

        $inr = fn (string $amount): float => (float) Money::mul($amount, $rate);
        $intra = ! $export && Gst::stateCode($client) === Gst::companyStateCode();
        $items = [];
        $sum = ['ass' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0];
        foreach ($invoice->items as $n => $item) {
            $amount = $inr((string) $item->amount);
            $gstRate = ! $export && $item->taxable ? (float) $invoice->gst_rate : 0.0;
            $gst = round($amount * $gstRate / 100, 2);
            $cgst = $intra ? round($gst / 2, 2) : 0.0;
            $sgst = $intra ? round($gst - $cgst, 2) : 0.0;
            $igst = $intra ? 0.0 : $gst;
            $items[] = ['SlNo' => (string) ($n + 1), 'PrdDesc' => mb_substr($item->description, 0, 300), 'IsServc' => 'Y', 'HsnCd' => (string) $item->sac,
                'Qty' => (float) $item->qty, 'Unit' => 'OTH', 'UnitPrice' => $inr((string) $item->rate), 'TotAmt' => $amount, 'Discount' => 0, 'AssAmt' => $amount,
                'GstRt' => $gstRate, 'IgstAmt' => $igst, 'CgstAmt' => $cgst, 'SgstAmt' => $sgst, 'TotItemVal' => round($amount + $gst, 2)];
            $sum = ['ass' => $sum['ass'] + $amount, 'igst' => $sum['igst'] + $igst, 'cgst' => $sum['cgst'] + $cgst, 'sgst' => $sum['sgst'] + $sgst];
        }
        $total = $inr((string) $invoice->grand_total);

        $payload = [
            'Version' => '1.1',
            'TranDtls' => ['TaxSch' => 'GST', 'SupTyp' => $export ? 'EXPWOP' : 'B2B', 'RegRev' => 'N', 'IgstOnIntra' => 'N'],
            'DocDtls' => ['Typ' => 'INV', 'No' => $invoice->number, 'Dt' => $invoice->invoice_date->format('d/m/Y')],
            'SellerDtls' => ['Gstin' => $sellerGstin, 'LglNm' => Setting::get('company_name'), 'Addr1' => mb_substr($sellerAddress, 0, 100), 'Loc' => mb_substr($sellerAddress, 0, 50), 'Pin' => $sellerPin, 'Stcd' => substr($sellerGstin, 0, 2), 'Em' => Setting::get('company_email')],
            'BuyerDtls' => $export
                ? ['Gstin' => 'URP', 'LglNm' => $client->name, 'Pos' => '96', 'Addr1' => mb_substr((string) ($client->address ?: $client->country), 0, 100), 'Loc' => mb_substr((string) ($client->country ?: 'Outside India'), 0, 50), 'Pin' => 999999, 'Stcd' => '96']
                : ['Gstin' => strtoupper((string) $client->gstin), 'LglNm' => $client->name, 'Pos' => Gst::stateCode($client), 'Addr1' => mb_substr((string) $client->address, 0, 100), 'Loc' => mb_substr((string) ($client->state ?: 'India'), 0, 50), 'Pin' => $buyerPin, 'Stcd' => Gst::stateCode($client)],
            'ItemList' => $items,
            'ValDtls' => ['AssVal' => round($sum['ass'], 2), 'CgstVal' => round($sum['cgst'], 2), 'SgstVal' => round($sum['sgst'], 2), 'IgstVal' => round($sum['igst'], 2), 'RndOffAmt' => round($total - $sum['ass'] - $sum['igst'] - $sum['cgst'] - $sum['sgst'], 2), 'TotInvVal' => $total]
                + ($export ? ['TotInvValFc' => (float) $invoice->grand_total] : []),
        ];
        if ($export) {
            $country = self::COUNTRY_CODES[mb_strtolower(trim((string) $client->country))] ?? null;
            $payload['ExpDtls'] = ['ForCur' => $invoice->currency] + ($country ? ['CntCode' => $country] : []);
        }

        return $payload;
    }

    /** The file for the portal's bulk upload: a list with this one invoice. */
    public function json(Invoice $invoice): string
    {
        return json_encode([$this->payload($invoice)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Keeps what the portal returned: its response JSON pasted as it is, or the IRN, acknowledgement and signed QR typed in.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(Invoice $invoice, array $data): void
    {
        if ($invoice->doc_type !== 'tax') {
            throw ValidationException::withMessages(['irn' => 'Only a tax invoice has an IRN.']);
        }
        $response = trim((string) ($data['response'] ?? ''));
        if ($response !== '') {
            $decoded = json_decode($response, true);
            $decoded = is_array($decoded) && array_is_list($decoded) ? ($decoded[0] ?? null) : $decoded;
            $decoded = is_array($decoded) && is_array($decoded['Data'] ?? null) ? $decoded['Data'] : $decoded;
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['response' => 'This is not the JSON the portal returned.']);
            }
            $data = ['irn' => $decoded['Irn'] ?? null, 'ack_no' => $decoded['AckNo'] ?? null, 'ack_date' => $decoded['AckDt'] ?? null, 'signed_qr' => $decoded['SignedQRCode'] ?? null];
        }
        $irn = strtolower(trim((string) ($data['irn'] ?? '')));
        if (! preg_match('/^[0-9a-f]{64}$/', $irn)) {
            throw ValidationException::withMessages(['irn' => 'The IRN is the 64-character code the portal returns.']);
        }
        if (Invoice::where('irn', $irn)->whereKeyNot($invoice->getKey())->exists()) {
            throw ValidationException::withMessages(['irn' => 'This IRN is already on another invoice.']);
        }
        if (blank($data['ack_no'] ?? null) || blank($data['ack_date'] ?? null)) {
            throw ValidationException::withMessages(['ack_no' => 'Enter the acknowledgement number and date.']);
        }
        $invoice->update(['irn' => $irn, 'irn_ack_no' => trim((string) $data['ack_no']), 'irn_ack_date' => Carbon::parse((string) $data['ack_date']), 'irn_signed_qr' => filled($data['signed_qr'] ?? null) ? trim((string) $data['signed_qr']) : null]);
    }

    /** The signed QR (or, without it, the IRN) as a PNG data URI. */
    public function qrImage(Invoice $invoice): ?string
    {
        $text = $invoice->irn_signed_qr ?: $invoice->irn;

        return $text ? (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'scale' => 3, 'outputBase64' => true, 'quietzoneSize' => 1])))->render($text) : null;
    }

    private static function pin(string $address): ?int
    {
        return preg_match_all('/(?<!\d)(\d{6})(?!\d)/', $address, $m) ? (int) end($m[1]) : null;
    }
}
