<?php

namespace Database\Seeders;

use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\AutomationLog;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\Interest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\T4JamProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@t4jam.local'],
            [
                'name' => 'T4Jam Admin',
                'password' => Hash::make('password'),
            ]
        );

        T4JamProfile::firstOrCreate(['user_id' => $user->id]);

        $accounts = [
            ['account_id' => '331752019953768', 'external_id' => 'act_331752019953768', 'name' => 'BA MKI 9', 'currency' => 'IDR'],
            ['account_id' => '1710492416145744', 'external_id' => 'act_1710492416145744', 'name' => 'MJO - Ardita HKM - 27', 'currency' => 'IDR'],
            ['account_id' => '417825920588492', 'external_id' => 'act_417825920588492', 'name' => 'MJO - Ardita HKM - 14', 'currency' => 'IDR'],
            ['account_id' => '761015892120918', 'external_id' => 'act_761015892120918', 'name' => 'MJO - Ardita HKM - 11', 'currency' => 'IDR'],
        ];

        $accountModels = collect($accounts)->mapWithKeys(fn (array $account) => [
            $account['external_id'] => AdAccount::updateOrCreate(['external_id' => $account['external_id']], $account),
        ]);

        $campaigns = [
            ['ad' => 'act_331752019953768', 'external_id' => '120251145837270525', 'name' => '1S. SQBI GNG BRAILLE WIN - 30 JUL', 'status' => 'ACTIVE', 'daily_budget' => 75000, 'spend' => 31980, 'reach' => 3590, 'result' => 1, 'link_click' => 206, 'landing_page_view' => 138],
            ['ad' => 'act_331752019953768', 'external_id' => '120251146014730525', 'name' => '2S. SQBI GNG BRAILLE WIN - 30 JUL', 'status' => 'ACTIVE', 'daily_budget' => 75000, 'spend' => 19872, 'reach' => 2270, 'result' => 0, 'link_click' => 130, 'landing_page_view' => 91],
            ['ad' => 'act_417825920588492', 'external_id' => '120245910270090687', 'name' => '1 - CBS. SQBI AL QURAN BRAILLE - 31 MAY', 'status' => 'ACTIVE', 'daily_budget' => 120000, 'spend' => 126400, 'reach' => 11940, 'result' => 4, 'link_click' => 410, 'landing_page_view' => 310],
            ['ad' => 'act_417825920588492', 'external_id' => '120245911219730687', 'name' => 'OFF 2 - CBS. SQBI AL QURAN BRAILLE - 31 MAY', 'status' => 'PAUSED', 'daily_budget' => 75000, 'spend' => 54200, 'reach' => 7620, 'result' => 2, 'link_click' => 233, 'landing_page_view' => 189],
            ['ad' => 'act_1710492416145744', 'external_id' => '120248976786140687', 'name' => '1P. CBP SQBI AL QURAN BRAILLE - 31 JUL', 'status' => 'ACTIVE', 'daily_budget' => 90000, 'spend' => 87500, 'reach' => 9340, 'result' => 3, 'link_click' => 290, 'landing_page_view' => 240],
            ['ad' => 'act_761015892120918', 'external_id' => '120243296040770687', 'name' => 'CBS - 3. ALL GNG BRAILLE WIN - 24 APR', 'status' => 'ACTIVE', 'daily_budget' => 110000, 'spend' => 49250, 'reach' => 5890, 'result' => 1, 'link_click' => 178, 'landing_page_view' => 120],
        ];

        $campaignModels = collect($campaigns)->mapWithKeys(function (array $campaign) use ($accountModels) {
            $adAccount = $accountModels[$campaign['ad']];
            $payload = collect($campaign)->except('ad')->merge([
                'ad_account_id' => $adAccount->id,
                'budget_type' => 'campaign',
                'level' => 'campaign',
            ])->all();

            return [$campaign['external_id'] => Campaign::updateOrCreate(['external_id' => $campaign['external_id']], $payload)];
        });

        foreach ($campaignModels as $campaign) {
            AdSet::updateOrCreate(
                ['external_id' => 'adset_'.$campaign->external_id],
                [
                    'ad_account_id' => $campaign->ad_account_id,
                    'campaign_id' => $campaign->id,
                    'name' => $campaign->name.' - Ad Set 1',
                    'status' => $campaign->status,
                    'effective_status' => $campaign->effective_status,
                    'daily_budget' => $campaign->daily_budget,
                    'spend' => $campaign->spend,
                    'reach' => $campaign->reach,
                    'result' => $campaign->result,
                    'link_click' => $campaign->link_click,
                    'landing_page_view' => $campaign->landing_page_view,
                ],
            );
        }

        foreach ($campaignModels->take(4) as $campaign) {
            $task = AutomationTask::firstOrCreate(
                ['campaign_external_id' => $campaign->external_id],
                [
                    'id' => (string) Str::uuid(),
                    'ad_account_id' => $campaign->ad_account_id,
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'ad_account_name' => $campaign->adAccount->name,
                    'event_flow' => $campaign->result > 1 ? 'lp_to_wa' : 'lp_to_form',
                    'system_flow' => $campaign->result > 1 ? 'onhold' : 'loss',
                    'conversion' => $campaign->result > 1 ? 'purchase' : 'initiate_checkout',
                    'current_budget' => $campaign->daily_budget,
                    'current_spend' => $campaign->spend,
                    'current_result' => $campaign->result,
                    'cpr_cap' => 35000,
                    'last_log' => 'Menurunkan budget ke '.number_format($campaign->daily_budget, 0, ',', '.').' berhasil',
                    'last_checked_at' => now(),
                ]
            );

            AutomationLog::firstOrCreate(
                ['automation_task_id' => $task->id],
                ['messages' => ['BOT membaca metrik campaign', 'Budget campaign berhasil dipantau']]
            );
        }

        $interests = [
            ['external_id' => '6003348453981', 'name' => 'Sepatu (sepatu)', 'topic' => 'Shopping and fashion', 'audience_size_lower_bound' => 841455391, 'audience_size_upper_bound' => 989551540, 'path' => ['Minat', 'Pakaian', 'Sepatu'], 'keyword' => 'sepatu'],
            ['external_id' => '6003256793569', 'name' => 'Sepatu & Sandal (pakaian)', 'topic' => 'Shopping and fashion', 'audience_size_lower_bound' => 551024073, 'audience_size_upper_bound' => 648004310, 'path' => ['Minat', 'Minat Lainnya', 'Sepatu & Sandal'], 'keyword' => 'sepatu'],
            ['external_id' => '6003384587151', 'name' => 'Sneakers (sepatu)', 'topic' => 'Shopping and fashion', 'audience_size_lower_bound' => 201164753, 'audience_size_upper_bound' => 236569750, 'path' => ['Minat', 'Minat Lainnya', 'Sneakers'], 'keyword' => 'sepatu'],
            ['external_id' => '6004051193896', 'name' => 'Digital marketing', 'topic' => 'Business and industry', 'audience_size_lower_bound' => 20500000, 'audience_size_upper_bound' => 24100000, 'path' => ['Minat', 'Bisnis', 'Digital marketing'], 'keyword' => 'marketing'],
        ];

        foreach ($interests as $interest) {
            Interest::updateOrCreate(['external_id' => $interest['external_id']], $interest);
        }

        $categories = collect([
            ['external_id' => 'fashion-pria', 'name' => 'Fashion Pria'],
            ['external_id' => 'fashion-wanita', 'name' => 'Fashion Wanita'],
            ['external_id' => 'aksesoris', 'name' => 'Aksesoris'],
            ['external_id' => 'buku', 'name' => 'Buku'],
        ])->mapWithKeys(fn (array $category) => [
            $category['external_id'] => ProductCategory::updateOrCreate(['external_id' => $category['external_id']], $category),
        ]);

        foreach ([
            ['cat' => 'fashion-pria', 'external_id' => 'prd-001', 'name' => 'Sepatu Running Pria Ringan', 'price' => 185000, 'sold' => 1240, 'total_review' => 621, 'rating' => 4.8, 'image_url' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=320&q=80'],
            ['cat' => 'fashion-wanita', 'external_id' => 'prd-002', 'name' => 'Sandal Wanita Casual Premium', 'price' => 79000, 'sold' => 873, 'total_review' => 402, 'rating' => 4.7, 'image_url' => 'https://images.unsplash.com/photo-1603487742131-4160ec999306?auto=format&fit=crop&w=320&q=80'],
            ['cat' => 'aksesoris', 'external_id' => 'prd-003', 'name' => 'Tas Selempang Kanvas', 'price' => 99000, 'sold' => 2180, 'total_review' => 1045, 'rating' => 4.9, 'image_url' => 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?auto=format&fit=crop&w=320&q=80'],
            ['cat' => 'fashion-pria', 'external_id' => 'prd-004', 'name' => 'Jaket Hoodie Polos', 'price' => 155000, 'sold' => 538, 'total_review' => 221, 'rating' => 4.6, 'image_url' => 'https://images.unsplash.com/photo-1556821840-3a63f95609a7?auto=format&fit=crop&w=320&q=80'],
            ['cat' => 'buku', 'external_id' => 'prd-005', 'name' => 'Al Quran Braille Edukasi', 'price' => 245000, 'sold' => 312, 'total_review' => 95, 'rating' => 4.9, 'image_url' => 'https://images.unsplash.com/photo-1519682337058-a94d519337bc?auto=format&fit=crop&w=320&q=80'],
        ] as $product) {
            Product::updateOrCreate(
                ['external_id' => $product['external_id']],
                collect($product)->except('cat')->merge([
                    'product_category_id' => $categories[$product['cat']]->id,
                    'detail_url' => 'https://www.tokopedia.com/search?st=product&q='.urlencode($product['name']),
                    'last_added_at' => now()->subDays(rand(1, 30)),
                ])->all()
            );
        }
    }
}
