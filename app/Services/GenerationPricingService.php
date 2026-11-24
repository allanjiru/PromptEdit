<?php

namespace App\Services;

use InvalidArgumentException;
use LogicException;

class GenerationPricingService
{
    /**
     * Calculate provider cost in integer nanodollars.
     *
     * Rates are USD per 1,000,000 tokens.
     *
     * Example:
     * tokens = 1000
     * rate = "40.00"
     * cost = $0.040000000
     *
     * @param  array<array-key, mixed>  $usage
     * @param  array<array-key, mixed>  $rates
     */
    public function providerCostUnits(
        array $usage,
        array $rates
    ): int {
        $total = '0';

        foreach ($usage as $category => $tokens) {
            if (
                ! is_string($category) ||
                ! is_int($tokens) ||
                $tokens < 0
            ) {
                throw new InvalidArgumentException(
                    'Invalid token usage.'
                );
            }

            if (! array_key_exists($category, $rates)) {
                throw new InvalidArgumentException(
                    "Missing rate for {$category}."
                );
            }

            $rate = $rates[$category];

            if (
                ! is_string($rate) ||
                ! is_numeric($rate) ||
                ! preg_match(
                    '/^\d+(?:\.\d{1,9})?$/',
                    $rate
                )
            ) {
                throw new InvalidArgumentException(
                    'Rates must be nonnegative decimal strings.'
                );
            }

            // Convert USD to nanodollars per million tokens.
            $rateNanodollars = bcmul(
                $rate,
                '1000000000',
                0
            );

            $numerator = bcmul(
                (string) $tokens,
                $rateNanodollars,
                0
            );

            $quotient = bcdiv(
                $numerator,
                '1000000',
                0
            );

            $remainder = bcmod(
                $numerator,
                '1000000'
            );

            // Round this category's cost upward.
            if (bccomp($remainder, '0', 0) > 0) {
                $quotient = bcadd($quotient, '1', 0);
            }

            $total = bcadd($total, $quotient, 0);
        }

        return $this->toSafeInteger($total);
    }

    /**
     * Add a 25% markup, rounding upward to one nanodollar.
     */
    public function customerChargeUnits(
        int $providerCostUnits
    ): int {
        if ($providerCostUnits < 0) {
            throw new InvalidArgumentException(
                'Provider cost cannot be negative.'
            );
        }

        $cost = (string) $providerCostUnits;

        $markup = bcdiv($cost, '4', 0);

        if (bccomp(bcmod($cost, '4'), '0', 0) > 0) {
            $markup = bcadd($markup, '1', 0);
        }

        return $this->toSafeInteger(
            bcadd($cost, $markup, 0)
        );
    }

    /**
     * Convert nanodollars to a nine-decimal USD string.
     */
    public function formatUsd(int $units): string
    {
        $negative = $units < 0;

        $absolute = $negative
            ? substr((string) $units, 1)
            : (string) $units;

        $whole = bcdiv($absolute, '1000000000', 0);
        $fraction = str_pad(
            bcmod($absolute, '1000000000'),
            9,
            '0',
            STR_PAD_LEFT
        );

        return ($negative ? '-' : '')
            .$whole.'.'.$fraction;
    }

    /** @param numeric-string $value */
    private function toSafeInteger(string $value): int
    {
        if (
            bccomp($value, '0', 0) < 0 ||
            bccomp($value, (string) PHP_INT_MAX, 0) > 0
        ) {
            throw new LogicException(
                'Monetary amount exceeds supported range.'
            );
        }

        return (int) $value;
    }
}
