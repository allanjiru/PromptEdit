<?php

namespace Tests\Unit;

use App\Services\GenerationPricingService;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerationPricingServiceTest extends TestCase
{
    private GenerationPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = new GenerationPricingService;
    }

    public function test_provider_cost_uses_each_billable_token_category(): void
    {
        $cost = $this->pricing->providerCostUnits(
            [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 500,
            ],
            [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ]
        );

        $this->assertSame(50_310_000, $cost);
        $this->assertSame(
            62_887_500,
            $this->pricing->customerChargeUnits($cost)
        );
    }

    public function test_fractional_nanodollar_provider_cost_rounds_up(): void
    {
        $cost = $this->pricing->providerCostUnits(
            ['input_text_tokens' => 1],
            ['input_text_tokens' => '0.000000001']
        );

        $this->assertSame(1, $cost);
    }

    public function test_markup_rounds_up_to_a_whole_nanodollar(): void
    {
        $this->assertSame(
            2,
            $this->pricing->customerChargeUnits(1)
        );
        $this->assertSame(
            5,
            $this->pricing->customerChargeUnits(4)
        );
    }

    #[DataProvider('formattedAmounts')]
    public function test_it_formats_exact_nine_decimal_usd_values(
        int $units,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            $this->pricing->formatUsd($units)
        );
    }

    /** @return iterable<string, array{int, string}> */
    public static function formattedAmounts(): iterable
    {
        yield 'zero' => [0, '0.000000000'];
        yield 'one nanodollar' => [1, '0.000000001'];
        yield 'one dollar' => [1_000_000_000, '1.000000000'];
        yield 'negative ledger value' => [-1_250_000_000, '-1.250000000'];
    }

    public function test_missing_rate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing rate');

        $this->pricing->providerCostUnits(
            ['output_tokens' => 10],
            []
        );
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_usage_and_rates_are_rejected(
        array $usage,
        array $rates
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $this->pricing->providerCostUnits($usage, $rates);
    }

    /**
     * @return iterable<string, array{
     *     array<array-key, mixed>,
     *     array<array-key, mixed>
     * }>
     */
    public static function invalidInputs(): iterable
    {
        yield 'negative tokens' => [
            ['output_tokens' => -1],
            ['output_tokens' => '40.00'],
        ];

        yield 'non-integer tokens' => [
            ['output_tokens' => '10'],
            ['output_tokens' => '40.00'],
        ];

        yield 'negative rate' => [
            ['output_tokens' => 10],
            ['output_tokens' => '-40.00'],
        ];

        yield 'excess precision' => [
            ['output_tokens' => 10],
            ['output_tokens' => '0.0000000001'],
        ];
    }

    public function test_values_outside_integer_range_are_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exceeds supported range');

        $this->pricing->providerCostUnits(
            ['output_tokens' => PHP_INT_MAX],
            ['output_tokens' => '999999999.00']
        );
    }
}
