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
    private const AUTOMATION_FIELDS = ['campaigns', 'adsets', 'ads'];

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
            ->map(function (array $entry): array {
                $changes = collect($entry['changes'] ?? [])->filter(fn ($change) => is_array($change));
                $targets = $this->extractAutomationTargets($changes->all());

                return [
                    'account' => $this->normalizeAdAccountId((string) $entry['id']),
                    'fields' => $changes->pluck('field')->filter()->unique()->values()->all(),
                    'campaign_ids' => $targets['campaign_ids'],
                    'ad_set_ids' => $targets['ad_set_ids'],
                ];
            })
            ->filter(function (array $entry): bool {
                $relevant = collect($entry['fields'])->contains(fn ($field) => in_array($field, self::AUTOMATION_FIELDS, true));

                if (! $relevant) {
                    MetaFlowLog::info('automation evaluation', [
                        'profile_id' => null,
                        'automation_task_id' => null,
                        'level' => null,
                        'target_id' => null,
                        'conversion' => null,
                        'spend' => null,
                        'result' => null,
                        'cpr' => null,
                        'cpr_cap' => null,
                        'target_status' => null,
                        'action' => 'none',
                        'reason' => 'webhook_irrelevant',
                        'source' => 'webhook',
                        'fields' => $entry['fields'],
                    ]);
                }

                return $relevant;
            })
            ->unique(fn (array $entry) => $entry['account'].':'.implode(',', $entry['campaign_ids']).':'.implode(',', $entry['ad_set_ids']))
            ->flatMap(function (array $entry): array {
                return $this->profileIdsForAccount($entry['account'])
                    ->map(function (int $profileId) use ($entry): array {
                        SyncMetaAdsAccount::dispatch(
                            $profileId,
                            $entry['account'],
                            $entry['campaign_ids'],
                            $entry['ad_set_ids'],
                            $entry['fields'],
                        );
                        MetaFlowLog::info('meta webhook account sync queued', [
                            'profile_id' => $profileId,
                            'ad_account_id' => $entry['account'],
                            'fields' => $entry['fields'],
                            'campaign_ids' => $entry['campaign_ids'],
                            'ad_set_ids' => $entry['ad_set_ids'],
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

    private function extractAutomationTargets(array $changes): array
    {
        $campaignIds = [];
        $adSetIds = [];

        foreach ($changes as $change) {
            $field = $change['field'] ?? null;
            $value = $change['value'] ?? [];

            if ($field === 'campaigns') {
                $campaignIds = array_merge($campaignIds, $this->collectIds($value, ['id', 'campaign_id']));
            }

            if ($field === 'adsets') {
                $adSetIds = array_merge($adSetIds, $this->collectIds($value, ['id', 'adset_id', 'ad_set_id']));
            }

            if ($field === 'ads') {
                $adIds = $this->collectIds($value, ['adset_id', 'ad_set_id']);
                $adSetIds = array_merge($adSetIds, $adIds);

                if ($adIds === []) {
                    $campaignIds = array_merge($campaignIds, $this->collectIds($value, ['campaign_id']));
                }
            }
        }

        return [
            'campaign_ids' => collect($campaignIds)->filter()->unique()->values()->all(),
            'ad_set_ids' => collect($adSetIds)->filter()->unique()->values()->all(),
        ];
    }

    private function collectIds(mixed $value, array $keys): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $key => $item) {
            if (in_array($key, $keys, true) && is_scalar($item) && filled((string) $item)) {
                $ids[] = (string) $item;
            }

            if (is_array($item)) {
                $ids = array_merge($ids, $this->collectIds($item, $keys));
            }
        }

        return $ids;
    }

    private function normalizeAdAccountId(string $id): string
    {
        return str_starts_with($id, 'act_') ? $id : 'act_'.$id;
    }
}
