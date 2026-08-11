<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class T4JamData
{
    public static function adAccounts(): array
    {
        return [
            [
                'account_id' => '331752019953768',
                'id' => 'act_331752019953768',
                'name' => 'BA MKI 9',
                'currency' => 'IDR',
                'campaigns' => ['data' => self::campaignsFor('act_331752019953768')],
            ],
            [
                'account_id' => '1710492416145744',
                'id' => 'act_1710492416145744',
                'name' => 'MJO - Ardita HKM - 27',
                'currency' => 'IDR',
                'campaigns' => ['data' => self::campaignsFor('act_1710492416145744')],
            ],
            [
                'account_id' => '417825920588492',
                'id' => 'act_417825920588492',
                'name' => 'MJO - Ardita HKM - 14',
                'currency' => 'IDR',
                'campaigns' => ['data' => self::campaignsFor('act_417825920588492')],
            ],
            [
                'account_id' => '761015892120918',
                'id' => 'act_761015892120918',
                'name' => 'MJO - Ardita HKM - 11',
                'currency' => 'IDR',
                'campaigns' => ['data' => self::campaignsFor('act_761015892120918')],
            ],
        ];
    }

    public static function campaigns(): array
    {
        return [
            ['daily_budget' => 75000, 'id' => '120251145837270525', 'name' => '1S. SQBI GNG BRAILLE WIN - 30 JUL', 'status' => 'ACTIVE', 'budget_type' => 'campaign', 'ad_id' => 'act_331752019953768'],
            ['daily_budget' => 75000, 'id' => '120251146014730525', 'name' => '2S. SQBI GNG BRAILLE WIN - 30 JUL', 'status' => 'ACTIVE', 'budget_type' => 'campaign', 'ad_id' => 'act_331752019953768'],
            ['daily_budget' => 120000, 'id' => '120245910270090687', 'name' => '1 - CBS. SQBI AL QURAN BRAILLE - 31 MAY', 'status' => 'ACTIVE', 'budget_type' => 'campaign', 'ad_id' => 'act_417825920588492'],
            ['daily_budget' => 75000, 'id' => '120245911219730687', 'name' => 'OFF 2 - CBS. SQBI AL QURAN BRAILLE - 31 MAY', 'status' => 'PAUSED', 'budget_type' => 'campaign', 'ad_id' => 'act_417825920588492'],
            ['daily_budget' => 90000, 'id' => '120248976786140687', 'name' => '1P. CBP SQBI AL QURAN BRAILLE - 31 JUL', 'status' => 'ACTIVE', 'budget_type' => 'campaign', 'ad_id' => 'act_1710492416145744'],
            ['daily_budget' => 110000, 'id' => '120243296040770687', 'name' => 'CBS - 3. ALL GNG BRAILLE WIN - 24 APR', 'status' => 'ACTIVE', 'budget_type' => 'campaign', 'ad_id' => 'act_761015892120918'],
        ];
    }

    public static function campaignsFor(string $accountId): array
    {
        return array_values(array_filter(self::campaigns(), fn (array $campaign) => $campaign['ad_id'] === $accountId));
    }

    public static function defaultAutomationTasks(): array
    {
        return [
            self::task('686cc9b2-b016-4647-8cc3-364e969a5bb4', '120251145837270525', 'BA MKI 9', '1S. SQBI GNG BRAILLE WIN - 30 JUL', 75000, 31980, 0, 'lp_to_form', 'initiate_checkout', 'loss'),
            self::task('4dcb3b80-7fe9-41e1-a9a8-57778192feca', '120251146014730525', 'BA MKI 9', '2S. SQBI GNG BRAILLE WIN - 30 JUL', 75000, 19872, 0, 'lp_to_form', 'initiate_checkout', 'loss'),
            self::task('78053474-54bb-43d9-b7c6-f80d83bb15de', '120245910270090687', 'MJO - Ardita HKM - 14', '1 - CBS. SQBI AL QURAN BRAILLE - 31 MAY', 120000, 126400, 4, 'lp_to_wa', 'purchase', 'onhold', 'false'),
            self::task('b107205e-ad10-44bd-8e1a-5c92c1d3829d', '120248976786140687', 'MJO - Ardita HKM - 27', '1P. CBP SQBI AL QURAN BRAILLE - 31 JUL', 90000, 54200, 2, 'lwa', 'lead', 'bypass'),
        ];
    }

    public static function filteredTasks(array $tasks, ?string $account, ?string $level, ?string $funnel): array
    {
        return array_values(array_filter($tasks, function (array $task) use ($account, $level, $funnel) {
            return (! $account || $account === 'all' || $task['ad_id'] === $account)
                && (! $level || $level === 'all' || $task['level'] === $level)
                && (! $funnel || $funnel === 'all' || $task['event_flow'] === $funnel);
        }));
    }

    public static function insights(): array
    {
        $rows = collect(self::campaigns())->map(function (array $campaign, int $index) {
            $spend = [31980, 19872, 126400, 54200, 87500, 49250][$index] ?? 0;
            $reach = [3590, 2270, 11940, 7620, 9340, 5890][$index] ?? 0;
            $hasil = [1, 0, 4, 2, 3, 1][$index] ?? 0;
            $lpView = [138, 91, 310, 189, 240, 120][$index] ?? 0;
            $click = [206, 130, 410, 233, 290, 178][$index] ?? 0;

            return [
                'campaign_id' => $campaign['id'],
                'campaign_name' => $campaign['name'],
                'budget' => $campaign['daily_budget'],
                'spend' => $spend,
                'reach' => $reach,
                'hasil' => $hasil,
                'cpr' => $hasil > 0 ? round($spend / $hasil) : $spend,
                'link_click' => $click,
                'landing_page_view' => $lpView,
                'klik_landas' => $click > 0 ? round(($lpView / $click) * 100, 1) : 0,
                'uang_jangkauan' => $reach > 0 ? round(($spend / $reach), 1) : 0,
                'uang_klik' => $click > 0 ? round(($spend / $click), 1) : 0,
                'landas_hasil' => $hasil > 0 ? round($lpView / $hasil, 1) : 0,
                'cpr_10' => $hasil > 0 ? round(($spend / $hasil) * 1.1) : $spend,
            ];
        })->values()->all();

        $sum = fn (string $key) => array_sum(array_column($rows, $key));
        $results = max(1, $sum('hasil'));

        return [
            'summery' => $rows,
            'highlight' => [
                self::metric('Reach', 'number', 'reach', $sum('reach')),
                self::metric('Spend', 'currency', 'spend', $sum('spend')),
                self::metric('Landing Page View', 'number', 'landing_page_view', $sum('landing_page_view')),
                self::metric('Link Clicks', 'number', 'link_click', $sum('link_click')),
                self::metric('Hasil (Purchase)', 'number', 'purchase', $sum('hasil')),
                self::metric('CPR', 'currency', 'cpr', round($sum('spend') / $results)),
                self::metric('Klik Landas', 'percen', 'klik_landas', round(($sum('landing_page_view') / max(1, $sum('link_click'))) * 100, 1), 70),
                self::metric('Uang Klik', 'currency', 'uang_klik', round($sum('spend') / max(1, $sum('link_click'))), 190),
                self::metric('Uang Jangkauan', 'currency', 'uang_jangkauan', round($sum('spend') / max(1, $sum('reach'))), 5),
                self::metric('Landas Hasil', 'number', 'landas_hasil', round($sum('landing_page_view') / $results, 1)),
            ],
        ];
    }

    public static function interests(string $keyword): array
    {
        $base = [
            ['name' => 'Sepatu (sepatu)', 'topic' => 'Shopping and fashion', 'audience_size_lower_bound' => 841455391, 'audience_size_upper_bound' => 989551540],
            ['name' => 'Sneakers (sepatu)', 'topic' => 'Shopping and fashion', 'audience_size_lower_bound' => 201164753, 'audience_size_upper_bound' => 236569750],
            ['name' => 'Converse (pabrik sepatu)', 'topic' => 'Shopping and fashion', 'audience_size_lower_bound' => 59855790, 'audience_size_upper_bound' => 70390410],
            ['name' => 'Digital marketing', 'topic' => 'Business and industry', 'audience_size_lower_bound' => 20500000, 'audience_size_upper_bound' => 24100000],
            ['name' => 'Tokopedia', 'topic' => 'Shopping and fashion', 'audience_size_lower_bound' => 35700000, 'audience_size_upper_bound' => 42000000],
        ];

        return array_values(array_map(function (array $item, int $index) use ($keyword) {
            return $item + [
                'id' => (string) (6003348453981 + $index),
                'path' => ['Minat', 'Minat Lainnya', $item['name']],
                'description' => '',
                'keyword' => $keyword,
            ];
        }, self::matching($base, $keyword, 'name'), array_keys(self::matching($base, $keyword, 'name'))));
    }

    public static function products(string $keyword = '', array $filters = []): array
    {
        $products = [
            ['name' => 'Sepatu Running Pria Ringan', 'price' => 185000, 'sold' => 1240, 'reviews' => 621, 'rating' => 4.8, 'category' => 'Fashion Pria', 'image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=320&q=80'],
            ['name' => 'Sandal Wanita Casual Premium', 'price' => 79000, 'sold' => 873, 'reviews' => 402, 'rating' => 4.7, 'category' => 'Fashion Wanita', 'image' => 'https://images.unsplash.com/photo-1603487742131-4160ec999306?auto=format&fit=crop&w=320&q=80'],
            ['name' => 'Tas Selempang Kanvas', 'price' => 99000, 'sold' => 2180, 'reviews' => 1045, 'rating' => 4.9, 'category' => 'Aksesoris', 'image' => 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?auto=format&fit=crop&w=320&q=80'],
            ['name' => 'Jaket Hoodie Polos', 'price' => 155000, 'sold' => 538, 'reviews' => 221, 'rating' => 4.6, 'category' => 'Fashion Pria', 'image' => 'https://images.unsplash.com/photo-1556821840-3a63f95609a7?auto=format&fit=crop&w=320&q=80'],
            ['name' => 'Al Quran Braille Edukasi', 'price' => 245000, 'sold' => 312, 'reviews' => 95, 'rating' => 4.9, 'category' => 'Buku', 'image' => 'https://images.unsplash.com/photo-1519682337058-a94d519337bc?auto=format&fit=crop&w=320&q=80'],
        ];

        $rows = self::matching($products, $keyword, 'name');
        $rows = array_filter($rows, fn (array $product) => self::passesProductFilters($product, $filters));

        return array_values(array_map(fn (array $product, int $index) => $product + [
            'id' => 'prd-'.$index,
            'detail_url' => 'https://www.tokopedia.com/search?st=product&q='.urlencode($product['name']),
        ], $rows, array_keys($rows)));
    }

    public static function categories(): array
    {
        return [
            ['id' => 'fashion-pria', 'name' => 'Fashion Pria'],
            ['id' => 'fashion-wanita', 'name' => 'Fashion Wanita'],
            ['id' => 'aksesoris', 'name' => 'Aksesoris'],
            ['id' => 'buku', 'name' => 'Buku'],
        ];
    }

    private static function task(string $id, string $campaignId, string $account, string $campaign, int $budget, int $spend, int $hasil, string $flow, string $conversion, string $system, string $status = 'true'): array
    {
        return [
            'id' => $id,
            'campaign_id' => $campaignId,
            'current_budget' => $budget,
            'current_spend' => $spend,
            'current_cpr' => $hasil > 0 ? round($spend / $hasil) : $spend,
            'current_hasil' => $hasil,
            'event_flow' => $flow,
            'system_flow' => $system,
            'conversion' => $conversion,
            'cpr_cap' => 35000,
            'log' => 'Menurunkan budget ke '.number_format($budget, 0, ',', '.').' berhasil',
            'status' => $status,
            'ad_id' => 'act_'.substr($campaignId, -15),
            'ad_account' => $account,
            'campaign_name' => $campaign,
            'level' => 'campaign',
            'mode' => 'default',
            'last_update' => now('Asia/Jakarta')->format('d-m-Y, H:i'),
            'act_bermasalah' => false,
            'is_reach_limit' => false,
            'limit_time' => false,
            'limit_open' => 0,
            'starting_budget' => $budget,
            'maximum_budget' => 0,
            'period' => 10,
            'pause_cpr_cap' => 70000,
            'on_time' => '01:00',
            'off_time' => '21:00',
        ];
    }

    private static function metric(string $name, string $type, string $key, int|float $value, int|float|null $min = null): array
    {
        $text = match ($type) {
            'currency' => 'Rp. '.number_format($value, 0, ',', '.').',-',
            'percen' => number_format($value, 1).' %',
            default => number_format($value, 0, ',', '.'),
        };

        return array_filter([
            'name' => $name,
            'data_type' => $type,
            'metric_name' => $key,
            'value' => $value,
            'value_text' => $text,
            'min_value' => $min,
            'instruction' => '',
        ], fn ($value) => $value !== null);
    }

    private static function matching(array $rows, string $keyword, string $field): array
    {
        if (trim($keyword) === '') {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row) => Str::contains(Str::lower($row[$field]), Str::lower($keyword))));
    }

    private static function passesProductFilters(array $product, array $filters): bool
    {
        return $product['price'] >= (int) ($filters['min_price'] ?: 0)
            && ($filters['max_price'] === null || $filters['max_price'] === '' || $product['price'] <= (int) $filters['max_price'])
            && $product['sold'] >= (int) ($filters['min_sold'] ?: 0)
            && (! Arr::get($filters, 'category') || Str::contains(Str::lower($product['category']), Str::lower($filters['category'])));
    }
}
