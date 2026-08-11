<?php

namespace App\Http\Controllers;

use App\Exceptions\MetaAdsException;
use App\Jobs\SyncMetaAdsProfile;
use App\Models\AdAccount;
use App\Models\AutomationLog;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\Interest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\T4JamProfile;
use App\Services\MetaAdsClient;
use App\Services\MetaAdsSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class T4JamController extends Controller
{
    public function dashboard(): View
    {
        return view('dashboard', [
            'title' => 'Report Dashboard',
            'accounts' => AdAccount::with('campaigns')->get(),
            'insights' => $this->insightsPayload(),
        ]);
    }

    public function automation(): View
    {
        return view('automation', [
            'title' => 'Automation Budget Strategy',
            'accounts' => AdAccount::query()->orderBy('name')->get(),
            'tasks' => AutomationTask::with(['adAccount', 'campaign'])->latest()->get(),
        ]);
    }

    public function interest(): View
    {
        return view('interest', ['title' => 'Interest Explore']);
    }

    public function products(): View
    {
        return view('products', [
            'title' => 'Product Research',
            'categories' => ProductCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function profile(): View
    {
        return view('profile', ['title' => 'Account Settings']);
    }

    public function privacy(): View
    {
        return view('legal', ['heading' => 'Privacy Policy']);
    }

    public function terms(): View
    {
        return view('legal', ['heading' => 'Term of Service']);
    }

    public function adAccounts(): JsonResponse
    {
        $accounts = AdAccount::with('campaigns')->get();

        return response()->json([
            'status' => 200,
            'adaccount' => $accounts->map(fn (AdAccount $account) => $this->accountPayload($account))->values(),
            'selected' => session('selected_ad_account', $accounts->first()?->external_id),
            'fix_campaign_list' => Campaign::with('adAccount')->get()->map(fn (Campaign $campaign) => $this->campaignPayload($campaign))->values(),
        ]);
    }

    public function adInsights(): JsonResponse
    {
        return response()->json($this->insightsPayload());
    }

    public function changeAdAccount(Request $request): JsonResponse
    {
        session(['selected_ad_account' => $request->input('ad_account')]);

        return response()->json(['status' => 200, 'text' => 'Ad account berhasil dipilih']);
    }

    public function changeSelectedCampaign(Request $request): JsonResponse
    {
        session(['selected_campaigns' => $request->input('campaigns', [])]);

        return response()->json(['status' => 200, 'text' => 'Campaign berhasil diperbarui']);
    }

    public function changeSettings(Request $request): JsonResponse
    {
        session(['dashboard_settings' => $request->only(['funnel_lp', 'conversion', 'level_mode'])]);

        return response()->json(['status' => 200, 'text' => 'Settings dashboard tersimpan']);
    }

    public function reloadAdAccount(MetaAdsSyncService $metaSync): JsonResponse
    {
        $profile = $this->currentProfile();

        if ($profile->access_token) {
            try {
                $metaSync->sync($profile);
            } catch (MetaAdsException $exception) {
                return response()->json(['status' => 422, 'text' => $exception->getMessage()], 422);
            }
        }

        return response()->json([
            'status' => 200,
            'text' => 'Ad account berhasil direload',
            'adaccount' => AdAccount::with('campaigns')->get()->map(fn (AdAccount $account) => $this->accountPayload($account))->values(),
        ]);
    }

    public function checkConnection(MetaAdsSyncService $metaSync): JsonResponse
    {
        $profile = $this->currentProfile();

        if (! $profile->access_token) {
            return response()->json(['status' => 422, 'text' => 'Access token Meta belum diisi.'], 422);
        }

        try {
            $metaUser = $metaSync->client($profile)->validateToken();
        } catch (MetaAdsException $exception) {
            return response()->json(['status' => 422, 'text' => $exception->getMessage()], 422);
        }

        return response()->json(['status' => 200, 'text' => 'Terhubung sebagai '.($metaUser['name'] ?? Auth::user()->name)]);
    }

    public function checkSelectedAccount(): JsonResponse
    {
        return response()->json(['status' => 200, 'text' => 'Data Valid', 'max_account' => 30, 'jumlah_akun_dipilih' => AdAccount::count()]);
    }

    public function automationTasks(Request $request): JsonResponse
    {
        $tasks = AutomationTask::with(['adAccount', 'campaign'])
            ->when($request->query('acc') && $request->query('acc') !== 'all', fn ($query) => $query->whereHas('adAccount', fn ($account) => $account->where('external_id', $request->query('acc'))))
            ->when($request->query('level') && $request->query('level') !== 'all', fn ($query) => $query->where('level', $request->query('level')))
            ->when($request->query('funnel') && $request->query('funnel') !== 'all', fn ($query) => $query->where('event_flow', $request->query('funnel')))
            ->latest()
            ->get()
            ->map(fn (AutomationTask $task) => $this->taskPayload($task))
            ->values();

        return response()->json(['data' => $tasks]);
    }

    public function createAutomationTask(Request $request): JsonResponse
    {
        $account = AdAccount::where('external_id', $request->input('ad_account'))->first() ?? AdAccount::first();
        $campaign = Campaign::where('external_id', $request->input('campaign_id'))->first() ?? Campaign::first();

        $task = AutomationTask::create($this->automationPayload($request) + [
            'id' => (string) str()->uuid(),
            'ad_account_id' => $account?->id,
            'campaign_id' => $campaign?->id,
            'campaign_external_id' => $campaign?->external_id,
            'campaign_name' => $campaign?->name ?? $request->input('campaign_name', 'Selected Campaign'),
            'ad_account_name' => $account?->name ?? $request->input('ad_account_name', 'Selected Account'),
            'current_budget' => (int) $request->input('starting_budget', 100000),
            'current_spend' => 0,
            'current_result' => 0,
            'is_active' => true,
            'level' => 'campaign',
            'last_log' => 'Automation budget berhasil dibuat',
            'last_checked_at' => now(),
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => ['Automation budget berhasil dibuat', 'BOT siap membaca metrik campaign'],
        ]);

        return response()->json(['status' => 200, 'text' => 'Automation budget berhasil dibuat', 'data' => $this->taskPayload($task->load(['adAccount', 'campaign']))]);
    }

    public function updateAutomationTask(Request $request): JsonResponse
    {
        $task = AutomationTask::findOrFail($request->input('automation_id'));
        $task->update($this->automationPayload($request) + [
            'current_budget' => (int) $request->input('starting_budget', $task->current_budget),
            'last_log' => 'Automation strategy berhasil diupdate',
            'last_checked_at' => now(),
            'is_active' => $request->input('automation_activation', 'active') === 'active',
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => ['Automation strategy berhasil diupdate'],
        ]);

        return response()->json(['status' => 200, 'text' => 'Automation strategy berhasil diupdate']);
    }

    public function updateStatusAutomation(Request $request, MetaAdsSyncService $metaSync): JsonResponse
    {
        $task = AutomationTask::findOrFail($request->input('automation_id'));
        $isActive = $request->input('status', 'true') === 'true';
        $metaLog = $this->pushMetaStatus($task, $isActive, $metaSync);

        $task->update([
            'is_active' => $isActive,
            'last_log' => 'Status automation berhasil diperbarui'.$metaLog,
            'last_checked_at' => now(),
        ]);
        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => ['Status automation berhasil diperbarui'.$metaLog],
        ]);

        return response()->json(['status' => 200, 'text' => 'Status automation berhasil diperbarui']);
    }

    public function specificTask(Request $request): JsonResponse
    {
        $task = AutomationTask::with(['adAccount', 'campaign'])->find($request->query('automation_id'));

        return response()->json(['status' => 200, 'data' => $task ? $this->taskPayload($task) : null]);
    }

    public function historyLog(Request $request): JsonResponse
    {
        $logs = AutomationLog::where('automation_task_id', $request->query('task_id'))->latest()->limit(10)->get();

        return response()->json([
            'status' => 200,
            'data' => $logs->map(fn (AutomationLog $log) => [
                'time' => $log->created_at->timezone('Asia/Jakarta')->format('d-m-Y, H:i'),
                'text' => $log->messages,
            ])->values(),
        ]);
    }

    public function turunBudget(Request $request, MetaAdsSyncService $metaSync): JsonResponse
    {
        $task = AutomationTask::findOrFail($request->input('automation_id'));
        $metaLog = $this->pushMetaBudget($task, (int) $task->starting_budget, $metaSync);

        $task->update([
            'current_budget' => $task->starting_budget,
            'last_log' => 'Menurunkan budget manual berhasil'.$metaLog,
            'last_checked_at' => now(),
        ]);
        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => ['Menurunkan budget manual berhasil'.$metaLog],
        ]);

        return response()->json(['status' => 200, 'text' => 'Budget berhasil diturunkan manual']);
    }

    public function getInterest(Request $request): JsonResponse
    {
        $keyword = (string) $request->query('keyword');
        $interests = Interest::query()
            ->when($keyword !== '', fn ($query) => $query->where('name', 'like', "%{$keyword}%")->orWhere('keyword', 'like', "%{$keyword}%"))
            ->limit(100)
            ->get()
            ->map(fn (Interest $interest) => [
                'id' => $interest->external_id,
                'name' => $interest->name,
                'audience_size_lower_bound' => $interest->audience_size_lower_bound,
                'audience_size_upper_bound' => $interest->audience_size_upper_bound,
                'path' => $interest->path ?? [],
                'description' => $interest->description,
                'topic' => $interest->topic,
                'keyword' => $keyword,
            ]);

        return response()->json(['status' => 200, 'interest' => $interests]);
    }

    public function getProducts(Request $request): JsonResponse
    {
        return response()->json(['status' => 200, 'produk' => $this->productQuery($request)->get()->map(fn (Product $product) => $this->productPayload($product))->values()]);
    }

    public function getCategoryProducts(Request $request): JsonResponse
    {
        return response()->json(['status' => 200, 'produk' => $this->productQuery($request, $request->query('cat_id'))->get()->map(fn (Product $product) => $this->productPayload($product))->values()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
        ]);

        Auth::user()->update(['name' => trim($data['first_name'].' '.$data['last_name'])]);

        return response()->json(['status' => 200, 'text' => 'Profile berhasil diupdate']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        if (! Hash::check($data['current_password'], Auth::user()->password)) {
            return response()->json(['status' => 422, 'text' => 'Current password tidak valid'], 422);
        }

        Auth::user()->update(['password' => $data['new_password']]);

        return response()->json(['status' => 200, 'text' => 'Password berhasil diupdate']);
    }

    public function saveAccessToken(Request $request): RedirectResponse
    {
        $accessToken = $request->input('access_token_app');
        $appId = $request->input('id_aplikasi');
        $appSecret = $request->input('kunci_rahasia');

        if ($accessToken && $appId && $appSecret) {
            try {
                $accessToken = MetaAdsClient::exchangeLongLivedToken($appId, $appSecret, $accessToken);
            } catch (MetaAdsException $exception) {
                return back()->withErrors(['meta' => $exception->getMessage()]);
            }
        }

        $profile = T4JamProfile::updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'access_token' => $accessToken,
            ]
        );

        if (! $profile->access_token) {
            return back()->with('status', 'Access token berhasil disimpan.');
        }

        SyncMetaAdsProfile::dispatchAfterResponse($profile->id);

        return back()->with('status', 'Access token berhasil disimpan. Sync Meta Ads sedang diproses.');
    }

    public function syncMetaAds(): RedirectResponse
    {
        $profile = $this->currentProfile();

        if (! $profile->access_token) {
            return back()->withErrors(['meta' => 'Access token Meta belum diisi.']);
        }

        SyncMetaAdsProfile::dispatchAfterResponse($profile->id);

        return back()->with('status', 'Sync Meta Ads sedang diproses. Refresh halaman beberapa saat lagi untuk melihat hasil terbaru.');
    }

    private function automationPayload(Request $request): array
    {
        return [
            'event_flow' => $request->input('budget_funnel_lp', 'lp_to_wa'),
            'mode' => $request->input('mode_automation', 'default'),
            'system_flow' => $request->input('hold_spend', 'onhold'),
            'conversion' => $request->input('budget_conversion', 'purchase'),
            'starting_budget' => (int) $request->input('starting_budget', 100000),
            'maximum_budget' => (int) $request->input('maximum_budget', 0),
            'cpr_cap' => (int) $request->input('cpr_cap', 7000),
            'period' => (int) $request->input('period', 10),
            'pause_cpr_cap' => (int) $request->input('pause_cpr_cap', 70000),
            'pause_when_cpr_loss' => $request->boolean('cpr_pause'),
            'counter_cpr' => $request->boolean('counter_cpr'),
            'use_on_off' => $request->boolean('use_on_off'),
            'on_time' => $request->input('on_time', '01:00'),
            'off_time' => $request->input('off_time', '21:00'),
        ];
    }

    private function accountPayload(AdAccount $account): array
    {
        return [
            'account_id' => $account->account_id,
            'id' => $account->external_id,
            'name' => $account->name,
            'currency' => $account->currency,
            'campaigns' => ['data' => $account->campaigns->map(fn (Campaign $campaign) => $this->campaignPayload($campaign))->values()],
        ];
    }

    private function campaignPayload(Campaign $campaign): array
    {
        return [
            'daily_budget' => (string) $campaign->daily_budget,
            'id' => $campaign->external_id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'budget_type' => $campaign->budget_type,
            'ad_id' => $campaign->adAccount?->external_id,
        ];
    }

    private function taskPayload(AutomationTask $task): array
    {
        $result = max(0, (int) $task->current_result);

        return [
            'id' => $task->id,
            'campaign_id' => $task->campaign_external_id,
            'current_budget' => $task->current_budget,
            'current_spend' => $task->current_spend,
            'current_cpr' => $result > 0 ? round($task->current_spend / $result) : $task->current_spend,
            'current_hasil' => $task->current_result,
            'event_flow' => $task->event_flow,
            'system_flow' => $task->system_flow,
            'conversion' => $task->conversion,
            'cpr_cap' => $task->cpr_cap,
            'log' => $task->last_log,
            'status' => $task->is_active ? 'true' : 'false',
            'ad_id' => $task->adAccount?->external_id,
            'ad_account' => $task->ad_account_name,
            'campaign_name' => $task->campaign_name,
            'level' => $task->level,
            'mode' => $task->mode,
            'last_update' => optional($task->last_checked_at ?? $task->updated_at)->timezone('Asia/Jakarta')->format('d-m-Y, H:i'),
            'act_bermasalah' => false,
            'is_reach_limit' => false,
            'limit_time' => false,
            'limit_open' => 0,
            'starting_budget' => $task->starting_budget,
            'maximum_budget' => $task->maximum_budget,
            'pause_cpr_cap' => $task->pause_cpr_cap,
            'period' => $task->period,
            'on_time' => substr((string) $task->on_time, 0, 5),
            'off_time' => substr((string) $task->off_time, 0, 5),
            'cpr_pause' => $task->pause_when_cpr_loss,
            'counter_cpr' => $task->counter_cpr,
            'use_on_off' => $task->use_on_off,
        ];
    }

    private function insightsPayload(): array
    {
        $rows = Campaign::query()->get()->map(function (Campaign $campaign) {
            $result = max(0, $campaign->result);
            $cpr = $result > 0 ? round($campaign->spend / $result) : $campaign->spend;

            return [
                'campaign_id' => $campaign->external_id,
                'campaign_name' => $campaign->name,
                'budget' => $campaign->daily_budget,
                'spend' => $campaign->spend,
                'reach' => $campaign->reach,
                'hasil' => $campaign->result,
                'cpr' => $cpr,
                'link_click' => $campaign->link_click,
                'landing_page_view' => $campaign->landing_page_view,
                'klik_landas' => $campaign->link_click > 0 ? round(($campaign->landing_page_view / $campaign->link_click) * 100, 1) : 0,
                'uang_jangkauan' => $campaign->reach > 0 ? round($campaign->spend / $campaign->reach, 1) : 0,
                'uang_klik' => $campaign->link_click > 0 ? round($campaign->spend / $campaign->link_click, 1) : 0,
                'landas_hasil' => $result > 0 ? round($campaign->landing_page_view / $result, 1) : 0,
                'cpr_10' => round($cpr * 1.1),
            ];
        });

        $sum = fn (string $key) => $rows->sum($key);
        $results = max(1, $sum('hasil'));

        return [
            'summery' => $rows->values(),
            'highlight' => [
                $this->metric('Reach', 'number', 'reach', $sum('reach')),
                $this->metric('Spend', 'currency', 'spend', $sum('spend')),
                $this->metric('Landing Page View', 'number', 'landing_page_view', $sum('landing_page_view')),
                $this->metric('Link Clicks', 'number', 'link_click', $sum('link_click')),
                $this->metric('Hasil (Purchase)', 'number', 'purchase', $sum('hasil')),
                $this->metric('CPR', 'currency', 'cpr', round($sum('spend') / $results)),
                $this->metric('Klik Landas', 'percen', 'klik_landas', round(($sum('landing_page_view') / max(1, $sum('link_click'))) * 100, 1), 70),
                $this->metric('Uang Klik', 'currency', 'uang_klik', round($sum('spend') / max(1, $sum('link_click'))), 190),
                $this->metric('Uang Jangkauan', 'currency', 'uang_jangkauan', round($sum('spend') / max(1, $sum('reach'))), 5),
                $this->metric('Landas Hasil', 'number', 'landas_hasil', round($sum('landing_page_view') / $results, 1)),
            ],
        ];
    }

    private function metric(string $name, string $type, string $key, int|float $value, int|float|null $min = null): array
    {
        return array_filter([
            'name' => $name,
            'data_type' => $type,
            'metric_name' => $key,
            'value' => $value,
            'value_text' => match ($type) {
                'currency' => 'Rp. '.number_format($value, 0, ',', '.').',-',
                'percen' => number_format($value, 1).' %',
                default => number_format($value, 0, ',', '.'),
            },
            'min_value' => $min,
            'instruction' => '',
        ], fn ($value) => $value !== null);
    }

    private function productQuery(Request $request, ?string $categoryId = null)
    {
        return Product::with('category')
            ->when($request->query('keyword'), fn ($query, $keyword) => $query->where('name', 'like', "%{$keyword}%"))
            ->when($categoryId, fn ($query) => $query->whereHas('category', fn ($category) => $category->where('external_id', $categoryId)->orWhere('name', 'like', "%{$categoryId}%")))
            ->when($request->query('min_price'), fn ($query, $min) => $query->where('price', '>=', (int) $min))
            ->when($request->query('max_price'), fn ($query, $max) => $query->where('price', '<=', (int) $max))
            ->when($request->query('min_sold'), fn ($query, $sold) => $query->where('sold', '>=', (int) $sold))
            ->when($request->query('last_added'), fn ($query, $days) => $query->where('last_added_at', '>=', now()->subDays((int) $days)))
            ->orderByDesc('sold')
            ->limit(100);
    }

    private function productPayload(Product $product): array
    {
        return [
            'id' => $product->external_id,
            'name' => $product->name,
            'price' => $product->price,
            'sold' => $product->sold,
            'total_review' => $product->total_review,
            'rating' => $product->rating,
            'category' => $product->category?->name,
            'image' => $product->image_url,
            'detail_url' => $product->detail_url,
        ];
    }

    private function currentProfile(): T4JamProfile
    {
        return T4JamProfile::firstOrCreate(['user_id' => Auth::id()]);
    }

    private function pushMetaBudget(AutomationTask $task, int $budget, MetaAdsSyncService $metaSync): string
    {
        if (! config('services.meta.enable_writes')) {
            return ' (Meta write disabled)';
        }

        try {
            $metaSync->client($this->currentProfile())->updateCampaignBudget($task->campaign_external_id, $budget);

            return ' (Meta updated)';
        } catch (MetaAdsException $exception) {
            return ' (Meta gagal: '.$exception->getMessage().')';
        }
    }

    private function pushMetaStatus(AutomationTask $task, bool $active, MetaAdsSyncService $metaSync): string
    {
        if (! config('services.meta.enable_writes')) {
            return ' (Meta write disabled)';
        }

        try {
            $metaSync->client($this->currentProfile())->updateCampaignStatus($task->campaign_external_id, $active);

            return ' (Meta updated)';
        } catch (MetaAdsException $exception) {
            return ' (Meta gagal: '.$exception->getMessage().')';
        }
    }
}
