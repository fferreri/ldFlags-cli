<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LaunchDarkly API Key
    |--------------------------------------------------------------------------
    |
    | API key to connect to the LaunchDarkly service.
    | You can obtain this key from your LaunchDarkly account at:
    | https://app.launchdarkly.com/settings/authorization
    |
    */
    'api_key' => env('LAUNCHDARKLY_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | LaunchDarkly API URL
    |--------------------------------------------------------------------------
    |
    | Base URL for the LaunchDarkly API.
    |
    */
    'api_url' => 'https://app.launchdarkly.com/api/v2',

    /*
    |--------------------------------------------------------------------------
    | LaunchDarkly Project Default
    |--------------------------------------------------------------------------
    |
    | Default project ID in LaunchDarkly.
    |
    */
    'default_project' => env('LAUNCHDARKLY_DEFAULT_PROJECT', ''),

    /*
    |--------------------------------------------------------------------------
    | LaunchDarkly Environment Default
    |--------------------------------------------------------------------------
    |
    | Default environment in LaunchDarkly.
    |
    */
    'default_environment' => env('LAUNCHDARKLY_DEFAULT_ENVIRONMENT', ''),
];