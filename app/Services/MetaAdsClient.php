<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaAdsClient
{
    public function __construct(private readonly string $accessToken) {}

    public function validateToken(): array
    {
        return $this->get('/me', ['fields' => 'id,name']);
    }

    public function adAccounts(): array
    {
        $accounts = $this->paginate('/me/adaccounts', [
            'fields' => 'account_id,id,name,currency,account_status,business{id,name}',
            'limit' => 100,
        ]);

        foreach ($this->businessPortfolios() as $business) {
            $businessId = $business['id'] ?? null;

            if (! $businessId) {
                continue;
            }

            $accounts = array_merge(
                $accounts,
                $this->businessAdAccounts($business, 'owned_ad_accounts'),
                $this->businessAdAccounts($business, 'client_ad_accounts'),
            );
        }

        return $this->uniqueAdAccounts($accounts);
    }

    public function businessPortfolios(): array
    {
        return $this->businesses();
    }

    public function campaigns(string $adAccountId): array
    {
        return $this->paginate("/{$adAccountId}/campaigns", [
            'fields' => 'id,name,status,effective_status,daily_budget,objective',
            'limit' => 100,
        ]);
    }

    public function campaignInsights(string $campaignId, string $datePreset = 'today'): array
    {
        $response = $this->get("/{$campaignId}/insights", [
            'fields' => 'spend,reach,actions,inline_link_clicks',
            'date_preset' => $datePreset,
            'level' => 'campaign',
            'limit' => 1,
        ]);

        return $response['data'][0] ?? [];
    }

    public function adSets(string $campaignId): array
    {
        return $this->paginate("/{$campaignId}/adsets", [
            'fields' => 'id,name,status,effective_status,daily_budget',
            'limit' => 100,
        ]);
    }

    public function adSetInsights(string $adSetId, string $datePreset = 'today'): array
    {
        $response = $this->get("/{$adSetId}/insights", [
            'fields' => 'spend,reach,actions,inline_link_clicks',
            'date_preset' => $datePreset,
            'level' => 'adset',
            'limit' => 1,
        ]);

        return $response['data'][0] ?? [];
    }

    public function updateCampaignBudget(string $campaignId, int $dailyBudget): array
    {
        return $this->post("/{$campaignId}", ['daily_budget' => $dailyBudget]);
    }

    public function updateAdSetBudget(string $adSetId, int $dailyBudget): array
    {
        return $this->post("/{$adSetId}", ['daily_budget' => $dailyBudget]);
    }

    public function updateCampaignStatus(string $campaignId, bool $active): array
    {
        return $this->post("/{$campaignId}", ['status' => $active ? 'ACTIVE' : 'PAUSED']);
    }

    public function updateAdSetStatus(string $adSetId, bool $active): array
    {
        return $this->post("/{$adSetId}", ['status' => $active ? 'ACTIVE' : 'PAUSED']);
    }

    public function createCampaign(string $adAccountId, array $payload): array
    {
        return $this->post("/{$adAccountId}/campaigns", $payload);
    }

    public function createAdSet(string $adAccountId, array $payload): array
    {
        return $this->post("/{$adAccountId}/adsets", $payload);
    }

    public function createAdCreative(string $adAccountId, array $payload): array
    {
        return $this->post("/{$adAccountId}/adcreatives", $payload);
    }

    public function createAd(string $adAccountId, array $payload): array
    {
        return $this->post("/{$adAccountId}/ads", $payload);
    }

    public static function exchangeLongLivedToken(string $appId, string $appSecret, string $shortLivedToken): string
    {
        $response = Http::acceptJson()
            ->timeout(config('services.meta.timeout'))
            ->retry(config('services.meta.retry_times'), config('services.meta.retry_sleep_ms'), throw: false)
            ->get(rtrim(config('services.meta.base_url'), '/').'/'.trim(config('services.meta.graph_version'), '/').'/oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

        if ($response->failed()) {
            $error = $response->json('error') ?? [];

            throw new MetaAdsException(
                $error['message'] ?? 'Gagal menukar access token Meta.',
                $response->status(),
                $error['code'] ?? null,
                $error['type'] ?? null,
            );
        }

        return $response->json('access_token') ?? $shortLivedToken;
    }

    private function businesses(): array
    {
        try {
            return $this->paginate('/me/businesses', [
                'fields' => 'id,name',
                'limit' => 100,
            ]);
        } catch (MetaAdsException $exception) {
            Log::info('Meta business account lookup skipped', [
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
                'http_status' => $exception->httpStatus,
            ]);

            return [];
        }
    }

    private function businessAdAccounts(array $business, string $edge): array
    {
        $businessId = $business['id'] ?? null;

        if (! $businessId) {
            return [];
        }

        try {
            $accounts = $this->paginate("/{$businessId}/{$edge}", [
                'fields' => 'account_id,id,name,currency,account_status,business{id,name}',
                'limit' => 100,
            ]);

            return collect($accounts)
                ->map(function (array $account) use ($business): array {
                    $account['business'] ??= [
                        'id' => $business['id'],
                        'name' => $business['name'] ?? $business['id'],
                    ];

                    return $account;
                })
                ->all();
        } catch (MetaAdsException $exception) {
            Log::info('Meta business ad account lookup skipped', [
                'business_id' => $businessId,
                'edge' => $edge,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
                'http_status' => $exception->httpStatus,
            ]);

            return [];
        }
    }

    private function uniqueAdAccounts(array $accounts): array
    {
        return collect($accounts)
            ->filter(fn (array $account) => ! empty($account['id']))
            ->groupBy('id')
            ->map(function ($matches) {
                $account = $matches->first();
                $businessAccount = $matches->first(fn (array $match) => ! empty($match['business']['id']));

                if ($businessAccount && empty($account['business']['id'])) {
                    $account['business'] = $businessAccount['business'];
                }

                return $account;
            })
            ->values()
            ->all();
    }

    private function paginate(string $path, array $query): array
    {
        $response = $this->get($path, $query);
        $rows = $response['data'] ?? [];
        $next = $response['paging']['next'] ?? null;

        while ($next) {
            $response = $this->send('GET', $next);
            $rows = array_merge($rows, $response['data'] ?? []);
            $next = $response['paging']['next'] ?? null;
        }

        return $rows;
    }

    private function get(string $path, array $query = []): array
    {
        return $this->send('GET', $this->url($path), $query);
    }

    private function post(string $path, array $data = []): array
    {
        return $this->send('POST', $this->url($path), $data);
    }

    private function send(string $method, string $url, array $data = []): array
    {
        $payload = $data + ['access_token' => $this->accessToken];
        $request = Http::acceptJson()
            ->timeout(config('services.meta.timeout'))
            ->retry(config('services.meta.retry_times'), config('services.meta.retry_sleep_ms'), throw: false);

        $response = $method === 'POST'
            ? $request->asForm()->post($url, $payload)
            : $request->get($url, $payload);

        if ($response->failed()) {
            $this->throwMetaException($response);
        }

        return $response->json() ?? [];
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim(config('services.meta.base_url'), '/').'/'.trim(config('services.meta.graph_version'), '/').'/'.ltrim($path, '/');
    }

    private function throwMetaException(Response $response): never
    {
        $error = $response->json('error') ?? [];
        $metaCode = $error['code'] ?? null;
        $metaType = $error['type'] ?? null;

        if ($this->isRateLimitError($metaCode)) {
            $retryAfter = $this->parseRetryAfter($response);
            Log::warning('Meta rate limit hit', [
                'meta_code' => $metaCode,
                'meta_type' => $metaType,
                'retry_after_seconds' => $retryAfter,
            ]);
        }

        throw new MetaAdsException(
            $error['message'] ?? 'Meta Graph API request failed.',
            $response->status(),
            $metaCode,
            $metaType,
        );
    }

    private function isRateLimitError(?int $metaCode): bool
    {
        if ($metaCode === null) {
            return false;
        }

        return in_array($metaCode, [4, 17, 613, 80000, 80001, 80002, 80003, 80004], true);
    }

    private function parseRetryAfter(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');
        if ($retryAfter !== null) {
            return (int) $retryAfter;
        }

        $businessUsage = $response->header('X-Business-Use-Case-Usage');
        if ($businessUsage) {
            $decoded = json_decode($businessUsage, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $usages) {
                    if (isset($usages[0]['estimated_time_to_regain_access'])) {
                        return (int) $usages[0]['estimated_time_to_regain_access'];
                    }
                }
            }
        }

        return null;
    }
}
