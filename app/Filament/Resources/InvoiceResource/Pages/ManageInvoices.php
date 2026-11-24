<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageInvoices extends ManageRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'drafts' => Tab::make('Drafts')->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'Draft'))->badge(Invoice::where('status', 'Draft')->count() ?: null),
            'proforma' => Tab::make('Proforma')->modifyQueryUsing(fn (Builder $q) => $q->where('doc_type', 'proforma')->where('status', '!=', 'Draft')),
            'pending' => Tab::make('Payment pending')->modifyQueryUsing(fn (Builder $q) => $q->where('doc_type', 'proforma')->whereIn('status', ['Unpaid', 'Partially paid'])),
            'tax' => Tab::make('Tax invoices')->modifyQueryUsing(fn (Builder $q) => $q->where('doc_type', 'tax')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New proforma')->icon('heroicon-o-plus')->modalWidth('5xl')
                ->schema(InvoiceResource::proformaForm())
                ->action(fn (array $data) => app(InvoiceService::class)->saveDraft($data)),
        ];
    }
}
