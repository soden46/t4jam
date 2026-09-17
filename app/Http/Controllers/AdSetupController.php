<?php

namespace App\Http\Controllers;

use App\Exceptions\MetaAdsException;
use App\Jobs\PublishMetaAdSetup;
use App\Models\AdAccount;
use App\Models\AdSetup;
use App\Models\T4JamProfile;
use App\Services\MetaAdSetupPublisher;
use App\Support\MetaFlowLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdSetupController extends Controller
{
    public function index(): View
    {
        $setups = $this->setupQuery()->get();

        return view('ad-setups.index', [
            'title' => 'Setup Iklan',
            'accounts' => AdAccount::query()->latest('updated_at')->latest('id')->get(),
            'setups' => $setups,
            'metaWritesEnabled' => config('services.meta.enable_writes'),
        ]);
    }

    public function status(): JsonResponse
    {
        $setups = $this->setupQuery()->get();

        return response()->json([
            'status' => 200,
            'total_setup' => $setups->count(),
            'setups' => $setups->map(fn (AdSetup $setup) => $this->setupPayload($setup))->values(),
        ]);
    }

    public function store(Request $request, MetaAdSetupPublisher $publisher): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'ad_account_id' => ['required', 'exists:ad_accounts,id'],
            'name' => ['required', 'string', 'max:160'],
            'campaign_name' => ['required', 'string', 'max:160'],
            'campaign_objective' => ['required', 'string', 'max:80'],
            'campaign_status' => ['required', 'in:ACTIVE,PAUSED'],
            'adset_name' => ['required', 'string', 'max:160'],
            'daily_budget' => ['required', 'integer', 'min:1000'],
            'billing_event' => ['required', 'string', 'max:80'],
            'optimization_goal' => ['required', 'string', 'max:80'],
            'bid_strategy' => ['required', 'string', 'max:80'],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date', 'after:start_time'],
            'countries' => ['required', 'string', 'max:255'],
            'age_min' => ['required', 'integer', 'min:13', 'max:65'],
            'age_max' => ['required', 'integer', 'min:13', 'max:65', 'gte:age_min'],
            'interests' => ['nullable', 'string', 'max:1000'],
            'page_id' => ['required', 'string', 'max:80'],
            'instagram_actor_id' => ['nullable', 'string', 'max:80'],
            'ad_name' => ['required', 'string', 'max:160'],
            'creative_name' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:1000'],
            'headline' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'link_url' => ['required', 'url', 'max:255'],
            'call_to_action' => ['required', 'string', 'max:80'],
            'publish' => ['nullable', 'boolean'],
        ]);

        $setup = AdSetup::create($this->payload($data) + [
            'user_id' => Auth::id(),
            'status' => $request->boolean('publish') ? 'publishing' : 'draft',
        ]);

        if (! $request->boolean('publish')) {
            MetaFlowLog::info('ad setup draft saved', [
                'user_id' => Auth::id(),
                'ad_setup_id' => $setup->id,
                'ad_account_id' => $setup->adAccount?->external_id,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 200,
                    'text' => 'Draft setup iklan berhasil disimpan.',
                    'setup' => $this->setupPayload($setup->load('adAccount')),
                ]);
            }

            return redirect()->route('ad-setups.index')->with('status', 'Draft setup iklan berhasil disimpan.');
        }

        return $this->publishOrQueue($request, $setup, $publisher);
    }

    public function publish(Request $request, AdSetup $adSetup, MetaAdSetupPublisher $publisher): RedirectResponse|JsonResponse
    {
        abort_unless($adSetup->user_id === Auth::id(), 403);

        if ($adSetup->status === 'published') {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 200,
                    'text' => 'Setup iklan sudah dipublish.',
                    'setup' => $this->setupPayload($adSetup->load('adAccount')),
                ]);
            }

            return redirect()->route('ad-setups.index')->with('status', 'Setup iklan sudah dipublish.');
        }
        $adSetup->update(['status' => 'publishing', 'last_error' => null]);

        return $this->publishOrQueue($request, $adSetup, $publisher);
    }

    private function publishOrQueue(Request $request, AdSetup $setup, MetaAdSetupPublisher $publisher): RedirectResponse|JsonResponse
    {
        try {
            $profile = T4JamProfile::usableForUser(Auth::id());

            if (! config('services.meta.enable_writes')) {
                $publisher->publish($setup, $profile);
                MetaFlowLog::info('ad setup publish skipped because write mode disabled', [
                    'user_id' => Auth::id(),
                    'profile_id' => $profile->id,
                    'ad_setup_id' => $setup->id,
                ]);

                if ($request->expectsJson()) {
                    return response()->json([
                        'status' => 200,
                        'text' => 'Setup iklan sudah siap. Publish ke Meta belum dijalankan karena write mode belum aktif.',
                        'setup' => $this->setupPayload($setup->fresh('adAccount')),
                    ]);
                }

                return redirect()->route('ad-setups.index')->with('warning', 'Setup iklan sudah siap. Publish ke Meta belum dijalankan karena write mode belum aktif.');
            }

            if (! $profile->hasAccessToken()) {
                $message = 'Access token Meta belum diisi. Silakan simpan access token di Profile.';
                $setup->update(['status' => 'failed', 'last_error' => $message]);
                MetaFlowLog::warning('ad setup publish rejected without access token', [
                    'user_id' => Auth::id(),
                    'profile_id' => $profile->id,
                    'ad_setup_id' => $setup->id,
                ]);

                if ($request->expectsJson()) {
                    return response()->json([
                        'status' => 422,
                        'text' => $message,
                        'setup' => $this->setupPayload($setup->fresh('adAccount')),
                    ], 422);
                }

                return redirect()->route('ad-setups.index')->withErrors(['meta' => $message]);
            }

            PublishMetaAdSetup::dispatch($setup->id, $profile->id)->afterResponse();
            MetaFlowLog::info('ad setup publish queued', [
                'user_id' => Auth::id(),
                'profile_id' => $profile->id,
                'ad_setup_id' => $setup->id,
                'queue' => 'meta',
            ]);
        } catch (MetaAdsException $exception) {
            $message = $this->metaErrorMessage($exception);
            $setup->update(['status' => 'failed', 'last_error' => $message]);
            $this->reportMetaPublishFailure($exception, $setup);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 422,
                    'text' => $message,
                    'setup' => $this->setupPayload($setup->fresh('adAccount')),
                ], 422);
            }

            return redirect()->route('ad-setups.index')->withErrors(['meta' => $message]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 200,
                'text' => 'Setup iklan diproses di background.',
                'setup' => $this->setupPayload($setup->fresh('adAccount')),
            ]);
        }

        return redirect()->route('ad-setups.index')->with('status', 'Setup iklan masuk antrean queue. Worker akan publish ke Meta di background.');
    }

    private function setupQuery()
    {
        return AdSetup::with('adAccount')
            ->where('user_id', Auth::id())
            ->latest();
    }

    private function setupPayload(AdSetup $setup): array
    {
        return [
            'id' => $setup->id,
            'name' => $setup->name,
            'campaign_name' => $setup->campaign_name,
            'ad_account' => $setup->adAccount?->name ?? '-',
            'status' => $setup->status,
            'meta_campaign_id' => $setup->meta_campaign_id,
            'meta_adset_id' => $setup->meta_adset_id,
            'meta_ad_id' => $setup->meta_ad_id,
            'last_error' => $setup->last_error,
            'publish_url' => route('ad-setups.publish', $setup),
        ];
    }

    private function payload(array $data): array
    {
        return collect($data)->except(['countries', 'interests', 'publish'])->merge([
            'special_ad_categories' => [],
            'targeting' => [
                'geo_locations' => [
                    'countries' => collect(explode(',', $data['countries']))->map(fn (string $country) => strtoupper(trim($country)))->filter()->values()->all(),
                ],
                'age_min' => (int) $data['age_min'],
                'age_max' => (int) $data['age_max'],
                'interests' => $this->interestPayload($data['interests'] ?? ''),
            ],
        ])->all();
    }

    private function interestPayload(string $interests): array
    {
        return collect(explode("\n", str_replace(',', "\n", $interests)))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->map(function (string $line) {
                [$id, $name] = array_pad(explode('|', $line, 2), 2, null);

                return ['id' => trim($id), 'name' => trim($name ?: $id)];
            })
            ->values()
            ->all();
    }

    private function metaErrorMessage(MetaAdsException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if ($exception->metaCode === 190 || str_contains($message, 'token')) {
            return 'Access token Meta tidak valid atau sudah expired. Silakan simpan ulang access token di Profile.';
        }

        if (in_array($exception->metaCode, [17, 4, 613, 80000, 80001, 80002, 80003, 80004], true)) {
            return 'Meta rate limit tercapai. Tunggu sebentar lalu coba lagi.';
        }

        if ($exception->httpStatus === 403 || str_contains($message, 'permission')) {
            return 'Akses Meta belum punya izin untuk membuat iklan di ad account ini.';
        }

        if ($exception->httpStatus === 400) {
            return 'Meta menolak data setup iklan. Cek Page ID, targeting, budget, dan URL landing page.';
        }

        return 'Publish ke Meta belum berhasil. Coba lagi beberapa saat atau cek koneksi Meta di Profile.';
    }

    private function reportMetaPublishFailure(MetaAdsException $exception, AdSetup $setup): void
    {
        MetaFlowLog::warning('ad setup publish failed', [
            'ad_setup_id' => $setup->id,
            'user_id' => $setup->user_id,
            'http_status' => $exception->httpStatus,
            'meta_code' => $exception->metaCode,
            'meta_type' => $exception->metaType,
        ]);
    }
}
