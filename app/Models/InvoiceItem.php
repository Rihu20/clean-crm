<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:2', 'rate' => 'decimal:2', 'amount' => 'decimal:2', 'taxable' => 'boolean'];
    }
}
