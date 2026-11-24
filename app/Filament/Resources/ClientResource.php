<?php

namespace App\Filament\Resources;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Gst;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Master data: the clients we invoice, each with our reference number. */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clients';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('ref_no')->label('Ref No')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Client name')->required()->maxLength(255),
            Select::make('client_type')->options(['Foreign' => 'Foreign (export)', 'Indian' => 'Indian'])->default('Foreign')->required()->live(),
            Select::make('currency')->options(Invoice::CURRENCIES)->default('USD')->required(),
            TextInput::make('country')->maxLength(100)->visible(fn (Get $get) => $get('client_type') !== 'Indian'),
            Select::make('state')->options(array_combine(array_keys(Gst::STATE_CODES), array_keys(Gst::STATE_CODES)))->searchable()->visible(fn (Get $get) => $get('client_type') === 'Indian'),
            TextInput::make('gstin')->label('GSTIN')->maxLength(15)->regex('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/')->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper(trim($state)) : null)->visible(fn (Get $get) => $get('client_type') === 'Indian'),
            TextInput::make('contact_person')->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->maxLength(50),
            Textarea::make('address')->helperText('Include the PIN / postal code; the e-invoice needs it for Indian clients.')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ref_no')->label('Ref No')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('client_type')->label('Type')->badge(),
                TextColumn::make('country')->state(fn (Client $record) => $record->isIndian() ? $record->state : $record->country),
                TextColumn::make('gstin')->label('GSTIN')->searchable()->toggleable(),
                TextColumn::make('currency'),
                TextColumn::make('email')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('client_type')->options(['Foreign' => 'Foreign', 'Indian' => 'Indian'])])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (Client $record) => ! $record->invoices()->exists()),
            ])
            ->defaultSort('ref_no');
    }

    public static function getPages(): array
    {
        return ['index' => ClientResource\Pages\ManageClients::route('/')];
    }
}
