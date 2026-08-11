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
            $publisher->publish($setup, T4JamProfile::firstOrCreate(['user_id' => Auth::id()]));
        } catch (MetaAdsException $exception) {
            $setup->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);

            return redirect()->route('ad-setups.index')->withErrors(['meta' => $exception->getMessage()]);
        }

        return redirect()->route('ad-setups.index')->with('status', 'Setup iklan berhasil dipublish ke Meta.');
    }

    public function publish(AdSetup $adSetup, MetaAdSetupPublisher $publisher): RedirectResponse
    {
        abort_unless($adSetup->user_id === Auth::id(), 403);

        try {
            $publisher->publish($adSetup, T4JamProfile::firstOrCreate(['user_id' => Auth::id()]));
        } catch (MetaAdsException $exception) {
            $adSetup->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);

            return redirect()->route('ad-setups.index')->withErrors(['meta' => $exception->getMessage()]);
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
}
