<?php

namespace App\Filament\Resources;

use App\Models\BankAccount;
use App\Models\Invoice;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Settings: our own bank accounts, printed on the invoice for the client to pay into. */
class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Our bank accounts';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('bank_name')->required()->maxLength(255),
            Select::make('currency')->options(Invoice::CURRENCIES)->default('USD')->required()->helperText('Invoices in this currency use this account.'),
            TextInput::make('beneficiary_name')->required()->maxLength(255),
            TextInput::make('account_no')->label('Account / IBAN no.')->required()->maxLength(64),
            TextInput::make('swift')->label('SWIFT / BIC')->maxLength(20),
            TextInput::make('ifsc')->label('IFSC')->maxLength(20),
            Textarea::make('beneficiary_address'),
            Textarea::make('bank_address'),
            Toggle::make('is_default')->label('Default for its currency'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_name')->searchable(),
                TextColumn::make('currency')->badge(),
                TextColumn::make('account_no')->label('Account no.'),
                TextColumn::make('swift')->label('SWIFT'),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => BankAccountResource\Pages\ManageBankAccounts::route('/')];
    }
}
