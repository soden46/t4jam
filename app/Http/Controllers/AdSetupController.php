<?php

namespace App\Http\Controllers;

use App\Exceptions\MetaAdsException;
use App\Models\AdAccount;
use App\Models\AdSetup;
use App\Models\T4JamProfile;
use App\Services\MetaAdSetupPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdSetupController extends Controller
{
    public function index(): View
    {
        return view('ad-setups.index', [
            'title' => 'Setup Iklan',
            'accounts' => AdAccount::query()->orderBy('name')->get(),
            'setups' => AdSetup::with('adAccount')
                ->where('user_id', Auth::id())
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request, MetaAdSetupPublisher $publisher): RedirectResponse
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
            return redirect()->route('ad-setups.index')->with('status', 'Draft setup iklan berhasil disimpan.');
        }

        try {
            $setup = $publisher->publish($setup, T4JamProfile::firstOrCreate(['user_id' => Auth::id()]));
        } catch (MetaAdsException $exception) {
            $message = $this->metaErrorMessage($exception);
            $setup->update(['status' => 'failed', 'last_error' => $message]);
            $this->reportMetaPublishFailure($exception, $setup);

            return redirect()->route('ad-setups.index')->withErrors(['meta' => $message]);
        }

        if ($setup->status === 'ready') {
            return redirect()->route('ad-setups.index')->with('warning', 'Setup iklan sudah siap. Publish ke Meta belum dijalankan karena write mode belum aktif.');
        }

        return redirect()->route('ad-setups.index')->with('status', 'Setup iklan berhasil dipublish ke Meta.');
    }

    public function publish(AdSetup $adSetup, MetaAdSetupPublisher $publisher): RedirectResponse
    {
        abort_unless($adSetup->user_id === Auth::id(), 403);

        try {
            $adSetup = $publisher->publish($adSetup, T4JamProfile::firstOrCreate(['user_id' => Auth::id()]));
        } catch (MetaAdsException $exception) {
            $message = $this->metaErrorMessage($exception);
            $adSetup->update(['status' => 'failed', 'last_error' => $message]);
            $this->reportMetaPublishFailure($exception, $adSetup);

            return redirect()->route('ad-setups.index')->withErrors(['meta' => $message]);
        }

        if ($adSetup->status === 'ready') {
            return redirect()->route('ad-setups.index')->with('warning', 'Setup iklan sudah siap. Publish ke Meta belum dijalankan karena write mode belum aktif.');
        }

        return redirect()->route('ad-setups.index')->with('status', 'Setup iklan berhasil dipublish ke Meta.');
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
        Log::warning('Meta ad setup publish failed', [
            'ad_setup_id' => $setup->id,
            'user_id' => $setup->user_id,
            'http_status' => $exception->httpStatus,
            'meta_code' => $exception->metaCode,
            'meta_type' => $exception->metaType,
        ]);
    }
}
