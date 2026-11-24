@php
    use App\Services\Gst;
    use App\Services\InvoiceService;
    use App\Services\Money;
    $client = $invoice->client;
    $cur = $invoice->currency;
    $n = fn ($v) => Money::number((string) $v, $cur);
    $isTax = $invoice->doc_type === 'tax';
    $paid = $isTax || $invoice->status === 'Paid';
    $received = $invoice->paidTotal();
    $bank = $invoice->bankAccount;
@endphp
<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:0}
body{font-family:DejaVu Sans,sans-serif;font-size:9px;line-height:1.4;color:#1c1b1b;margin:0}
table{width:100%;border-collapse:collapse}td,th{vertical-align:top;padding:0}
.band{height:7px;background:#c62828}
.lbl{display:block;font-size:7.5px;letter-spacing:1px;text-transform:uppercase;color:#8a8080}
.h{font-size:7.5px;letter-spacing:1px;text-transform:uppercase;color:#d32f2f;font-weight:bold;margin-bottom:6px}
.kind{font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#d32f2f;font-weight:bold}
.no{font-size:22px;font-weight:bold;margin:2px 0 8px}
.meta td{padding:0 0 6px 14px}
.parties td{border-top:1px solid #eee6e5;border-bottom:1px solid #eee6e5;padding:12px 0}
.name{font-size:12px;font-weight:bold;margin:1px 0 2px}
.items{table-layout:fixed;margin-top:10px}
.items th{white-space:nowrap;font-size:7.5px;letter-spacing:.8px;text-transform:uppercase;color:#6f6767;text-align:left;padding:8px 6px 8px 0;border-bottom:2px solid #1c1b1b}
.items td{padding:7px 6px 7px 0;border-bottom:1px solid #f1eaea;overflow-wrap:break-word}
.items .last{padding-right:0}.items th.r{text-align:right}.items th.c{text-align:center}.r{text-align:right}.c{text-align:center}
.totals td{padding:4px 0;border-bottom:1px solid #f1eaea}.totals .v{text-align:right;font-weight:bold}
.grand{background:#1c1b1b;border-radius:5px}.grand td{color:#fff;padding:7px 9px;font-size:11px;font-weight:bold;border:0}
.pay{margin:14px 30px 0;border:1px solid #f3c7c7;background:#fff7f7;border-radius:8px;padding:10px 12px}
.br td{padding:3px 0;border-bottom:1px dashed #f0d9d9}.br .k{width:150px;font-size:7.5px;letter-spacing:.8px;text-transform:uppercase;color:#8a8080;padding-top:5px}
.hi{font-family:DejaVu Sans Mono,monospace;font-size:10px;background:#fff;border:1px solid #f3c7c7;border-radius:3px;padding:1px 6px}
.einv{border:1px solid #eee6e5;border-radius:8px}.einv td{padding:8px 10px;vertical-align:middle}
.stamp{position:absolute;left:300px;top:96px;border:2px solid;font-weight:bold;font-size:18px;letter-spacing:4px;padding:3px 12px;transform:rotate(-12deg);opacity:.55;border-radius:5px}
.gen{margin:14px 30px 0;padding-top:8px;border-top:1px solid #eee6e5;font-size:7.5px;color:#8a8080;text-align:center}
</style></head><body>
<div class="band"></div>
@if($paid)<div class="stamp" style="color:#2e7d32;border-color:#2e7d32">PAID</div>@elseif($invoice->status === 'Cancelled')<div class="stamp" style="color:#c62828;border-color:#c62828">CANCELLED</div>@endif
<table><tr>
    <td style="width:56%;padding:22px 0 14px 30px">@if($logo)<img src="{{ $logo }}" style="max-height:58px;max-width:200px"><br>@endif
        <div style="font-size:8.5px;line-height:1.55"><b style="font-size:10px">{{ $settings['company_name'] }}</b><br>{{ $settings['company_address'] }}@if($settings['company_gstin'])<br>GSTIN {{ $settings['company_gstin'] }}@endif<br>{{ collect([$settings['company_email'], $settings['company_phone']])->filter()->implode(' · ') }}</div></td>
    <td style="text-align:right;padding:22px 30px 14px 0"><div class="kind">{{ $isTax ? 'Tax Invoice' : 'Proforma Invoice' }}</div><div class="no">{{ $invoice->number ?? 'DRAFT' }}</div>
        <table class="meta"><tr><td><span class="lbl">Invoice date</span><b>{{ $invoice->invoice_date->format('F d, Y') }}</b></td><td><span class="lbl">{{ $isTax ? 'Against proforma' : 'Due date' }}</span><b>{{ $isTax ? $invoice->proforma?->number : $invoice->due_date?->format('F d, Y') }}</b></td></tr><tr><td><span class="lbl">Client ref</span><b>{{ $client->ref_no }}</b></td><td><span class="lbl">Currency</span><b>{{ $cur }}</b></td></tr></table></td>
</tr></table>
<div style="padding:0 30px"><table class="parties"><tr><td><span class="lbl">Invoice to</span><div class="name">{{ $client->name }}</div>@if($client->contact_person)Attn. {{ $client->contact_person }}<br>@endif{{ $client->address }}@if($client->email || $client->phone)<br>{{ collect([$client->email, $client->phone])->filter()->implode(' · ') }}@endif @if($client->isIndian())<br>GSTIN: {{ $client->gstin ?: 'Unregistered' }}@endif<br>Place of Supply: {{ Gst::placeOfSupply($client) }}</td></tr></table></div>
<div style="padding:0 30px"><table class="items">
<thead><tr><th style="width:5%">#</th><th style="width:48%">Description of service</th><th class="c" style="width:11%">SAC</th><th class="r" style="width:14%">Rate</th><th class="c" style="width:7%">Qty</th><th class="r last" style="width:15%">Amount ({{ $cur }})</th></tr></thead><tbody>
@foreach($invoice->items as $i => $item)<tr><td style="color:#8a8080">{{ $i + 1 }}</td><td>{{ $item->description }}</td><td class="c">{{ $item->sac }}</td><td class="r">{{ $n($item->rate) }}</td><td class="c">{{ rtrim(rtrim((string) $item->qty, '0'), '.') }}</td><td class="r last">{{ $n($item->amount) }}</td></tr>@endforeach
</tbody></table></div>
<table><tr>
    <td style="padding:12px 24px 0 30px"><span class="lbl">Amount in words</span><b>{{ InvoiceService::inWords((string) $invoice->grand_total, $cur) }}</b>
        @if(! $client->isIndian() && $isTax)<div style="margin-top:8px;border-left:2px solid #e53935;padding-left:6px">Supply meant for export of services under LUT{{ $settings['lut_arn'] ? ' (ARN '.$settings['lut_arn'].')' : '' }} without payment of IGST.</div>@endif</td>
    <td style="width:44%;padding:12px 30px 0 0"><table class="totals">
        <tr><td>Subtotal</td><td class="v">{{ $n($invoice->subtotal) }}</td></tr>
        @if(! $client->isIndian())<tr><td>IGST 0% (export under LUT)</td><td class="v">{{ $n(0) }}</td></tr>
        @elseif(bccomp((string) $invoice->cgst, '0', 2) > 0)<tr><td>CGST {{ rtrim(rtrim(bcdiv((string) $invoice->gst_rate, '2', 2), '0'), '.') }}%</td><td class="v">{{ $n($invoice->cgst) }}</td></tr><tr><td>SGST {{ rtrim(rtrim(bcdiv((string) $invoice->gst_rate, '2', 2), '0'), '.') }}%</td><td class="v">{{ $n($invoice->sgst) }}</td></tr>
        @else<tr><td>IGST {{ rtrim(rtrim((string) $invoice->gst_rate, '0'), '.') }}%</td><td class="v">{{ $n($invoice->igst) }}</td></tr>@endif
        <tr><td colspan="2" style="border:0;padding:6px 0 4px"><table class="grand"><tr><td>Total payable</td><td style="text-align:right">{{ $n($invoice->grand_total) }} {{ $cur }}</td></tr></table></td></tr>
        @if(! $isTax && bccomp($received, '0', 2) > 0)<tr><td>Received</td><td class="v">{{ $n($received) }}</td></tr><tr><td>Balance due</td><td class="v" style="color:#c62828">{{ $n($invoice->balance()) }}</td></tr>@endif
    </table></td>
</tr></table>
@if($bank)<div class="pay"><div class="h">Pay through bank / wire transfer</div><table class="br">
    @foreach([['Beneficiary name', $bank->beneficiary_name, false], ['Beneficiary address', $bank->beneficiary_address, false], ['Bank name', $bank->bank_name, false], ['Bank address', $bank->bank_address, false], ['Account / IBAN no.', $bank->account_no, true], ['SWIFT / BIC code', $bank->swift, true], ['IFSC code', $bank->ifsc, true], ['Currency', $bank->currency, false]] as [$label, $value, $hi])
        @if(filled($value))<tr><td class="k">{{ $label }}</td><td style="font-weight:bold">@if($hi)<span class="hi">{{ $value }}</span>@else{{ $value }}@endif</td></tr>@endif
    @endforeach
</table></div>@endif
@if($invoice->irn)<div style="padding:12px 30px 0"><table class="einv"><tr>@if($qr)<td style="width:96px"><img src="{{ $qr }}" style="width:88px;height:88px"></td>@endif
    <td><div class="h">e-Invoice (GST IRP)</div><span class="lbl">IRN</span><div style="font-family:DejaVu Sans Mono,monospace;font-size:8px">{{ $invoice->irn }}</div>
        <table style="margin-top:6px"><tr><td><span class="lbl">Ack No.</span><b>{{ $invoice->irn_ack_no }}</b></td><td><span class="lbl">Ack Date</span><b>{{ $invoice->irn_ack_date?->format('d.m.Y H:i') }}</b></td></tr></table></td></tr></table></div>@endif
<table><tr>
    <td style="padding:16px 24px 0 30px;vertical-align:bottom;font-size:8px;color:#555">@if($settings['terms'])<div class="h">Terms &amp; conditions</div>{!! nl2br(e($settings['terms'])) !!}@endif</td>
    <td style="width:34%;padding:16px 30px 0 0;text-align:center;vertical-align:bottom">@if($signature)<img src="{{ $signature }}" style="max-height:52px;max-width:150px">@else<div style="height:40px"></div>@endif
        <div style="border-top:1px solid #1c1b1b;margin-top:3px;padding-top:3px;font-weight:bold">For {{ $settings['signatory'] ?: $settings['company_name'] }}<br>Authorized Signatory</div></td>
</tr></table>
<div class="gen">This is a computer generated invoice · Original copy for recipient</div>
</body></html>
