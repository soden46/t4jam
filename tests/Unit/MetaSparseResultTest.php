<?php

namespace Tests\Unit;

use App\Services\AutomationBudgetService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaSparseResultTest extends TestCase
{
    #[DataProvider('sparseResults')]
    public function test_sparse_native_results_require_valid_spend_and_selected_event_evidence(array $changes, ?int $expected): void
    {
        $insights = array_replace([
            'spend' => '0',
            'results' => [['indicator' => 'actions:offsite_conversion.fb_pixel_initiate_checkout']],
            'inline_link_clicks' => '0',
            'date_start' => '2026-10-12',
            'date_stop' => '2026-10-12',
        ], $changes);

        $metrics = app(AutomationBudgetService::class)->metricSnapshot($insights, 'initiate_checkout');

        $this->assertSame($expected !== null ? ['conversion' => 'initiate_checkout', 'count' => $expected] : null, $metrics['meta_result']);
    }

    public static function sparseResults(): array
    {
        $indicator = 'actions:offsite_conversion.fb_pixel_initiate_checkout';

        return [
            'production zero response' => [[], 0],
            'numeric zero' => [['spend' => 0], 0],
            'positive spend with confirmed event' => [['spend' => '35148'], 0],
            'missing spend' => [['spend' => null], null],
            'invalid spend' => [['spend' => 'unknown'], null],
            'negative spend' => [['spend' => '-1'], null],
            'positive checkout actions despite zero spend' => [['actions' => [
                ['action_type' => 'omni_initiated_checkout', 'value' => '2'],
            ]], 2],
            'null values are malformed' => [['results' => [['indicator' => $indicator, 'values' => null]]], null],
            'empty values are malformed' => [['results' => [['indicator' => $indicator, 'values' => []]]], null],
            'invalid value is malformed' => [['results' => [['indicator' => $indicator, 'values' => [['value' => 'unknown']]]]], null],
            'multiple windows remain ambiguous' => [['results' => [['indicator' => $indicator, 'values' => [['value' => '0'], ['value' => '0']]]]], null],
            'multiple indicators remain ambiguous' => [['results' => [['indicator' => $indicator], ['indicator' => 'actions:lead']]], null],
            'missing indicator' => [['results' => [[]]], null],
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

    #[DataProvider('unconfirmedEvents')]
    public function test_positive_spend_with_sparse_results_requires_matching_meta_settings(?string $selected): void
    {
        $metrics = app(AutomationBudgetService::class)->metricSnapshot([
            'spend' => '35148',
            'results' => [['indicator' => 'actions:offsite_conversion.fb_pixel_initiate_checkout']],
            'actions' => [['action_type' => 'purchase', 'value' => '5']],
        ], $selected);

        $this->assertNull($metrics['meta_result']);
    }

    public static function unconfirmedEvents(): array
    {
        return [[null], ['purchase'], ['lead']];
    }

    public function test_confirmed_sparse_click_result_uses_actual_clicks(): void
    {
        $metrics = app(AutomationBudgetService::class)->metricSnapshot([
            'spend' => '22466', 'inline_link_clicks' => '8',
            'results' => [['indicator' => 'actions:link_click']],
        ], 'link_click');

        $this->assertSame(['conversion' => 'link_click', 'count' => 8], $metrics['meta_result']);
    }
}
