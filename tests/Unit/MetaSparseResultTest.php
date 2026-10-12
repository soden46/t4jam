<?php

namespace Tests\Unit;

use App\Services\AutomationBudgetService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaSparseResultTest extends TestCase
{
    #[DataProvider('sparseResults')]
    public function test_sparse_native_results_only_become_zero_with_valid_zero_spend(array $changes, bool $available): void
    {
        $insights = array_replace([
            'spend' => '0',
            'results' => [['indicator' => 'actions:offsite_conversion.fb_pixel_initiate_checkout']],
            'inline_link_clicks' => '0',
            'date_start' => '2026-10-12',
            'date_stop' => '2026-10-12',
        ], $changes);

        $metrics = app(AutomationBudgetService::class)->metricSnapshot($insights, 'initiate_checkout');

        $this->assertSame($available ? ['conversion' => 'initiate_checkout', 'count' => 0] : null, $metrics['meta_result']);
    }

    public static function sparseResults(): array
    {
        $indicator = 'actions:offsite_conversion.fb_pixel_initiate_checkout';

        return [
            'production zero response' => [[], true],
            'numeric zero' => [['spend' => 0], true],
            'positive spend' => [['spend' => '100'], false],
            'missing spend' => [['spend' => null], false],
            'invalid spend' => [['spend' => 'unknown'], false],
            'negative spend' => [['spend' => '-1'], false],
            'positive checkout actions despite zero spend' => [['actions' => [
                ['action_type' => 'omni_initiated_checkout', 'value' => '2'],
            ]], false],
            'null values are malformed' => [['results' => [['indicator' => $indicator, 'values' => null]]], false],
            'empty values are malformed' => [['results' => [['indicator' => $indicator, 'values' => []]]], false],
            'invalid value is malformed' => [['results' => [['indicator' => $indicator, 'values' => [['value' => 'unknown']]]]], false],
            'multiple windows remain ambiguous' => [['results' => [['indicator' => $indicator, 'values' => [['value' => '0'], ['value' => '0']]]]], false],
            'multiple indicators remain ambiguous' => [['results' => [['indicator' => $indicator], ['indicator' => 'actions:lead']]], false],
            'missing indicator' => [['results' => [[]]], false],
        ];
    }

    public function test_sparse_link_click_result_does_not_erase_positive_clicks(): void
    {
        $metrics = app(AutomationBudgetService::class)->metricSnapshot([
            'spend' => '0', 'inline_link_clicks' => '2',
            'results' => [['indicator' => 'actions:link_click']],
        ]);

        $this->assertNull($metrics['meta_result']);
    }
}
