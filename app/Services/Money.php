<?php

namespace App\Services;

/** Money in exact decimals (bcmath), rounded half up to paise / cents. */
final class Money
{
    public static function round(string $n): string
    {
        return bcadd($n, bccomp($n, '0', 8) >= 0 ? '0.005' : '-0.005', 2);
    }

    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul($a, $b, 8));
    }

    /** 123456.5 → "1,23,456.50" for rupees, "123,456.50" otherwise (yen without decimals). */
    public static function number(string $amount, string $currency): string
    {
        if ($currency === 'INR') {
            $f = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
            $f->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
            $f->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

            return $f->format((float) $amount);
        }

        return number_format((float) $amount, $currency === 'JPY' ? 0 : 2);
    }

    public static function format(string $amount, string $currency): string
    {
        return $currency.' '.self::number($amount, $currency);
    }
}
