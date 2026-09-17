<?php

// This must run before Laravel, including the parent `artisan test` process.
if (! defined('SUBTITLE_TEST_STORAGE')) {
    define('SUBTITLE_TEST_STORAGE', sys_get_temp_dir().'/subtitle-tests-'.bin2hex(random_bytes(12)));
    foreach (['app/private', 'app/public', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
        if (! mkdir(SUBTITLE_TEST_STORAGE.'/'.$directory, 0700, true) && ! is_dir(SUBTITLE_TEST_STORAGE.'/'.$directory)) {
            throw new RuntimeException('Cannot create disposable test storage.');
        }
    }
    // Tests must not read settings or credentials from the runtime .env file.
    if (file_put_contents(SUBTITLE_TEST_STORAGE.'/.env', '') === false) {
        throw new RuntimeException('Cannot create disposable test environment.');
    }
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'APP_CONFIG_CACHE' => SUBTITLE_TEST_STORAGE.'/config.php',
    'APP_ROUTES_CACHE' => SUBTITLE_TEST_STORAGE.'/routes.php',
    'APP_EVENTS_CACHE' => SUBTITLE_TEST_STORAGE.'/events.php',
    'LARAVEL_STORAGE_PATH' => SUBTITLE_TEST_STORAGE,
    'VIEW_COMPILED_PATH' => SUBTITLE_TEST_STORAGE.'/framework/views',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SUBTITLE_CONCURRENCY_CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SUBTITLE_QUEUE_CONNECTION' => 'sync',
    'FILESYSTEM_DISK' => 'local',
    'LOG_CHANNEL' => 'single',
    'LOG_STACK' => 'single',
    'BCRYPT_ROUNDS' => '4',
    'OPENAI_API_KEY' => 'test-openai-key',
    'CEREBRAS_API_KEY' => 'test-cerebras-key',
    'ELEVENLABS_API_KEY' => 'test-elevenlabs-key',
    'STRIPE_SECRET' => 'sk_test_isolated',
    'STRIPE_WEBHOOK_SECRET' => 'whsec_isolated',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}

require_once __DIR__.'/../vendor/autoload.php';
