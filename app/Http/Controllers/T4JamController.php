<?php

namespace App\Http\Controllers;

use App\Exceptions\MetaAdsException;
use App\Jobs\PushMetaAutomationTaskUpdate;
use App\Jobs\SyncMetaAdsProfile;
use App\Models\AdAccount;
use App\Models\AdSet;
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
        $accounts = AdAccount::with('campaigns.adSets')->get();
        $selectedAccount = session('selected_ad_account', $accounts->first()?->external_id);
        $settings = session('dashboard_settings', []);
        $level = $settings['level_mode'] ?? 'campaign';

        return view('dashboard', [
            'title' => 'Report Dashboard',
            'accounts' => $accounts,
            'selectedAccount' => $selectedAccount,
            'insights' => $this->insightsPayload($selectedAccount, [], $level),
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
        $accounts = AdAccount::with('campaigns.adSets')->get();
        $selected = session('selected_ad_account', $accounts->first()?->external_id);
        $selectedAccount = $accounts->firstWhere('external_id', $selected) ?? $accounts->first();

        return response()->json([
            'status' => 200,
            'adaccount' => $accounts->map(fn (AdAccount $account) => $this->accountPayload($account))->values(),
            'selected' => $selectedAccount?->external_id,
            'selected_campaigns' => $this->normalizeCampaignIds(session('selected_campaigns', [])),
            'fix_campaign_list' => $selectedAccount
                ? $selectedAccount->campaigns->map(fn (Campaign $campaign) => $this->campaignPayload($campaign))->values()
                : collect(),
        ]);
    }

    public function adInsights(Request $request): JsonResponse
    {
        $settings = session('dashboard_settings', []);
        $adAccount = $request->query('ad_account', session('selected_ad_account'));
        $level = $request->query('level', $settings['level_mode'] ?? 'campaign');

        return response()->json($this->insightsPayload($adAccount, $this->normalizeCampaignIds(session('selected_campaigns', [])), $level));
    }

    public function changeAdAccount(Request $request): JsonResponse
    {
        session(['selected_ad_account' => $request->input('ad_account')]);
        session()->forget('selected_campaigns');

        return response()->json(['status' => 200, 'text' => 'Ad account berhasil dipilih']);
    }

    public function changeSelectedCampaign(Request $request): JsonResponse
    {
        session(['selected_campaigns' => $this->normalizeCampaignIds($request->input('campaigns', []))]);

        return response()->json(['status' => 200, 'text' => 'Campaign berhasil diperbarui']);
    }

    public function changeSettings(Request $request): JsonResponse
    {
        session(['dashboard_settings' => $request->only(['funnel_lp', 'conversion', 'level_mode'])]);

        return response()->json(['status' => 200, 'text' => 'Settings dashboard tersimpan']);
    }

    public function reloadAdAccount(): JsonResponse
    {
        $profile = $this->currentProfile();
        $message = 'Dashboard direfresh dari data terakhir di database.';

        if ($profile->access_token) {
            $profile->update(['last_meta_error' => null]);
            SyncMetaAdsProfile::dispatch($profile->id)->afterCommit();
            $message = 'Sync Meta Ads masuk antrean queue. Data dashboard akan berubah setelah worker selesai.';
        }

        $accounts = AdAccount::with('campaigns.adSets')->get();

        return response()->json([
            'status' => 200,
            'text' => $message,
            'adaccount' => $accounts->map(fn (AdAccount $account) => $this->accountPayload($account))->values(),
            'ad_account_count' => $accounts->count(),
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
        $account = AdAccount::where('external_id', $request->input('ad_account'))->first();

        if (! $account) {
            return response()->json(['status' => 422, 'text' => 'Pilih ad account yang valid dulu.'], 422);
        }

        $level = $request->input('level') === 'adset' ? 'adset' : 'campaign';
        $adSet = null;
        $campaign = null;

        if ($level === 'adset') {
            $adSet = $account->adSets()->with('campaign')->where('external_id', $request->input('campaign_id'))->first();
            $campaign = $adSet?->campaign;

            if (! $adSet || ! $campaign) {
                return response()->json(['status' => 422, 'text' => 'Pilih ad set dari ad account yang aktif dulu.'], 422);
            }
        } else {
            $campaign = $account->campaigns()->where('external_id', $request->input('campaign_id'))->first();

            if (! $campaign) {
                return response()->json(['status' => 422, 'text' => 'Pilih campaign dari ad account yang aktif dulu.'], 422);
            }
        }

        $budget = max(1000, (int) $request->input('starting_budget', 100000));

        if ($budget < 1000) {
            return response()->json(['status' => 422, 'text' => 'Budget minimal adalah Rp. 1.000,-.'], 422);
        }

        $metaQueue = $this->metaWriteReadiness('Budget belum dikirim ke Meta karena write mode belum aktif.');
        if (! $metaQueue['ok']) {
            return response()->json(['status' => 422, 'text' => $metaQueue['text']], 422);
        }

        $this->persistLocalBudget($adSet ?? $campaign, $budget, $level);
        $baseMessage = 'Automation budget berhasil dibuat';

        $task = AutomationTask::create($this->automationPayload($request) + [
            'id' => (string) str()->uuid(),
            'ad_account_id' => $account->id,
            'campaign_id' => $campaign->id,
            'ad_set_id' => $adSet?->id,
            'campaign_external_id' => $campaign->external_id,
            'ad_set_external_id' => $adSet?->external_id,
            'campaign_name' => $adSet?->name ?? $campaign->name,
            'ad_account_name' => $account->name,
            'current_budget' => $budget,
            'current_spend' => 0,
            'current_result' => 0,
            'is_active' => true,
            'level' => $level,
            'last_log' => $baseMessage.'; update Meta masuk antrean queue',
            'last_checked_at' => now(),
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$baseMessage.'; update Meta masuk antrean queue', 'BOT siap membaca metrik campaign'],
        ]);

        $this->queueMetaAutomationTask($metaQueue['profile_id'], $task, 'budget', $baseMessage, $budget);

        return response()->json(['status' => 200, 'text' => 'Automation budget berhasil dibuat. Update budget Meta masuk antrean queue.', 'data' => $this->taskPayload($task->load(['adAccount', 'campaign']))]);
    }

    public function updateAutomationTask(Request $request): JsonResponse
    {
        $task = AutomationTask::with(['campaign', 'adSet'])->findOrFail($request->input('automation_id'));
        $budget = max(1000, (int) $request->input('starting_budget', $task->current_budget));

        if ($budget < 1000) {
            return response()->json(['status' => 422, 'text' => 'Budget minimal adalah Rp. 1.000,-.'], 422);
        }

        $target = $task->level === 'adset'
            ? ($task->adSet ?? $task->ad_set_external_id)
            : ($task->campaign ?? $task->campaign_external_id);
        $metaQueue = $this->metaWriteReadiness('Budget belum dikirim ke Meta karena write mode belum aktif.');
        if (! $metaQueue['ok']) {
            return response()->json(['status' => 422, 'text' => $metaQueue['text']], 422);
        }

        $this->persistLocalBudget($target, $budget, $task->level);
        $baseMessage = 'Automation strategy berhasil diupdate';

        $task->update($this->automationPayload($request) + [
            'current_budget' => $budget,
            'last_log' => $baseMessage.'; update Meta masuk antrean queue',
            'last_checked_at' => now(),
            'is_active' => $request->input('automation_activation', 'active') === 'active',
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$baseMessage.'; update Meta masuk antrean queue'],
        ]);

        $this->queueMetaAutomationTask($metaQueue['profile_id'], $task, 'budget', $baseMessage, $budget);

        return response()->json(['status' => 200, 'text' => 'Automation strategy berhasil diupdate. Update budget Meta masuk antrean queue.']);
    }

    public function updateStatusAutomation(Request $request): JsonResponse
    {
        $task = AutomationTask::with(['campaign', 'adSet'])->findOrFail($request->input('automation_id'));
        $isActive = $request->input('status', 'true') === 'true';
        $metaQueue = $this->metaWriteReadiness('Status belum dikirim ke Meta karena write mode belum aktif.');
        if (! $metaQueue['ok']) {
            return response()->json(['status' => 422, 'text' => $metaQueue['text']], 422);
        }

        $baseMessage = 'Status automation berhasil diperbarui';

        $task->update([
            'is_active' => $isActive,
            'last_log' => $baseMessage.'; update Meta masuk antrean queue',
            'last_checked_at' => now(),
        ]);
        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$baseMessage.'; update Meta masuk antrean queue'],
        ]);

        $this->queueMetaAutomationTask($metaQueue['profile_id'], $task, 'status', $baseMessage, null, $isActive);

        return response()->json(['status' => 200, 'text' => 'Status automation berhasil diperbarui. Update status Meta masuk antrean queue.']);
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

    public function turunBudget(Request $request): JsonResponse
    {
        $task = AutomationTask::with(['campaign', 'adSet'])->findOrFail($request->input('automation_id'));
        $target = $task->level === 'adset'
            ? ($task->adSet ?? $task->ad_set_external_id)
            : ($task->campaign ?? $task->campaign_external_id);
        $budget = max(1000, (int) $task->starting_budget);

        if ($budget < 1000) {
            return response()->json(['status' => 422, 'text' => 'Budget minimal adalah Rp. 1.000,-.'], 422);
        }

        $metaQueue = $this->metaWriteReadiness('Budget belum dikirim ke Meta karena write mode belum aktif.');
        if (! $metaQueue['ok']) {
            return response()->json(['status' => 422, 'text' => $metaQueue['text']], 422);
        }

        $this->persistLocalBudget($target, $budget, $task->level);
        $baseMessage = 'Menurunkan budget manual berhasil';

        $task->update([
            'current_budget' => $task->starting_budget,
            'last_log' => $baseMessage.'; update Meta masuk antrean queue',
            'last_checked_at' => now(),
        ]);
        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$baseMessage.'; update Meta masuk antrean queue'],
        ]);

        $this->queueMetaAutomationTask($metaQueue['profile_id'], $task, 'budget', $baseMessage, $budget);

        return response()->json(['status' => 200, 'text' => 'Budget berhasil diturunkan manual. Update budget Meta masuk antrean queue.']);
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
                'last_meta_error' => null,
            ]
        );

        if (! $profile->access_token) {
            return back()->with('status', 'Access token berhasil disimpan.');
        }

        return back()->with('status', 'Access token berhasil disimpan. Klik tombol Sync Meta Ads untuk menyinkronkan data.');
    }

    public function syncMetaAds(): RedirectResponse
    {
        $profile = $this->currentProfile();

        if (! $profile->access_token) {
            return back()->withErrors(['meta' => 'Access token Meta belum diisi.']);
        }

        $profile->update(['last_meta_error' => null]);

        SyncMetaAdsProfile::dispatch($profile->id)->afterCommit();

        return back()->with('status', 'Sync Meta Ads masuk antrean queue. Worker akan memproses data di background.');
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
            'adsets' => ['data' => $account->adSets->map(fn (AdSet $adSet) => $this->adSetPayload($adSet))->values()],
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
            'level' => 'campaign',
            'adsets' => ['data' => $campaign->adSets->map(fn (AdSet $adSet) => $this->adSetPayload($adSet))->values()],
        ];
    }

    private function adSetPayload(AdSet $adSet): array
    {
        return [
            'daily_budget' => (string) $adSet->daily_budget,
            'id' => $adSet->external_id,
            'name' => $adSet->name,
            'status' => $adSet->status,
            'budget_type' => 'adset',
            'ad_id' => $adSet->adAccount?->external_id,
            'campaign_id' => $adSet->campaign?->external_id,
            'campaign_name' => $adSet->campaign?->name,
            'level' => 'adset',
        ];
    }

    private function taskPayload(AutomationTask $task): array
    {
        $result = max(0, (int) $task->current_result);

        return [
            'id' => $task->id,
            'campaign_id' => $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id,
            'parent_campaign_id' => $task->campaign_external_id,
            'adset_id' => $task->ad_set_external_id,
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

    private function metaWriteReadiness(string $disabledMessage): array
    {
        if (! config('services.meta.enable_writes')) {
            return ['ok' => false, 'text' => $disabledMessage];
        }

        $profile = $this->currentProfile();

        if (! $profile->access_token) {
            return ['ok' => false, 'text' => 'Access token Meta belum diisi.'];
        }

        return ['ok' => true, 'profile_id' => $profile->id];
    }

    private function persistLocalBudget(Campaign|AdSet|string|null $target, int $budget, string $level): void
    {
        if ($target instanceof Campaign || $target instanceof AdSet) {
            $target->update(['daily_budget' => $budget]);

            return;
        }

        if (! is_string($target) || $target === '') {
            return;
        }

        if ($level === 'adset') {
            AdSet::query()->where('external_id', $target)->update(['daily_budget' => $budget]);
        } else {
            Campaign::query()->where('external_id', $target)->update(['daily_budget' => $budget]);
        }
    }

    private function queueMetaAutomationTask(int $profileId, AutomationTask $task, string $action, string $baseMessage, ?int $budget = null, ?bool $active = null): void
    {
        PushMetaAutomationTaskUpdate::dispatch(
            $profileId,
            $task->id,
            $action,
            $baseMessage,
            $budget,
            $active,
        )->afterCommit();
    }

    private function insightsPayload(?string $adAccountExternalId = null, array $selectedCampaigns = [], string $level = 'campaign'): array
    {
        $query = $level === 'adset' ? AdSet::query() : Campaign::query();
        $rows = $query
            ->when($adAccountExternalId, fn ($query) => $query->whereHas('adAccount', fn ($account) => $account->where('external_id', $adAccountExternalId)))
            ->when($selectedCampaigns !== [], fn ($query) => $query->whereIn('external_id', $selectedCampaigns))
            ->get()
            ->map(fn (Campaign|AdSet $item) => $this->insightRow($item, $level));

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

    private function insightRow(Campaign|AdSet $item, string $level): array
    {
        $result = max(0, $item->result);
        $cpr = $result > 0 ? round($item->spend / $result) : $item->spend;

        return [
            'campaign_id' => $item->external_id,
            'campaign_name' => $item->name,
            'parent_campaign_id' => $item instanceof AdSet ? $item->campaign?->external_id : $item->external_id,
            'level' => $level,
            'budget' => $item->daily_budget,
            'spend' => $item->spend,
            'reach' => $item->reach,
            'hasil' => $item->result,
            'cpr' => $cpr,
            'link_click' => $item->link_click,
            'landing_page_view' => $item->landing_page_view,
            'klik_landas' => $item->link_click > 0 ? round(($item->landing_page_view / $item->link_click) * 100, 1) : 0,
            'uang_jangkauan' => $item->reach > 0 ? round($item->spend / $item->reach, 1) : 0,
            'uang_klik' => $item->link_click > 0 ? round($item->spend / $item->link_click, 1) : 0,
            'landas_hasil' => $result > 0 ? round($item->landing_page_view / $result, 1) : 0,
            'cpr_10' => round($cpr * 1.1),
        ];
    }

    private function normalizeCampaignIds(mixed $campaigns): array
    {
        if (is_string($campaigns)) {
            $campaigns = explode(',', $campaigns);
        }

        if (! is_array($campaigns)) {
            return [];
        }

        return collect($campaigns)
            ->flatten()
            ->map(fn ($campaign) => trim((string) $campaign))
            ->filter()
            ->unique()
            ->values()
            ->all();
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

}
