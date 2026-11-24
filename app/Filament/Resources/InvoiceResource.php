<?php

namespace App\Filament\Resources;

use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\EInvoiceService;
use App\Services\InvoiceService;
use App\Services\Money;
use App\Services\PdfService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Proforma invoices, their payments (full, part, several), cancellation, the tax invoice and its e-invoice. */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['client', 'payments', 'taxInvoice', 'proforma.payments']);
    }

    /** The proforma form: client, currency, bank, dates and lines. */
    public static function proformaForm(): array
    {
        return [Grid::make(2)->schema([
            Select::make('client_id')->label('Client')->required()->searchable()->live()
                ->options(fn () => Client::orderBy('name')->get()->mapWithKeys(fn (Client $c) => [$c->id => $c->ref_no.' – '.$c->name]))
                ->afterStateUpdated(fn ($state, Set $set) => $set('currency', Client::find($state)?->currency ?? 'USD')),
            Select::make('currency')->options(Invoice::CURRENCIES)->default('USD')->required()->live(),
            Select::make('bank_account_id')->label('Our bank account')->placeholder('Default account for this currency')
                ->options(fn (Get $get) => BankAccount::where('is_active', true)->where('currency', $get('currency'))->get()->mapWithKeys(fn (BankAccount $b) => [$b->id => $b->bank_name.' · '.$b->account_no])),
            DatePicker::make('invoice_date')->default(now())->required()->maxDate(now()),
            DatePicker::make('due_date')->placeholder('From the payment days in Company details'),
            Repeater::make('items')->label('Lines')->required()->minItems(1)->defaultItems(1)->columnSpanFull()->columns(12)->schema([
                Textarea::make('description')->required()->maxLength(500)->rows(2)->columnSpan(5),
                TextInput::make('sac')->label('SAC')->default('998213')->maxLength(10)->columnSpan(2),
                TextInput::make('qty')->numeric()->default(1)->gt(0)->required()->columnSpan(1),
                TextInput::make('rate')->numeric()->minValue(0)->required()->columnSpan(2),
                Toggle::make('taxable')->label('GST')->default(true)->inline(false)->columnSpan(2),
            ]),
            Textarea::make('notes')->maxLength(2000)->columnSpanFull(),
        ])];
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state, Invoice $record) => Money::format((string) $state, $record->currency);

        return $table
            ->columns([
                TextColumn::make('number')->placeholder('Draft')->searchable()->sortable()->weight('bold')
                    ->description(fn (Invoice $record) => $record->isProforma() ? 'Proforma' : 'Tax invoice · from '.$record->proforma?->number),
                TextColumn::make('client.name')->label('Client')->limit(30)->description(fn (Invoice $record) => 'Ref '.$record->client->ref_no)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('ref_no', 'like', "%{$search}%"))),
                TextColumn::make('invoice_date')->date('d.m.Y')->sortable(),
                TextColumn::make('grand_total')->label('Total')->formatStateUsing($money)->alignEnd(),
                TextColumn::make('received')->state(fn (Invoice $record) => $record->paidTotal())->formatStateUsing($money)->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balance')->state(fn (Invoice $record) => $record->isProforma() && $record->status !== 'Cancelled' ? $record->balance() : null)
                    ->formatStateUsing($money)->placeholder('—')->alignEnd()->color(fn ($state) => $state && bccomp((string) $state, '0', 2) > 0 ? 'danger' : null)
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'tax'),
                TextColumn::make('status')->badge()->color(fn (string $state) => Invoice::STATUS_COLORS[$state] ?? 'gray')
                    ->description(fn (Invoice $record) => $record->status === 'Cancelled' ? $record->cancel_reason : ($record->taxInvoice ? 'Tax: '.$record->taxInvoice->number : null)),
                TextColumn::make('irn')->label('e-Invoice')->state(fn (Invoice $record) => $record->isProforma() ? null : ($record->irn ? 'IRN '.$record->irn_ack_no : 'Pending'))
                    ->badge()->color(fn ($state) => $state === 'Pending' ? 'warning' : 'success')->placeholder('—')
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'proforma'),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(array_keys(Invoice::STATUS_COLORS), array_keys(Invoice::STATUS_COLORS))),
                SelectFilter::make('client_id')->label('Client')->relationship('client', 'name')->searchable(),
            ])
            ->recordActions([ActionGroup::make([
                self::editAction(), self::finaliseAction(), self::paymentAction(), self::paymentsAction(), self::cancelAction(), self::convertAction(),
                self::pdfAction(), self::inrRateAction(), self::eInvoiceJsonAction(), self::recordIrnAction(),
            ])])
            ->defaultSort('id', 'desc');
    }

    private static function editAction(): Action
    {
        return Action::make('edit')->icon('heroicon-o-pencil-square')->modalWidth('5xl')->visible(fn (Invoice $record) => $record->status === 'Draft')
            ->fillForm(fn (Invoice $record) => $record->only(['client_id', 'currency', 'bank_account_id', 'invoice_date', 'due_date', 'notes'])
                + ['items' => $record->items()->get()->map(fn ($i) => $i->only(['description', 'sac', 'qty', 'rate', 'taxable']))->all()])
            ->schema(self::proformaForm())
            ->action(fn (Invoice $record, array $data, Action $action) => self::attempt($action, fn () => app(InvoiceService::class)->saveDraft($data, $record)));
    }

    private static function finaliseAction(): Action
    {
        return Action::make('finalise')->label('Finalise proforma')->icon('heroicon-o-check-badge')->color('success')->requiresConfirmation()
            ->modalDescription('The proforma gets its number and can no longer be edited.')
            ->visible(fn (Invoice $record) => $record->status === 'Draft')
            ->action(function (Invoice $record, Action $action) {
                $invoice = self::attempt($action, fn () => app(InvoiceService::class)->finalise($record));
                Notification::make()->title('Proforma '.$invoice->number.' finalised')->success()->send();
            });
    }

    private static function paymentAction(): Action
    {
        return Action::make('payment')->label('Record payment')->icon('heroicon-o-banknotes')->color('success')
            ->visible(fn (Invoice $record) => $record->isProforma() && in_array($record->status, ['Unpaid', 'Partially paid'], true))
            ->modalDescription(fn (Invoice $record) => 'Total '.Money::format((string) $record->grand_total, $record->currency).' · received '.Money::format($record->paidTotal(), $record->currency).' · balance '.Money::format($record->balance(), $record->currency).'. Enter the full balance, or a part (e.g. 50% now, 50% later).')
            ->fillForm(fn (Invoice $record) => ['received_on' => now()->toDateString(), 'amount' => $record->balance(), 'tds' => 0, 'bank_account_id' => $record->bank_account_id])
            ->schema([
                DatePicker::make('received_on')->required()->maxDate(now()),
                TextInput::make('amount')->label(fn (Invoice $record) => 'Amount received ('.$record->currency.')')->numeric()->minValue(0)->required()
                    ->hint(fn (Invoice $record) => '50% = '.Money::number(Money::round(bcdiv($record->balance(), '2', 4)), $record->currency)),
                TextInput::make('tds')->label('TDS deducted by client')->numeric()->minValue(0)->default(0),
                TextInput::make('reference')->label('Bank reference / UTR')->required()->maxLength(255),
                Select::make('bank_account_id')->label('Received in')->options(fn () => BankAccount::where('is_active', true)->get()->mapWithKeys(fn (BankAccount $b) => [$b->id => $b->bank_name.' · '.$b->currency.' · '.$b->account_no])),
                Textarea::make('notes'),
            ])
            ->action(function (Invoice $record, array $data, Action $action) {
                self::attempt($action, fn () => app(InvoiceService::class)->recordPayment($record, $data));
                $status = $record->fresh()->status;
                Notification::make()->title($status === 'Paid' ? 'Fully paid – the tax invoice can be made now' : 'Part payment recorded · '.$status)->success()->send();
            });
    }

    private static function paymentsAction(): Action
    {
        $describe = fn (Payment $p, string $currency) => $p->received_on->format('d.m.Y').' · '.Money::format((string) $p->amount, $currency)
            .(bccomp((string) $p->tds, '0', 2) > 0 ? ' + TDS '.Money::number((string) $p->tds, $currency) : '').' · '.$p->reference;

        return Action::make('payments')->label('Payments')->icon('heroicon-o-list-bullet')->color('gray')->modalSubmitActionLabel('Delete selected payment')
            ->modalSubmitAction(fn (Invoice $record) => $record->taxInvoice ? false : null)
            ->visible(fn (Invoice $record) => $record->isProforma() && $record->payments->isNotEmpty())
            ->schema(fn (Invoice $record) => [
                TextEntry::make('list')->label('Received')->state($record->payments->map(fn (Payment $p) => $describe($p, $record->currency))->all())->listWithLineBreaks()->bulleted(),
                Select::make('payment_id')->label('Delete a wrong entry')->placeholder('Keep all')->visible(! $record->taxInvoice)
                    ->options($record->payments->mapWithKeys(fn (Payment $p) => [$p->id => $describe($p, $record->currency)])),
            ])
            ->action(function (Invoice $record, array $data, Action $action) {
                if ($payment = $record->payments->firstWhere('id', (int) ($data['payment_id'] ?? 0))) {
                    self::attempt($action, fn () => app(InvoiceService::class)->deletePayment($payment));
                    Notification::make()->title('Payment deleted')->success()->send();
                }
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')->label('Cancel proforma')->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
            ->visible(fn (Invoice $record) => $record->isProforma() && in_array($record->status, ['Draft', 'Unpaid'], true) && $record->payments->isEmpty())
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(function (Invoice $record, array $data, Action $action) {
                self::attempt($action, fn () => app(InvoiceService::class)->cancel($record, $data['reason']));
                Notification::make()->title('Proforma cancelled')->success()->send();
            });
    }

    private static function convertAction(): Action
    {
        return Action::make('convert')->label('Make tax invoice')->icon('heroicon-o-document-check')->color('primary')
            ->visible(fn (Invoice $record) => $record->isProforma() && $record->status === 'Paid' && ! $record->taxInvoice)
            ->modalDescription('Same client, lines and totals as the proforma, with its own tax invoice number.')
            ->schema([DatePicker::make('date')->label('Tax invoice date')->default(now())->required()->maxDate(now())])
            ->action(function (Invoice $record, array $data, Action $action) {
                $tax = self::attempt($action, fn () => app(InvoiceService::class)->convertToTax($record, $data['date']));
                Notification::make()->title('Tax invoice '.$tax->number.' made')->success()->send();
            });
    }

    private static function pdfAction(): Action
    {
        return Action::make('pdf')->label('Download PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->action(fn (Invoice $record) => response()->streamDownload(fn () => print (app(PdfService::class)->render($record)), ($record->number ?: 'draft-'.$record->id).'.pdf', ['Content-Type' => 'application/pdf']));
    }

    private static function inrRateAction(): Action
    {
        return Action::make('inr_rate')->label('INR exchange rate')->icon('heroicon-o-currency-rupee')->color('gray')
            ->visible(fn (Invoice $record) => ! $record->isProforma() && $record->currency !== 'INR' && ! $record->irn)
            ->fillForm(fn (Invoice $record) => ['inr_rate' => $record->inr_rate])
            ->schema([TextInput::make('inr_rate')->label(fn (Invoice $record) => '1 '.$record->currency.' = ? INR (on the invoice date)')->numeric()->gt(0)->required()])
            ->action(fn (Invoice $record, array $data) => $record->update(['inr_rate' => $data['inr_rate']]));
    }

    private static function eInvoiceJsonAction(): Action
    {
        return Action::make('einvoice_json')->label('e-Invoice JSON')->icon('heroicon-o-code-bracket')->color('gray')
            ->visible(fn (Invoice $record) => ! $record->isProforma())
            ->action(function (Invoice $record, Action $action) {
                $json = self::attempt($action, fn () => app(EInvoiceService::class)->json($record), 'The e-invoice cannot be made yet');

                return response()->streamDownload(fn () => print ($json), 'einvoice-'.$record->number.'.json', ['Content-Type' => 'application/json']);
            });
    }

    private static function recordIrnAction(): Action
    {
        return Action::make('einvoice_record')->label(fn (Invoice $record) => $record->irn ? 'IRN details' : 'Record IRN')->icon('heroicon-o-qr-code')->color('gray')
            ->visible(fn (Invoice $record) => ! $record->isProforma())
            ->modalDescription('Paste the response JSON from the Invoice Registration Portal, or type the IRN, acknowledgement and signed QR.')
            ->fillForm(fn (Invoice $record) => ['irn' => $record->irn, 'ack_no' => $record->irn_ack_no, 'ack_date' => $record->irn_ack_date, 'signed_qr' => $record->irn_signed_qr])
            ->schema([
                Textarea::make('response')->label('Portal response JSON')->rows(4),
                TextInput::make('irn')->label('IRN')->maxLength(64),
                TextInput::make('ack_no')->label('Ack No.'),
                DateTimePicker::make('ack_date')->label('Ack Date'),
                Textarea::make('signed_qr')->label('Signed QR code')->rows(3),
            ])
            ->action(function (Invoice $record, array $data, Action $action) {
                self::attempt($action, fn () => app(EInvoiceService::class)->record($record, $data));
                Notification::make()->title('IRN saved – the PDF now carries the e-invoice QR')->success()->send();
            });
    }

    /** Runs a service call; a rule it breaks is shown as a message and the modal stays open. */
    private static function attempt(Action $action, callable $call, string $title = 'Not done'): mixed
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            Notification::make()->title($title)->body(implode(' ', $e->validator->errors()->all()))->danger()->persistent()->send();
            $action->halt();
        }

        return null;
    }

    public static function getPages(): array
    {
        return ['index' => InvoiceResource\Pages\ManageInvoices::route('/')];
    }
}
