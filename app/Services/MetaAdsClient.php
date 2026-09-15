<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Support\MetaFlowLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
            'fields' => 'account_id,id,name,currency,account_status',
            'limit' => 100,
        ]);

        try {
            foreach ($this->businesses() as $business) {
                $businessId = $business['id'] ?? null;

                if (! $businessId) {
                    continue;
                }

                $accounts = array_merge(
                    $accounts,
                    $this->businessAdAccounts($businessId, 'owned_ad_accounts'),
                    $this->businessAdAccounts($businessId, 'client_ad_accounts'),
                );
            }
        } catch (MetaAdsException $exception) {
            if ($exception->retryable() || $exception->metaCode === 190 || $exception->httpStatus === 401) {
                throw $exception;
            }
            MetaFlowLog::warning('business account lookup skipped', [
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);
        }

        return $this->uniqueAdAccounts($accounts);
    }

    public function campaigns(string $adAccountId): array
    {
        return $this->paginate("/{$adAccountId}/campaigns", [
            'fields' => 'id,name,status,effective_status,daily_budget,objective',
            'limit' => 100,
        ]);
    }

    public function campaignInsights(string $campaignId, ?string $datePreset = null): array
    {
        $response = $this->get("/{$campaignId}/insights", [
            'fields' => 'spend,reach,actions,cost_per_action_type,inline_link_clicks',
            'date_preset' => $datePreset ?? config('services.meta.insights_date_preset', 'last_30d'),
            'level' => 'campaign',
            'use_unified_attribution_setting' => true,
            'limit' => 1,
        ]);

        return $response['data'][0] ?? [];
    }

    public function accountCampaignInsights(string $adAccountId, ?string $datePreset = null): array
    {
        return $this->accountInsights($adAccountId, 'campaign', 'campaign_id', $datePreset);
    }

    public function adSets(string $campaignId): array
    {
        return $this->paginate("/{$campaignId}/adsets", [
            'fields' => 'id,name,status,effective_status,daily_budget',
            'limit' => 100,
        ]);
    }

    public function adSetInsights(string $adSetId, ?string $datePreset = null): array
    {
        $response = $this->get("/{$adSetId}/insights", [
            'fields' => 'spend,reach,actions,cost_per_action_type,inline_link_clicks',
            'date_preset' => $datePreset ?? config('services.meta.insights_date_preset', 'last_30d'),
            'level' => 'adset',
            'use_unified_attribution_setting' => true,
            'limit' => 1,
        ]);

        return $response['data'][0] ?? [];
    }

    public function accountAdSetInsights(string $adAccountId, ?string $datePreset = null): array
    {
        return $this->accountInsights($adAccountId, 'adset', 'adset_id', $datePreset);
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
        try {
            $response = Http::acceptJson()->timeout(config('services.meta.timeout'))
                ->get(rtrim(config('services.meta.base_url'), '/').'/'.trim(config('services.meta.graph_version'), '/').'/oauth/access_token', [
                    'grant_type' => 'fb_exchange_token', 'client_id' => $appId,
                    'client_secret' => $appSecret, 'fb_exchange_token' => $shortLivedToken,
                ]);
        } catch (ConnectionException) {
            throw new MetaAdsException('Koneksi Meta gagal. Coba lagi beberapa saat.', transient: true);
        }
        if ($response->failed()) {
            (new self($shortLivedToken))->throwMetaException($response);
        }

        return $response->json('access_token') ?? $shortLivedToken;
    }

    private function businesses(): array
    {
        return $this->paginate('/me/businesses', [
            'fields' => 'id,name',
            'limit' => 100,
        ]);
    }

    private function businessAdAccounts(string $businessId, string $edge): array
    {
        try {
            return $this->paginate("/{$businessId}/{$edge}", [
                'fields' => 'account_id,id,name,currency,account_status',
                'limit' => 100,
            ]);
        } catch (MetaAdsException $exception) {
            if ($exception->retryable() || $exception->metaCode === 190 || $exception->httpStatus === 401) {
                throw $exception;
            }
            MetaFlowLog::warning('business ad account edge skipped', [
                'business_id' => $businessId,
                'edge' => $edge,
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);

            return [];
        }
    }

    private function accountInsights(string $adAccountId, string $level, string $idField, ?string $datePreset): array
    {
        return $this->paginate("/{$adAccountId}/insights", [
            'fields' => implode(',', [$idField, 'spend', 'reach', 'actions', 'cost_per_action_type', 'inline_link_clicks']),
            'date_preset' => $datePreset ?? config('services.meta.insights_date_preset', 'last_30d'),
            'level' => $level,
            'use_unified_attribution_setting' => true,
            'limit' => 100,
        ]);
    }

    private function uniqueAdAccounts(array $accounts): array
    {
        $unique = [];

        foreach ($accounts as $account) {
            $id = $account['id'] ?? null;

            if (! $id) {
                continue;
            }

            $unique[$id] ??= $account;
        }

        return array_values($unique);
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
            ->timeout(config('services.meta.timeout'));

        try {
            $response = $method === 'POST'
                ? $request->asForm()->post($url, $payload)
                : $request->get($url, $payload);
        } catch (ConnectionException) {
            throw new MetaAdsException('Koneksi Meta gagal. Coba lagi beberapa saat.', transient: true, outcomeUnknown: $method === 'POST');
        }

        if ($response->failed()) {
            $this->throwMetaException($response);
        }

        $data = $response->json() ?? [];

        if (($data['success'] ?? true) === false) {
            throw new MetaAdsException('Meta Graph API menolak perubahan.', $response->status());
        }

        return $data;
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
        $metaSubcode = $error['error_subcode'] ?? null;
        $providerMessage = $this->safeProviderMessage($error['error_user_msg'] ?? $error['message'] ?? null);

        if ($this->isRateLimitError($metaCode)) {
            $retryAfter = $this->parseRetryAfter($response);
            MetaFlowLog::warning('rate limit hit', [
                'meta_code' => $metaCode,
                'meta_type' => $metaType,
                'retry_after_seconds' => $retryAfter,
            ]);
        }

        throw new MetaAdsException(
            match (true) {
                $metaCode === 190 => 'Access token Meta tidak valid atau sudah expired.',
                $this->isRateLimitError($metaCode), $response->status() === 429 => 'Meta rate limit tercapai. Coba lagi setelah jeda.',
                $response->status() >= 500 => 'Layanan Meta sementara bermasalah.',
                in_array($metaCode, [10, 200], true), $response->status() === 403 => 'Permission Meta tidak mencukupi.',
                default => 'Meta menolak request. Periksa data akun.',
            },
            $response->status(),
            $metaCode,
            $metaType,
            $this->parseRetryAfter($response),
            (bool) ($error['is_transient'] ?? false),
            $response->status() >= 500,
            $metaSubcode,
            $providerMessage,
        );
    }

    private function safeProviderMessage(mixed $message): ?string
    {
        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        $message = preg_replace('/\s+/', ' ', trim($message)) ?: '';
        if ($this->accessToken !== '') {
            $message = str_replace($this->accessToken, '[token]', $message);
        }

        return strlen($message) > 240 ? substr($message, 0, 237).'...' : $message;
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
        if (filled($retryAfter)) {
            return min(3600, max(0, is_numeric($retryAfter) ? (int) $retryAfter : (int) strtotime($retryAfter) - time()));
        }

        $businessUsage = $response->header('X-Business-Use-Case-Usage');
        if ($businessUsage) {
            $decoded = json_decode($businessUsage, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $usages) {
                    if (isset($usages[0]['estimated_time_to_regain_access'])) {
                        return min(3600, max(0, (int) $usages[0]['estimated_time_to_regain_access'] * 60));
                    }
                }
            }
        }

        return null;
    }

    public function adAccount(string $adAccountId): array
    {
        return $this->get("/{$adAccountId}", [
            'fields' => 'account_id,id,name,currency,account_status',
        ]);
    }
}
