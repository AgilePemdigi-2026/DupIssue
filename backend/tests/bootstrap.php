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

// PHPUnit's force=true updates getenv()/$_ENV, while PHP may retain the shell's
// old values in $_SERVER. Laravel's environment repository reads both.
// Synchronize only the values already validated above; never replace APP_KEY.
foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL'] as $name) {
    $_ENV[$name] = $_SERVER[$name] = getenv($name);
}

require $basePath.'/vendor/autoload.php';
