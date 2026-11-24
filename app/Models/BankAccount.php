<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Our own bank accounts, printed on invoices for the client to pay into. */
class BankAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
