<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationPricingService;
use App\Services\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ReconcilePixelforgeUsage extends Command
{
    protected $signature = 'pixelforge:reconcile-usage
        {--start-date= : First UTC date (YYYY-MM-DD)}
        {--end-date= : Last UTC date (YYYY-MM-DD)}';

    protected $description =
        'Compare local generation usage and cost with Pixelforge usage totals.';

    public function handle(
        PixelforgeClient $client,
        GenerationPricingService $pricing
    ): int {
        try {
            $end = $this->dateOption('end-date')
                ?? CarbonImmutable::now('UTC')->subDay()->startOfDay();
            $start = $this->dateOption('start-date') ?? $end;
        } catch (\InvalidArgumentException) {
            $this->error('Dates must use YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($start->gt($end) || $start->diffInDays($end) > 91) {
            $this->error('The inclusive date range must cover at most 92 days.');

            return self::FAILURE;
        }

        $provider = $client->usage(
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );

        $providerData = $provider['data'] ?? null;

        if (! is_array($providerData)) {
            $this->error('Pixelforge returned an invalid usage report.');

            return self::FAILURE;
        }

        $providerDays = [];

        foreach ($providerData as $day) {
            if (is_array($day) && is_string($day['date'] ?? null)) {
                $providerDays[$day['date']] = $day;
            }
        }
        $mismatches = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $dateString = $date->format('Y-m-d');
            $local = $this->localTotals($date);
            $remote = $providerDays[$dateString] ?? null;

            if (! is_array($remote)) {
                $mismatches[] = "{$dateString}: provider day is missing";

                continue;
            }

            $expected = [
                'generations' => $local['generations'],
                'input_text_tokens' => $local['input_text_tokens'],
                'input_image_tokens' => $local['input_image_tokens'],
                'output_tokens' => $local['output_tokens'],
                'reasoning_tokens' => $local['reasoning_tokens'],
                'cost_usd' => $pricing->formatUsd($local['cost_units']),
            ];

            foreach ($expected as $field => $value) {
                if (($remote[$field] ?? null) !== $value) {
                    $actual = json_encode($remote[$field] ?? null);
                    $mismatches[] =
                        "{$dateString} {$field}: local {$value}, provider {$actual}";
                }
            }
        }

        if ($mismatches !== []) {
            foreach ($mismatches as $mismatch) {
                $this->error($mismatch);
            }

            return self::FAILURE;
        }

        $this->info('Pixelforge usage matches local billing records.');

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! CarbonImmutable::hasFormat($value, 'Y-m-d')) {
            throw new \InvalidArgumentException('Invalid date.');
        }

        $date = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            'UTC'
        );

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid date.');
        }

        return $date;
    }

    /** @return array<string, int> */
    private function localTotals(CarbonImmutable $date): array
    {
        $totals = [
            'generations' => 0,
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_units' => 0,
        ];

        Generation::query()
            ->where('status', Generation::STATUS_COMPLETED)
            ->whereBetween('completed_at', [
                $date->startOfDay(),
                $date->endOfDay(),
            ])
            ->orderBy('id')
            ->each(function (Generation $generation) use (&$totals) {
                $usage = $generation->provider_usage;

                if (! is_array($usage)) {
                    // A succeeded generation without a snapshot must cause the
                    // comparison to fail rather than silently disappear.
                    $totals['generations']++;
                    $totals['cost_units'] +=
                        $generation->provider_cost_units ?? 0;

                    return;
                }

                $totals['generations']++;
                $totals['cost_units'] +=
                    $generation->provider_cost_units ?? 0;

                foreach ([
                    'input_text_tokens',
                    'input_image_tokens',
                    'output_tokens',
                    'reasoning_tokens',
                ] as $category) {
                    $totals[$category] += (int) ($usage[$category] ?? 0);
                }
            });

        return $totals;
    }
}
