<?php

// Fail before Laravel boots (and before RefreshDatabase can run migrations).
$basePath = dirname(__DIR__);
$cachePath = getenv('APP_CONFIG_CACHE') ?: $basePath.'/bootstrap/cache/config.php';
if (! str_starts_with($cachePath, '/')) {
    $cachePath = $basePath.'/'.$cachePath;
}
if (is_file($cachePath)) {
    throw new RuntimeException('Testing refuses cached application config. Use an uncached testing checkout.');
}
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('DB_DATABASE') !== ':memory:' || getenv('DB_URL')) {
    throw new RuntimeException('Tests require isolated SQLite :memory: with no DB_URL. Use phpunit.xml.');
}

require $basePath.'/vendor/autoload.php';
