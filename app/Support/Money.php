<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function cents(string $amount): int
    {
        if (!preg_match('/^\d{1,14}(?:\.\d{1,2})?$/D', $amount)) {
            throw new InvalidArgumentException('Invalid decimal money value.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Negative money value.');
        }
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
