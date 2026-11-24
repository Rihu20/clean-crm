<?php

namespace App\Filament\Resources\BankAccountResource\Pages;

use App\Filament\Resources\BankAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBankAccounts extends ManageRecords
{
    protected static string $resource = BankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add bank account')];
    }
}
