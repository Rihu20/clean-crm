<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Setting;

/** GST state codes and place of supply. */
final class Gst
{
    public const STATE_CODES = ['Jammu and Kashmir' => '01', 'Himachal Pradesh' => '02', 'Punjab' => '03', 'Chandigarh' => '04', 'Uttarakhand' => '05', 'Haryana' => '06', 'Delhi' => '07', 'Rajasthan' => '08', 'Uttar Pradesh' => '09', 'Bihar' => '10', 'Sikkim' => '11', 'Arunachal Pradesh' => '12', 'Nagaland' => '13', 'Manipur' => '14', 'Mizoram' => '15', 'Tripura' => '16', 'Meghalaya' => '17', 'Assam' => '18', 'West Bengal' => '19', 'Jharkhand' => '20', 'Odisha' => '21', 'Chhattisgarh' => '22', 'Madhya Pradesh' => '23', 'Gujarat' => '24', 'Dadra and Nagar Haveli and Daman and Diu' => '26', 'Maharashtra' => '27', 'Karnataka' => '29', 'Goa' => '30', 'Lakshadweep' => '31', 'Kerala' => '32', 'Tamil Nadu' => '33', 'Puducherry' => '34', 'Andaman and Nicobar Islands' => '35', 'Telangana' => '36', 'Andhra Pradesh' => '37', 'Ladakh' => '38'];

    /** From the GSTIN, else from the state name. */
    public static function stateCode(Client $client): ?string
    {
        if (preg_match('/^(\d{2})/', (string) $client->gstin, $m)) {
            return $m[1];
        }

        return self::STATE_CODES[trim((string) $client->state)] ?? null;
    }

    public static function companyStateCode(): ?string
    {
        return preg_match('/^(\d{2})/', Setting::get('company_gstin'), $m) ? $m[1] : null;
    }

    public static function placeOfSupply(Client $client): string
    {
        if (! $client->isIndian()) {
            return 'Outside India – '.($client->country ?: 'export of services');
        }
        $code = self::stateCode($client);

        return ($client->state ?: (array_search($code, self::STATE_CODES, true) ?: '—')).($code ? ' ('.$code.')' : '');
    }
}
