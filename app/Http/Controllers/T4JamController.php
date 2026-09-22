<?php

namespace App\Http\Controllers;

use App\Exceptions\MetaAdsException;
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
use App\Services\AutomationBudgetService;
use App\Services\MetaAdsClient;
use App\Services\MetaAdsSyncService;
use App\Support\MetaFlowLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class T4JamController extends Controller
{
    private const NON_EDITABLE_META_STATUSES = ['ARCHIVED', 'DELETED'];

    public function root(): RedirectResponse
    {
        return redirect('/dashboard/');
    }

    public function dashboard(): View
    {
        $accounts = $this->newestAdAccountsWithChildren()->get();
        $selectedAccount = session('selected_ad_account', $accounts->first()?->external_id);
        $settings = session('dashboard_settings', []);
        $level = $settings['level_mode'] ?? 'campaign';

        return view('dashboard', [
            'title' => 'Report Dashboard',
            'accounts' => $accounts,
            'selectedAccount' => $selectedAccount,
            'insights' => $this->insightsPayload($selectedAccount, [], $level, $settings['conversion'] ?? 'purchase'),
        ]);
    }

    public function automation(): View
    {
        return view('automation', [
            'title' => 'Automation Budget Strategy',
            'accounts' => $this->newestAdAccounts()->get(),
            'tasks' => AutomationTask::where('user_id', Auth::id())->with(['adAccount', 'campaign', 'adSet'])->latest()->get(),
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
            'categories' => ProductCategory::query()->latest('updated_at')->latest('id')->get(),
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
        $accounts = $this->newestAdAccountsWithChildren()->get();
        $selected = session('selected_ad_account', $accounts->first()?->external_id);
        $selectedAccount = $accounts->firstWhere('external_id', $selected) ?? $accounts->first();

        return response()->json([
            'status' => 200,
            'adaccount' => $accounts->map(fn (AdAccount $account) => $this->accountPayload($account))->values(),
            'ad_account_count' => $accounts->count(),
            'selected' => $selectedAccount?->external_id,
            'selected_campaigns' => $this->normalizeCampaignIds(session('selected_campaigns', [])),
            'fix_campaign_list' => $selectedAccount
                ? $selectedAccount->campaigns->filter(fn (Campaign $campaign) => $this->editableMetaTarget($campaign))->map(fn (Campaign $campaign) => $this->campaignPayload($campaign))->values()
                : collect(),
        ]);
    }

    public function adInsights(Request $request): JsonResponse
    {
        $settings = session('dashboard_settings', []);
        $adAccount = $request->query('ad_account', session('selected_ad_account'));
        $level = $request->query('level', $settings['level_mode'] ?? 'campaign');

        $request->validate(['conversion' => ['sometimes', Rule::in(AutomationBudgetService::conversions())]]);
        $conversion = $request->query('conversion', $settings['conversion'] ?? 'purchase');

        return response()->json($this->insightsPayload($adAccount, $this->normalizeCampaignIds(session('selected_campaigns', [])), $level, $conversion));
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
        $request->validate(['conversion' => ['sometimes', Rule::in(AutomationBudgetService::conversions())]]);
        session(['dashboard_settings' => $request->only(['funnel_lp', 'conversion', 'level_mode'])]);

        return response()->json(['status' => 200, 'text' => 'Settings dashboard tersimpan']);
    }

    public function reloadAdAccount(
        Request $request,
        MetaAdsSyncService $metaSync
    ): JsonResponse {
        $profile = $this->metaCredentialProfile();

        if (! $profile->hasAccessToken()) {
            MetaFlowLog::warning('reload rejected without access token', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
            ]);

            return response()->json([
                'status' => 422,
                'text' => 'Access token Meta belum diisi.',
            ], 422);
        }

        $adAccountExternalId = $request->input(
            'ad_account',
            session('selected_ad_account')
        );

        if (! $adAccountExternalId) {
            return response()->json([
                'status' => 422,
                'text' => 'Pilih ad account terlebih dahulu.',
            ], 422);
        }

        MetaFlowLog::info('reload requested', [
            'user_id' => Auth::id(),
            'profile_id' => $profile->id,
            'ad_account_id' => $adAccountExternalId,
        ]);

        try {
            $counts = $metaSync->syncCampaignsForAccount(
                $profile,
                $adAccountExternalId
            );
        } catch (MetaAdsException $exception) {
            $profile->update([
                'last_meta_error' => $exception->getMessage(),
            ]);

            MetaFlowLog::warning('reload failed', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
                'ad_account_id' => $adAccountExternalId,
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);

            return response()->json([
                'status' => 422,
                'text' => $exception->getMessage(),
            ], 422);
        }

        $accounts = $this->newestAdAccountsWithChildren()->get();
        MetaFlowLog::info('reload finished', [
            'user_id' => Auth::id(),
            'profile_id' => $profile->id,
            'ad_account_id' => $adAccountExternalId,
            'campaigns' => $counts['campaigns'],
        ]);

        return response()->json([
            'status' => 200,
            'text' => sprintf(
                'Reload selesai. %d campaign diperbarui.',
                $counts['campaigns']
            ),
            'adaccount' => $accounts
                ->map(fn (AdAccount $account) => $this->accountPayload($account)
                )
                ->values(),
            'ad_account_count' => $accounts->count(),
        ]);
    }

    public function checkConnection(MetaAdsSyncService $metaSync): JsonResponse
    {
        $profile = $this->metaCredentialProfile();

        if (! $profile->hasAccessToken()) {
            MetaFlowLog::warning('connection check rejected without access token', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
            ]);

            return response()->json(['status' => 422, 'text' => 'Access token Meta belum diisi.'], 422);
        }

        try {
            $metaUser = $metaSync->client($profile)->validateToken();
        } catch (MetaAdsException $exception) {
            MetaFlowLog::warning('connection check failed', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);

            return response()->json(['status' => 422, 'text' => $exception->getMessage()], 422);
        }

        MetaFlowLog::info('connection check finished', [
            'user_id' => Auth::id(),
            'profile_id' => $profile->id,
            'meta_user_id' => $metaUser['id'] ?? null,
        ]);

        return response()->json(['status' => 200, 'text' => 'Terhubung sebagai '.($metaUser['name'] ?? Auth::user()->name)]);
    }

    public function checkSelectedAccount(): JsonResponse
    {
        return response()->json(['status' => 200, 'text' => 'Data Valid', 'max_account' => 30, 'jumlah_akun_dipilih' => AdAccount::count()]);
    }

    public function automationTasks(
        Request $request,
        AutomationBudgetService $automation,
        MetaAdsSyncService $metaSync
    ): JsonResponse {
        $tasks = AutomationTask::where('user_id', Auth::id())->with(['adAccount', 'campaign', 'adSet'])
            ->when($request->query('acc') && $request->query('acc') !== 'all', fn ($query) => $query->whereHas('adAccount', fn ($account) => $account->where('external_id', $request->query('acc'))))
            ->when($request->query('level') && $request->query('level') !== 'all', fn ($query) => $query->where('level', $request->query('level')))
            ->when($request->query('funnel') && $request->query('funnel') !== 'all', fn ($query) => $query->where('event_flow', $request->query('funnel')))
            ->latest()
            ->get();

        $sync = $this->refreshAutomationDisplayMetrics($request, $tasks, $automation, $metaSync);

        $tasks = $tasks
            ->fresh(['adAccount', 'campaign', 'adSet'])
            ->map(fn (AutomationTask $task) => $this->taskPayload($task))
            ->values();

        return response()->json(['data' => $tasks, 'meta_sync' => $sync]);
    }

    public function createAutomationTask(Request $request, MetaAdsSyncService $metaSync): JsonResponse
    {
        $this->validateAutomation($request);
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

            if (! $this->editableMetaTarget($adSet)) {
                return response()->json(['status' => 422, 'text' => 'Ad set ini sudah dihapus/diarsipkan di Meta. Klik Reload lalu pilih ad set aktif.'], 422);
            }
        } else {
            $campaign = $account->campaigns()->where('external_id', $request->input('campaign_id'))->first();

            if (! $campaign) {
                return response()->json(['status' => 422, 'text' => 'Pilih campaign dari ad account yang aktif dulu.'], 422);
            }

            if (! $this->editableMetaTarget($campaign)) {
                return response()->json(['status' => 422, 'text' => 'Campaign ini sudah dihapus/diarsipkan di Meta. Klik Reload lalu pilih campaign aktif.'], 422);
            }
        }

        $budget = max(1000, (int) $request->input('starting_budget', 100000));

        if ($budget < 1000) {
            return response()->json(['status' => 422, 'text' => 'Budget minimal adalah Rp. 1.000,-.'], 422);
        }

        $metaResult = $this->pushMetaBudget($adSet ?? $campaign, $budget, $level, $metaSync);
        if (! $metaResult['ok']) {
            return response()->json(['status' => 422, 'text' => $metaResult['text']], 422);
        }

        $baseMessage = 'Automation budget berhasil dibuat';
        $successMessage = $baseMessage.'; Meta berhasil diupdate.';

        $task = DB::transaction(function () use ($request, $account, $campaign, $adSet, $budget, $level, $successMessage): AutomationTask {
            $this->persistLocalBudget($adSet ?? $campaign, $budget, $level);

            $task = AutomationTask::create($this->automationPayload($request) + [
                'id' => (string) str()->uuid(),
                'user_id' => Auth::id(),
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
                'is_active' => $this->automationActive($request),
                'level' => $level,
                'last_log' => $successMessage,
                'last_checked_at' => null,
                'last_budget_changed_at' => now(),
                'last_budget_action' => 'baseline',
            ]);

            AutomationLog::create([
                'automation_task_id' => $task->id,
                'messages' => [$successMessage, 'BOT siap membaca metrik campaign'],
            ]);

            return $task;
        });

        return response()->json(['status' => 200, 'text' => 'Automation budget berhasil dibuat dan budget Meta berhasil diupdate.', 'data' => $this->taskPayload($task->load(['adAccount', 'campaign', 'adSet']))]);
    }

    public function updateAutomationTask(Request $request, MetaAdsSyncService $metaSync): JsonResponse
    {
        $this->validateAutomation($request);
        $task = AutomationTask::where('user_id', Auth::id())->with(['campaign', 'adSet'])->findOrFail($request->input('automation_id'));
        $budget = max(1000, (int) $request->input('starting_budget', $task->starting_budget));

        if ($budget < 1000) {
            return response()->json(['status' => 422, 'text' => 'Budget minimal adalah Rp. 1.000,-.'], 422);
        }

        $budgetChanged = $budget !== (int) $task->starting_budget;
        $requestedActive = $this->automationActive($request);
        $statusChanged = $requestedActive !== (bool) $task->is_active;
        $baseMessage = 'Automation strategy berhasil diupdate';
        $metaBudgetPushed = false;
        $metaStatusPushed = false;
        $target = $task->level === 'adset'
            ? ($task->adSet ?? $task->ad_set_external_id)
            : ($task->campaign ?? $task->campaign_external_id);

        if ($budgetChanged || $this->shouldRefreshMetaBudget()) {
            $metaResult = $this->pushMetaBudget($target, $budget, $task->level, $metaSync);
            if (! $metaResult['ok']) {
                if ($budgetChanged) {
                    return response()->json(['status' => 422, 'text' => $metaResult['text']], 422);
                }
            } else {
                $metaBudgetPushed = true;
            }
        }

        if ($statusChanged) {
            $metaResult = $this->pushMetaStatus($task, $requestedActive, $metaSync);
            if (! $metaResult['ok']) {
                if ($budgetChanged && $metaBudgetPushed) {
                    DB::transaction(function () use ($task, $target, $budget): void {
                        $this->persistLocalBudget($target, $budget, $task->level);
                        $task->update([
                            'starting_budget' => $budget,
                            'current_budget' => $budget,
                            'last_budget_changed_at' => now(),
                            'last_budget_before' => $task->current_budget,
                            'last_budget_action' => 'manual',
                            'last_log' => 'Budget Meta berhasil diupdate, tetapi perubahan status gagal.',
                        ]);
                    });
                }

                return response()->json(['status' => 422, 'text' => $metaResult['text']], 422);
            }

            $metaStatusPushed = true;
        }

        $metaPushed = $metaBudgetPushed || $metaStatusPushed;
        $logMessage = $metaPushed ? $baseMessage.'; Meta berhasil diupdate.' : $baseMessage;

        DB::transaction(function () use ($request, $task, $target, $logMessage, $budgetChanged, $budget, $requestedActive, $statusChanged): void {
            if ($budgetChanged) {
                $this->persistLocalBudget($target, $budget, $task->level);
            }

            if ($statusChanged && ($target instanceof Campaign || $target instanceof AdSet)) {
                $target->update([
                    'status' => $requestedActive ? 'ACTIVE' : 'PAUSED',
                    'effective_status' => $requestedActive ? 'ACTIVE' : 'PAUSED',
                ]);
            }

            $taskData = $this->automationPayload($request) + [
                'last_log' => $logMessage,
                'last_checked_at' => null,
                'last_budget_action' => 'manual',
                'is_active' => $requestedActive,
            ] + ($budgetChanged ? [
                'current_budget' => $budget,
                'last_budget_changed_at' => now(),
                'last_budget_before' => $task->current_budget,
                'last_budget_action' => 'manual',
            ] : []);

            if (($taskData['conversion'] ?? $task->conversion) !== $task->conversion) {
                $taskData['current_result'] = 0;
                $taskData['current_spend'] = 0;
            }
            $task->update($taskData);

            AutomationLog::create([
                'automation_task_id' => $task->id,
                'messages' => [$logMessage],
            ]);
        });

        return response()->json(['status' => 200, 'text' => $metaPushed ? 'Automation strategy berhasil diupdate dan budget Meta berhasil diupdate.' : 'Automation strategy berhasil diupdate.']);
    }

    public function updateStatusAutomation(Request $request, MetaAdsSyncService $metaSync): JsonResponse
    {
        $task = AutomationTask::where('user_id', Auth::id())->with(['campaign', 'adSet'])->findOrFail($request->input('automation_id'));
        $isActive = $request->input('status', 'true') === 'true';
        $metaResult = $this->pushMetaStatus($task, $isActive, $metaSync);
        if (! $metaResult['ok']) {
            return response()->json(['status' => 422, 'text' => $metaResult['text']], 422);
        }

        $baseMessage = 'Status automation berhasil diperbarui';
        $successMessage = $baseMessage.'; Meta berhasil diupdate.';

        DB::transaction(function () use ($task, $isActive, $successMessage): void {
            $target = $this->taskMetricTarget($task);
            $target?->update(['status' => $isActive ? 'ACTIVE' : 'PAUSED', 'effective_status' => $isActive ? 'ACTIVE' : 'PAUSED']);
            $task->update([
                'is_active' => $isActive,
                'last_log' => $successMessage,
                'last_checked_at' => $isActive ? null : now(),
                'last_budget_action' => 'manual',
            ]);
            AutomationLog::create([
                'automation_task_id' => $task->id,
                'messages' => [$successMessage],
            ]);
        });

        return response()->json(['status' => 200, 'text' => 'Status automation berhasil diperbarui dan Meta berhasil diupdate.']);
    }

    public function deleteAutomationTask(Request $request): JsonResponse
    {
        $task = AutomationTask::where('user_id', Auth::id())->findOrFail($request->input('automation_id'));
        $taskName = $task->campaign_name;
        $task->delete();

        MetaFlowLog::info('automation task deleted', [
            'user_id' => Auth::id(),
            'automation_task_id' => $request->input('automation_id'),
            'target_id' => $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id,
            'level' => $task->level,
        ]);

        return response()->json([
            'status' => 200,
            'text' => 'Automation budget '.$taskName.' berhasil dihapus dari tools.',
        ]);
    }

    public function specificTask(Request $request): JsonResponse
    {
        $task = AutomationTask::where('user_id', Auth::id())->with(['adAccount', 'campaign', 'adSet'])->find($request->query('automation_id'));

        return response()->json(['status' => 200, 'data' => $task ? $this->taskPayload($task) : null]);
    }

    public function historyLog(Request $request): JsonResponse
    {
        AutomationTask::where('user_id', Auth::id())->findOrFail($request->query('task_id'));
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
        $task = AutomationTask::where('user_id', Auth::id())->with(['campaign', 'adSet'])->findOrFail($request->input('automation_id'));
        $target = $task->level === 'adset'
            ? ($task->adSet ?? $task->ad_set_external_id)
            : ($task->campaign ?? $task->campaign_external_id);
        $budget = max(1000, (int) $task->starting_budget);

        if ($budget < 1000) {
            return response()->json(['status' => 422, 'text' => 'Budget minimal adalah Rp. 1.000,-.'], 422);
        }

        $metaResult = $this->pushMetaBudget($target, $budget, $task->level, $metaSync);
        if (! $metaResult['ok']) {
            return response()->json(['status' => 422, 'text' => $metaResult['text']], 422);
        }

        $baseMessage = 'Menurunkan budget manual berhasil';
        $successMessage = $baseMessage.'; Meta berhasil diupdate.';

        DB::transaction(function () use ($target, $budget, $task, $successMessage): void {
            $this->persistLocalBudget($target, $budget, $task->level);

            $task->update([
                'current_budget' => $task->starting_budget,
                'last_log' => $successMessage,
                'last_checked_at' => now(),
                'last_budget_changed_at' => now(),
                'last_budget_before' => $task->current_budget,
                'last_budget_action' => 'manual',
            ]);
            AutomationLog::create([
                'automation_task_id' => $task->id,
                'messages' => [$successMessage],
            ]);
        });

        return response()->json(['status' => 200, 'text' => 'Budget berhasil diturunkan manual dan Meta berhasil diupdate.']);
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
        $request->validate([
            'access_token_app' => ['nullable', 'string', 'max:20000'],
            'id_aplikasi' => ['nullable', 'string', 'max:255'],
            'kunci_rahasia' => ['nullable', 'string', 'max:2000'],
        ]);
        $profile = $this->currentProfile();
        $accessToken = $request->filled('access_token_app') ? $request->string('access_token_app')->toString() : $profile->access_token;
        $appId = $request->filled('id_aplikasi') ? $request->string('id_aplikasi')->toString() : $profile->app_id;
        $appSecret = $request->filled('kunci_rahasia') ? $request->string('kunci_rahasia')->toString() : $profile->app_secret;

        $tokenChanged = $request->filled('access_token_app')
            && $request->string('access_token_app')->toString() !== $profile->access_token;

        if ($tokenChanged && $appId && $appSecret) {
            try {
                $accessToken = MetaAdsClient::exchangeLongLivedToken($appId, $appSecret, $accessToken);
            } catch (MetaAdsException $exception) {
                return back()->withErrors(['meta' => $exception->getMessage()]);
            }
        }
        $profile->update([
            'app_id' => $appId,
            'app_secret' => $appSecret,
            'access_token' => $accessToken,
            'last_meta_error' => null,
        ]);

        if (! $profile->access_token) {
            MetaFlowLog::info('access token profile saved without sync queue', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
            ]);

            return back()->with('status', 'Access token berhasil disimpan.');
        }

        SyncMetaAdsProfile::dispatch($profile->id)->afterCommit();
        MetaFlowLog::info('access token saved and full sync queued', [
            'user_id' => Auth::id(),
            'profile_id' => $profile->id,
            'queue' => 'meta',
        ]);

        return back()->with('status', 'Access token tersimpan. Sync Meta Ads masuk antrean queue.');
    }

    public function syncMetaAds(Request $request): JsonResponse|RedirectResponse
    {
        $profile = $this->metaCredentialProfile();

        if (! $profile->hasAccessToken()) {
            MetaFlowLog::warning('manual full sync rejected without access token', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
            ]);

            if ($request->expectsJson()) {
                return response()->json(['status' => 422, 'text' => 'Access token Meta belum diisi.'], 422);
            }

            return back()->withErrors(['meta' => 'Access token Meta belum diisi.']);
        }

        SyncMetaAdsProfile::dispatch($profile->id)->afterCommit();
        MetaFlowLog::info('manual full sync queued', [
            'user_id' => Auth::id(),
            'profile_id' => $profile->id,
            'queue' => 'meta',
        ]);

        $message = 'Sync Meta Ads masuk antrean queue.';

        if ($request->expectsJson()) {
            return response()->json(['status' => 200, 'text' => $message]);
        }

        return back()->with('status', $message);
    }

    private function syncMetaProfileNow(T4JamProfile $profile, MetaAdsSyncService $metaSync): array
    {
        try {
            return ['ok' => true, 'counts' => $metaSync->sync($profile)];
        } catch (MetaAdsException $exception) {
            $profile->update(['last_meta_error' => $exception->getMessage()]);

            return ['ok' => false, 'text' => $exception->getMessage()];
        } catch (Throwable $exception) {
            MetaFlowLog::warning('direct full sync failed', [
                'profile_id' => $profile->id,
                'exception' => $exception::class,
            ]);

            $message = 'Sync Meta Ads gagal. Coba lagi beberapa saat.';
            $profile->update(['last_meta_error' => $message]);

            return ['ok' => false, 'text' => $message];
        }
    }

    private function metaSyncSuccessMessage(array $counts): string
    {
        $message = sprintf(
            'Sync Meta Ads selesai. %d ad account, %d campaign, dan %d ad set diperbarui.',
            $counts['accounts'] ?? 0,
            $counts['campaigns'] ?? 0,
            $counts['adsets'] ?? 0,
        );

        if (! empty($counts['warning'])) {
            $message .= ' Sebagian metrik belum lengkap: '.$counts['warning'];
        }

        return $message;
    }

    private function automationActive(Request $request): bool
    {
        return $request->input('automation_activation') === 'active' || $request->boolean('automation_activation');
    }

    private function validateAutomation(Request $request): void
    {
        $request->validate([
            'budget_conversion' => ['sometimes', Rule::in(AutomationBudgetService::conversions())],
            'cpr_cap' => ['sometimes', 'integer', 'min:1'],
            'pause_cpr_cap' => ['sometimes', 'integer', 'min:1'],
            'starting_budget' => ['sometimes', 'integer', 'min:1000'],
            'maximum_budget' => ['sometimes', 'integer', 'min:0'],
            'period' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'on_time' => ['sometimes', 'date_format:H:i'],
            'off_time' => ['sometimes', 'date_format:H:i'],
        ]);
        if ($request->boolean('counter_cpr') && (int) $request->input('pause_cpr_cap', 5000) >= (int) $request->input('cpr_cap', 7000)) {
            throw ValidationException::withMessages(['pause_cpr_cap' => 'Resume CPR harus lebih rendah dari CPR Cap.']);
        }
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
            'pause_cpr_cap' => (int) $request->input('pause_cpr_cap', 5000),
            'pause_when_cpr_loss' => $request->boolean('cpr_pause'),
            'counter_cpr' => $request->boolean('counter_cpr'),
            'use_on_off' => $request->boolean('use_on_off'),
            'on_time' => $request->input('on_time', '01:00'),
            'off_time' => $request->input('off_time', '21:00'),
        ];
    }

    private function accountPayload(AdAccount $account): array
    {
        $campaigns = $account->campaigns->filter(fn (Campaign $campaign) => $this->editableMetaTarget($campaign));
        $adSets = $account->adSets->filter(fn (AdSet $adSet) => $this->editableMetaTarget($adSet));

        return [
            'account_id' => $account->account_id,
            'id' => $account->external_id,
            'name' => $account->name,
            'currency' => $account->currency,
            'campaigns' => ['data' => $campaigns->map(fn (Campaign $campaign) => $this->campaignPayload($campaign))->values()],
            'adsets' => ['data' => $adSets->map(fn (AdSet $adSet) => $this->adSetPayload($adSet))->values()],
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
            'adsets' => ['data' => $campaign->adSets->filter(fn (AdSet $adSet) => $this->editableMetaTarget($adSet))->map(fn (AdSet $adSet) => $this->adSetPayload($adSet))->values()],
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
        $target = $this->taskMetricTarget($task);
        $budget = (int) ($target?->daily_budget ?? $task->current_budget);
        $spend = (int) ($target?->spend ?? $task->current_spend);
        $targetResults = $target?->conversion_results ?? [];
        $result = $target
            ? max(0, (int) ($targetResults[$task->conversion] ?? ($task->conversion === 'purchase' ? $target->result : 0)))
            : max(0, (int) $task->current_result);

        return [
            'id' => $task->id,
            'campaign_id' => $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id,
            'parent_campaign_id' => $task->campaign_external_id,
            'adset_id' => $task->ad_set_external_id,
            'current_budget' => $budget,
            'current_spend' => $spend,
            'current_cpr' => $result > 0 ? round($spend / $result) : $spend,
            'current_hasil' => $result,
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
            'last_update' => optional($task->last_metrics_synced_at ?? $task->last_checked_at ?? $task->updated_at)->timezone('Asia/Jakarta')->format('d-m-Y, H:i'),
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

    private function refreshAutomationDisplayMetrics(
        Request $request,
        Collection $tasks,
        AutomationBudgetService $automation,
        MetaAdsSyncService $metaSync
    ): array {
        if ($request->boolean('local')) {
            return ['attempted' => false, 'updated' => 0, 'reason' => 'local_only'];
        }

        if ($tasks->isEmpty()) {
            return ['attempted' => false, 'updated' => 0, 'reason' => 'empty'];
        }

        $profile = $this->metaCredentialProfile();
        if (! $profile->hasAccessToken()) {
            return ['attempted' => false, 'updated' => 0, 'reason' => 'missing_token'];
        }

        $filters = implode(':', [
            Auth::id(),
            $request->query('acc', 'all'),
            $request->query('level', 'all'),
            $request->query('funnel', 'all'),
        ]);
        $cacheKey = 'automation-display-sync:'.md5($filters);

        if (! Cache::add($cacheKey, true, now()->addSeconds(45))) {
            return ['attempted' => false, 'updated' => 0, 'reason' => 'throttled'];
        }

        try {
            $updated = $automation->refreshTaskMetricsForDisplay(
                $profile,
                $metaSync->client($profile),
                $tasks
            );
        } catch (MetaAdsException $exception) {
            $profile->update(['last_meta_error' => $exception->getMessage()]);
            MetaFlowLog::warning('automation display metrics refresh failed', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);

            return ['attempted' => true, 'updated' => 0, 'reason' => 'meta_error'];
        } catch (Throwable $exception) {
            MetaFlowLog::warning('automation display metrics refresh crashed', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
                'error' => $exception->getMessage(),
            ]);

            return ['attempted' => true, 'updated' => 0, 'reason' => 'error'];
        }

        return ['attempted' => true, 'updated' => $updated, 'reason' => 'ok'];
    }

    private function taskMetricTarget(AutomationTask $task): Campaign|AdSet|null
    {
        if ($task->level === 'adset') {
            return $task->adSet
                ?? ($task->ad_set_external_id
                    ? AdSet::query()->where('external_id', $task->ad_set_external_id)->first()
                    : null);
        }

        return $task->campaign
            ?? ($task->campaign_external_id
                ? Campaign::query()->where('external_id', $task->campaign_external_id)->first()
                : null);
    }

    private function metaWriteReadiness(string $disabledMessage): array
    {
        if (! config('services.meta.enable_writes')) {
            MetaFlowLog::warning('meta write rejected because write mode disabled', [
                'user_id' => Auth::id(),
            ]);

            return ['ok' => false, 'text' => $disabledMessage];
        }

        $profile = $this->metaCredentialProfile();

        if (! $profile->hasAccessToken()) {
            MetaFlowLog::warning('meta write rejected without access token', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
            ]);

            return ['ok' => false, 'text' => 'Access token Meta belum diisi.'];
        }

        return ['ok' => true, 'profile_id' => $profile->id];
    }

    private function shouldRefreshMetaBudget(): bool
    {
        return (bool) config('services.meta.enable_writes') && $this->metaCredentialProfile()->hasAccessToken();
    }

    private function pushMetaBudget(Campaign|AdSet|string|null $target, int $budget, string $level, MetaAdsSyncService $metaSync): array
    {
        if (($target instanceof Campaign || $target instanceof AdSet) && ! $this->editableMetaTarget($target)) {
            return ['ok' => false, 'text' => ucfirst($level).' ini sudah dihapus/diarsipkan di Meta. Klik Reload lalu pilih target aktif.'];
        }

        $targetId = match (true) {
            $target instanceof Campaign, $target instanceof AdSet => $target->external_id,
            is_string($target) => $target,
            default => null,
        };

        if (! $targetId) {
            MetaFlowLog::warning('budget push rejected without target', [
                'user_id' => Auth::id(),
                'level' => $level,
            ]);

            return ['ok' => false, 'text' => 'Target campaign/ad set tidak ditemukan.'];
        }

        $readiness = $this->metaWriteReadiness('Budget belum dikirim ke Meta karena write mode belum aktif.');
        if (! $readiness['ok']) {
            return $readiness;
        }

        try {
            $profile = $this->metaCredentialProfile();
            $client = $metaSync->client($profile);

            if ($level === 'adset') {
                $client->updateAdSetBudget($targetId, $budget);
            } else {
                $client->updateCampaignBudget($targetId, $budget);
            }

            MetaFlowLog::info('budget pushed to meta', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
                'target_id' => $targetId,
                'level' => $level,
                'budget' => $budget,
            ]);
        } catch (MetaAdsException $exception) {
            $this->reportMetaAutomationFailure($exception, $targetId, 'budget');

            return ['ok' => false, 'text' => $this->metaAutomationErrorMessage($exception)];
        }

        return ['ok' => true];
    }

    private function pushMetaStatus(AutomationTask $task, bool $active, MetaAdsSyncService $metaSync): array
    {
        $targetId = $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id;
        $target = $this->taskMetricTarget($task);

        if ($target && ! $this->editableMetaTarget($target)) {
            return ['ok' => false, 'text' => ucfirst($task->level).' ini sudah dihapus/diarsipkan di Meta. Klik Reload lalu pilih target aktif.'];
        }

        if (! $targetId) {
            MetaFlowLog::warning('status push rejected without target', [
                'user_id' => Auth::id(),
                'automation_task_id' => $task->id,
                'level' => $task->level,
            ]);

            return ['ok' => false, 'text' => 'Target campaign/ad set tidak ditemukan.'];
        }

        $readiness = $this->metaWriteReadiness('Status belum dikirim ke Meta karena write mode belum aktif.');
        if (! $readiness['ok']) {
            return $readiness;
        }

        try {
            $profile = $this->metaCredentialProfile();
            $client = $metaSync->client($profile);

            if ($task->level === 'adset') {
                $client->updateAdSetStatus($targetId, $active);
            } else {
                $client->updateCampaignStatus($targetId, $active);
            }

            MetaFlowLog::info('status pushed to meta', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
                'automation_task_id' => $task->id,
                'target_id' => $targetId,
                'level' => $task->level,
                'active' => $active,
            ]);
        } catch (MetaAdsException $exception) {
            $this->reportMetaAutomationFailure($exception, $targetId, 'status');

            return ['ok' => false, 'text' => $this->metaAutomationErrorMessage($exception)];
        }

        return ['ok' => true];
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

    private function metaAutomationErrorMessage(MetaAdsException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if ($exception->metaCode === 190 || str_contains($message, 'token')) {
            return 'Access token Meta tidak valid atau sudah expired. Silakan simpan ulang access token di Profile.';
        }

        if ($exception->httpStatus === 403 || str_contains($message, 'permission')) {
            return 'Akses Meta belum punya izin untuk mengubah campaign/ad set ini.';
        }

        if ($exception->httpStatus === 400) {
            if ($exception->providerMessage) {
                return 'Meta menolak update: '.$exception->providerMessage;
            }

            return 'Meta menolak update. Cek minimum budget, status campaign/ad set, dan permission ad account.';
        }

        return 'Update Meta belum berhasil. Coba lagi beberapa saat.';
    }

    private function reportMetaAutomationFailure(MetaAdsException $exception, string $targetId, string $action): void
    {
        if ($this->metaExceptionMeansDeletedTarget($exception)) {
            Campaign::query()->where('external_id', $targetId)->update(['status' => 'DELETED', 'effective_status' => 'DELETED']);
            AdSet::query()->where('external_id', $targetId)->update(['status' => 'DELETED', 'effective_status' => 'DELETED']);
        }

        MetaFlowLog::warning('automation meta update failed', [
            'target_id' => $targetId,
            'action' => $action,
            'http_status' => $exception->httpStatus,
            'meta_code' => $exception->metaCode,
            'meta_subcode' => $exception->metaSubcode,
            'meta_type' => $exception->metaType,
            'provider_message' => $exception->providerMessage,
        ]);
    }

    private function editableMetaTarget(Campaign|AdSet $target): bool
    {
        $status = strtoupper((string) $target->status);
        $effectiveStatus = strtoupper((string) $target->effective_status);

        return ! in_array($status, self::NON_EDITABLE_META_STATUSES, true)
            && ! in_array($effectiveStatus, self::NON_EDITABLE_META_STATUSES, true);
    }

    private function metaExceptionMeansDeletedTarget(MetaAdsException $exception): bool
    {
        $message = strtolower((string) $exception->providerMessage);

        return $exception->metaSubcode === 1487566
            || str_contains($message, 'sudah dihapus')
            || str_contains($message, 'has been deleted');
    }

    private function insightsPayload(?string $adAccountExternalId = null, array $selectedCampaigns = [], string $level = 'campaign', string $conversion = 'purchase'): array
    {
        $query = $level === 'adset' ? AdSet::query() : Campaign::query();
        $rows = $query
            ->when($adAccountExternalId, fn ($query) => $query->whereHas('adAccount', fn ($account) => $account->where('external_id', $adAccountExternalId)))
            ->when($selectedCampaigns !== [], fn ($query) => $query->whereIn('external_id', $selectedCampaigns))
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->map(fn (Campaign|AdSet $item) => $this->insightRow($item, $level, $conversion));

        $sum = fn (string $key) => $rows->sum($key);
        $results = max(1, $sum('hasil'));

        return [
            'summery' => $rows->values(),
            'highlight' => [
                $this->metric('Reach', 'number', 'reach', $sum('reach')),
                $this->metric('Spend', 'currency', 'spend', $sum('spend')),
                $this->metric('Landing Page View', 'number', 'landing_page_view', $sum('landing_page_view')),
                $this->metric('Link Clicks', 'number', 'link_click', $sum('link_click')),
                $this->metric('Hasil ('.AutomationBudgetService::conversionLabel($conversion).')', 'number', $conversion, $sum('hasil')),
                $this->metric('CPR', 'currency', 'cpr', round($sum('spend') / $results)),
                $this->metric('Klik Landas', 'percen', 'klik_landas', round(($sum('landing_page_view') / max(1, $sum('link_click'))) * 100, 1), 70),
                $this->metric('Uang Klik', 'currency', 'uang_klik', round($sum('spend') / max(1, $sum('link_click'))), 190),
                $this->metric('Uang Jangkauan', 'currency', 'uang_jangkauan', round($sum('spend') / max(1, $sum('reach'))), 5),
                $this->metric('Landas Hasil', 'number', 'landas_hasil', round($sum('landing_page_view') / $results, 1)),
            ],
        ];
    }

    private function insightRow(Campaign|AdSet $item, string $level, string $conversion): array
    {
        $result = max(0, (int) ($item->conversion_results[$conversion] ?? ($conversion === 'purchase' ? $item->result : 0)));
        $cpr = $result > 0 ? round($item->spend / $result) : $item->spend;

        return [
            'campaign_id' => $item->external_id,
            'campaign_name' => $item->name,
            'parent_campaign_id' => $item instanceof AdSet ? $item->campaign?->external_id : $item->external_id,
            'level' => $level,
            'budget' => $item->daily_budget,
            'spend' => $item->spend,
            'reach' => $item->reach,
            'hasil' => $result,
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
            ->latest('last_added_at')
            ->latest('updated_at')
            ->latest('id')
            ->orderByDesc('sold')
            ->limit(100);
    }

    private function newestAdAccounts()
    {
        return AdAccount::query()
            ->latest('updated_at')
            ->latest('id');
    }

    private function newestAdAccountsWithChildren()
    {
        return $this->newestAdAccounts()->with([
            'campaigns' => fn ($query) => $query
                ->latest('updated_at')
                ->latest('id')
                ->with(['adSets' => fn ($query) => $query->latest('updated_at')->latest('id')]),
            'adSets' => fn ($query) => $query
                ->latest('updated_at')
                ->latest('id')
                ->with('campaign'),
        ]);
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

    private function metaCredentialProfile(): T4JamProfile
    {
        return T4JamProfile::usableForUser(Auth::id());
    }
}
