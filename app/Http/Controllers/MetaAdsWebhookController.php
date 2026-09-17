<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMetaAdsAccount;
use App\Models\AdAccount;
use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Support\MetaFlowLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class MetaAdsWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = (string) $request->query('hub.verify_token', $request->query('hub_verify_token', ''));
        $challenge = (string) $request->query('hub.challenge', $request->query('hub_challenge', ''));
        $expected = (string) config('services.meta.webhook_verify_token');

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            abort(403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): JsonResponse
    {
        $body = $request->getContent();

        if (! $this->hasValidSignature($body, (string) $request->header('X-Hub-Signature-256'))) {
            MetaFlowLog::warning('meta webhook rejected invalid signature');

            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $payload = $request->json()->all();
        if (($payload['object'] ?? null) !== 'ad_account') {
            return response()->json(['status' => 'ignored']);
        }

        $queued = collect($payload['entry'] ?? [])
            ->filter(fn ($entry) => is_array($entry) && filled($entry['id'] ?? null))
            ->map(fn (array $entry) => [
                'account' => $this->normalizeAdAccountId((string) $entry['id']),
                'fields' => collect($entry['changes'] ?? [])->pluck('field')->filter()->unique()->values()->all(),
            ])
            ->unique('account')
            ->flatMap(function (array $entry): array {
                return $this->profileIdsForAccount($entry['account'])
                    ->map(function (int $profileId) use ($entry): array {
                        SyncMetaAdsAccount::dispatch($profileId, $entry['account']);
                        MetaFlowLog::info('meta webhook account sync queued', [
                            'profile_id' => $profileId,
                            'ad_account_id' => $entry['account'],
                            'fields' => $entry['fields'],
                        ]);

                        return [$profileId.':'.$entry['account']];
                    })
                    ->all();
            })
            ->unique()
            ->count();

        return response()->json(['status' => 'received', 'queued' => $queued]);
    }

    private function hasValidSignature(string $body, string $signature): bool
    {
        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $provided = substr($signature, 7);

        return $this->webhookSecrets()->contains(
            fn (string $secret) => hash_equals(hash_hmac('sha256', $body, $secret), $provided)
        );
    }

    private function webhookSecrets(): Collection
    {
        $configured = (string) config('services.meta.webhook_app_secret');

        if ($configured !== '') {
            return collect([$configured]);
        }

        return T4JamProfile::query()
            ->whereNotNull('app_secret')
            ->get()
            ->map(fn (T4JamProfile $profile) => (string) $profile->app_secret)
            ->filter()
            ->unique()
            ->values();
    }

    private function profileIdsForAccount(string $adAccountExternalId): Collection
    {
        $account = AdAccount::query()->where('external_id', $adAccountExternalId)->first();
        $profileIds = $account?->profiles()->pluck('t4jam_profiles.id') ?? collect();

        if ($profileIds->isEmpty() && $account) {
            $userIds = AutomationTask::query()
                ->where('ad_account_id', $account->id)
                ->whereNotNull('user_id')
                ->pluck('user_id');
            $profileIds = T4JamProfile::query()
                ->whereIn('user_id', $userIds)
                ->whereNotNull('access_token')
                ->pluck('id');
        }

        if ($profileIds->isEmpty()) {
            $profiles = T4JamProfile::query()->whereNotNull('access_token')->pluck('id');
            if ($profiles->count() === 1) {
                $profileIds = $profiles;
            }
        }

        return $profileIds->map(fn ($id) => (int) $id)->unique()->values();
    }

    private function normalizeAdAccountId(string $id): string
    {
        return str_starts_with($id, 'act_') ? $id : 'act_'.$id;
    }
}
