<?php

// Run before Laravel boots, including when the shell exports development settings.
require dirname(__DIR__).'/vendor/autoload.php';

$configCache = dirname(__DIR__).'/bootstrap/cache/config.php';
if (is_file($configCache)) {
    throw new RuntimeException('Refusing tests with cached application config. Remove the local config cache first.');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_CONFIG_CACHE' => $configCache,
    'APP_ROUTES_CACHE' => __DIR__.'/cache/routes.php',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DB_QUEUE_CONNECTION' => 'sqlite',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
