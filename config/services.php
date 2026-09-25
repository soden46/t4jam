<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/social-auth/complete/google-oauth2/'),
    ],

    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        'base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
        'enable_writes' => env('META_ADS_ENABLE_WRITES', false),
        'timeout' => (int) env('META_GRAPH_TIMEOUT', 45),
        'retry_times' => (int) env('META_GRAPH_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('META_GRAPH_RETRY_SLEEP_MS', 500),
        'insights_date_preset' => env('META_GRAPH_INSIGHTS_DATE_PRESET', 'last_30d'),
        'automation_insights_date_preset' => env(
            'META_GRAPH_AUTOMATION_INSIGHTS_DATE_PRESET',
            env('META_GRAPH_INSIGHTS_DATE_PRESET', 'last_30d'),
        ),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'webhook_app_secret' => env('META_WEBHOOK_APP_SECRET'),
        'webhook_callback_url' => env('META_WEBHOOK_CALLBACK_URL', rtrim((string) env('APP_URL'), '/').'/meta/webhook/'),
        'webhook_fields' => array_values(array_filter(array_map('trim', explode(',', env('META_WEBHOOK_FIELDS', 'campaigns,adsets,ads'))))),
        'webhook_sync_mode' => env('META_WEBHOOK_SYNC_MODE', 'after_response'),
        'auto_post_deploy_sync' => env('META_AUTO_POST_DEPLOY_SYNC', env('APP_ENV') === 'production'),
        'auto_post_deploy_profile_id' => env('META_AUTO_POST_DEPLOY_PROFILE_ID'),
        'auto_post_deploy_configure_webhook' => env('META_AUTO_POST_DEPLOY_CONFIGURE_WEBHOOK', false),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
