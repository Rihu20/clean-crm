<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Company details and invoice settings, one row per key. */
class Setting extends Model
{
    protected $guarded = [];

    public const DEFAULTS = [
        'company_name' => '', 'company_address' => '', 'company_gstin' => '', 'company_email' => '', 'company_phone' => '',
        'lut_arn' => '', 'gst_rate' => '18', 'proforma_prefix' => 'PI', 'tax_prefix' => 'INV', 'payment_days' => '15',
        'terms' => '', 'signatory' => '', 'logo' => '', 'signature' => '',
    ];

    public static function get(string $key): string
    {
        return (string) (static::where('key', $key)->value('value') ?? self::DEFAULTS[$key] ?? '');
    }

    /** @return array<string, string> */
    public static function allValues(): array
    {
        return array_merge(self::DEFAULTS, static::pluck('value', 'key')->map(fn ($v) => (string) $v)->all());
    }

    /** @param  array<string, mixed>  $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
