<?php

namespace App\Console\Commands;

use App\Exceptions\MetaAdsException;
use App\Models\AdAccount;
use App\Models\T4JamProfile;
use App\Services\MetaAdsClient;
use Illuminate\Console\Command;

class ConfigureMetaAdsWebhook extends Command
{
    protected $signature = 't4jam:configure-meta-webhook {--profile_id=} {--callback_url=}';

    protected $description = 'Register the Meta ad account webhook and subscribe accessible ad accounts';

    public function handle(): int
    {
        $profile = T4JamProfile::query()
            ->when($this->option('profile_id'), fn ($query, $id) => $query->whereKey($id))
            ->whereNotNull('access_token')
            ->first();
        $callbackUrl = (string) ($this->option('callback_url') ?: config('services.meta.webhook_callback_url'));
        $verifyToken = (string) config('services.meta.webhook_verify_token');
        $fields = config('services.meta.webhook_fields', []);

        if (! $profile || ! filled($profile->app_id) || ! filled($profile->app_secret)) {
            $this->error('Profile Meta lengkap dengan app ID, app secret, dan access token tidak ditemukan.');

            return self::FAILURE;
        }

        if ($verifyToken === '' || ! str_starts_with($callbackUrl, 'https://') || $fields === []) {
            $this->error('META_WEBHOOK_VERIFY_TOKEN, callback HTTPS, dan META_WEBHOOK_FIELDS wajib dikonfigurasi.');

            return self::FAILURE;
        }

        try {
            MetaAdsClient::configureWebhookSubscription(
                (string) $profile->app_id,
                (string) $profile->app_secret,
                $callbackUrl,
                $verifyToken,
                $fields,
            );

            $client = new MetaAdsClient((string) $profile->access_token);
            $accounts = $client->adAccounts();

            foreach ($accounts as $accountData) {
                $externalId = (string) ($accountData['id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $client->subscribeAdAccount($externalId, (string) $profile->app_id);
                $account = AdAccount::query()->where('external_id', $externalId)->first();
                if ($account) {
                    $profile->adAccounts()->syncWithoutDetaching([$account->id]);
                }
            }
        } catch (MetaAdsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Webhook Meta aktif untuk %d ad account.', count($accounts)));

        return self::SUCCESS;
    }
}
