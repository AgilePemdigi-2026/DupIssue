<?php

namespace DupIssueQa;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Artisan, Cache, DB, Hash, Http, Log};
use RuntimeException;

require_once __DIR__.'/OutboundPolicy.php';

final class Environment
{
    public readonly string $root;
    public readonly string $directory;
    public readonly string $backend;

    public function __construct(string $profile = 'github-integration')
    {
        if (! preg_match('~\A(?:github-integration|integration-tests/[a-f0-9]{16})\z~', $profile)) {
            throw new RuntimeException('QA_PROFILE_DENIED');
        }
        umask(0077);
        $this->backend = dirname(__DIR__, 3);
        $this->root = dirname($this->backend);
        $this->directory = $this->root.'/.qa/'.$profile;
        $this->assertPaths();
    }

    private function assertPaths(): void
    {
        $path = $this->root;
        foreach (explode('/', substr($this->directory, strlen($this->root) + 1)) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('QA_SYMLINK_DENIED');
            }
        }
        if (is_dir($this->directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file->isLink() || ($file->getPerms() & 0077) !== 0 || ($file->isFile() && lstat($file->getPathname())['nlink'] !== 1)) {
                    throw new RuntimeException('QA_PRIVATE_PATH_REQUIRED');
                }
            }
            if ((fileperms($this->directory) & 0077) !== 0) {
                throw new RuntimeException('QA_PRIVATE_DIRECTORY_REQUIRED');
            }
        }
        if (is_file($this->backend.'/bootstrap/cache/config.php')) {
            throw new RuntimeException('QA_CONFIG_CACHE_DENIED');
        }
    }

    public function initialize(): void
    {
        if (file_exists($this->directory)) {
            throw new RuntimeException('QA_REFUSE_OVERWRITE');
        }
        foreach (['empty-env', 'bootstrap-cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'storage/app/private'] as $suffix) {
            mkdir($this->directory.'/'.$suffix, 0700, true);
        }
        file_put_contents($this->directory.'/key', 'base64:'.base64_encode(random_bytes(32)));
        fclose(fopen($this->directory.'/database.sqlite', 'x'));
        copy(__DIR__.'/github-config.example.json', $this->directory.'/github-config.json');
        chmod($this->directory.'/github-config.json', 0600);
        $this->boot(false);
        Artisan::call('migrate', ['--force' => true]);
        $password = bin2hex(random_bytes(24));
        $owner = \App\Models\User::forceCreate(['name' => 'Integration QA Maintainer', 'email' => 'maintainer@integration.qa.invalid', 'role' => 'MAINTAINER', 'password' => Hash::make($password), 'email_verified_at' => now()]);
        $reporter = \App\Models\User::forceCreate(['name' => 'Integration QA Reporter', 'email' => 'reporter@integration.qa.invalid', 'role' => 'REPORTER', 'password' => Hash::make($password), 'email_verified_at' => now()]);
        foreach (['Integration Checkout', 'Integration Mobile'] as $name) {
            $project = \App\Models\Project::forceCreate(['owner_id' => $owner->id, 'name' => $name, 'description' => 'Synthetic integration QA; GitHub connection not yet authorized.']);
            \App\Models\Membership::forceCreate(['project_id' => $project->id, 'user_id' => $reporter->id, 'is_active' => true]);
            foreach (['Checkout payment fails', 'Checkout button does not respond', 'Unable to complete payment'] as $title) {
                \App\Models\Issue::forceCreate(['project_id' => $project->id, 'title' => $title, 'description' => 'Clicking the payment button does not complete checkout.', 'status' => 'OPEN', 'source_type' => 'FORM', 'author_id' => $reporter->id, 'detection_status' => 'PENDING']);
            }
        }
        file_put_contents($this->directory.'/accounts.json', json_encode(['reporter' => $reporter->email, 'maintainer' => $owner->email, 'password' => $password], JSON_PRETTY_PRINT));
        // No GitHub mapping is seeded, and no external HTTP is needed for initialization.
    }

    public function credentials(): array
    {
        $this->assertPaths();
        $data = json_decode(file_get_contents($this->directory.'/github-config.json'), true, 8, JSON_THROW_ON_ERROR);
        $keys = array_keys(json_decode(file_get_contents(__DIR__.'/github-config.example.json'), true));
        if (! is_array($data) || array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) {
            throw new RuntimeException('QA_CONFIG_KEYS_INVALID');
        }
        foreach ($keys as $key) {
            if (! is_string($data[$key]) || $data[$key] === '' || strlen($data[$key]) > 4096 || preg_match('/[\x00-\x1f]/', $data[$key])) {
                throw new RuntimeException('QA_CONFIG_INCOMPLETE');
            }
        }
        if (! preg_match('/\A[1-9][0-9]*\z/', $data['GITHUB_APP_ID'])
            || $data['GITHUB_APP_SLUG'] !== 'dupissue-kel4-dev-valentinokevin'
            || $data['GITHUB_APP_CALLBACK_URL'] !== 'http://127.0.0.1:18080/integrations/github/callback'
            || strlen($data['GITHUB_APP_WEBHOOK_SECRET']) < 32
            || $data['GITHUB_APP_PRIVATE_KEY_PATH'] !== $this->directory.'/private-key.pem'
            || ! is_file($this->directory.'/private-key.pem')) {
            throw new RuntimeException('QA_CONFIG_INVALID');
        }
        return $data;
    }

    public function boot(bool $github = false): \Illuminate\Foundation\Application
    {
        $this->assertPaths();
        foreach (['key', 'database.sqlite'] as $file) {
            if (! is_file($this->directory.'/'.$file)) {
                throw new RuntimeException('QA_INITIALIZE_FIRST');
            }
        }
        // Credentials are never loaded at all in default/offline mode.
        $credentials = $github ? $this->credentials() : [];
        $d = $this->directory;
        $env = ['APP_ENV' => 'integration-qa', 'APP_DEBUG' => 'false', 'APP_KEY' => file_get_contents($d.'/key'),
            'APP_URL' => 'http://127.0.0.1:18080', 'APP_MAINTENANCE_DRIVER' => 'file',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $d.'/database.sqlite', 'DB_URL' => '', 'DATABASE_URL' => '',
            'CACHE_STORE' => 'database', 'DB_CACHE_CONNECTION' => 'sqlite', 'DB_CACHE_LOCK_CONNECTION' => 'sqlite',
            'DB_CACHE_TABLE' => 'cache', 'DB_CACHE_LOCK_TABLE' => 'cache_locks',
            'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'sqlite', 'SESSION_TABLE' => 'sessions',
            'SESSION_COOKIE' => 'dupissue_github_integration_qa', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => 'null',
            'QUEUE_CONNECTION' => 'database', 'DB_QUEUE_CONNECTION' => 'sqlite', 'DB_QUEUE_RETRY_AFTER' => '90',
            'DB_QUEUE_TABLE' => 'jobs', 'DB_QUEUE' => 'default', 'QUEUE_FAILED_DRIVER' => 'database-uuids',
            'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'single', 'LOG_DEPRECATIONS_CHANNEL' => 'null',
            'NLP_SERVICE_URL' => 'http://127.0.0.1:18765', 'VIEW_COMPILED_PATH' => $d.'/storage/framework/views'];
        foreach (['CONFIG', 'ROUTES', 'EVENTS', 'SERVICES', 'PACKAGES'] as $cache) {
            $env['APP_'.$cache.'_CACHE'] = $d.'/bootstrap-cache/'.strtolower($cache).'.php';
            if (in_array($cache, ['CONFIG', 'ROUTES', 'EVENTS']) && file_exists($env['APP_'.$cache.'_CACHE'])) {
                throw new RuntimeException('QA_CACHED_RUNTIME_DENIED');
            }
        }
        foreach (array_keys(json_decode(file_get_contents(__DIR__.'/github-config.example.json'), true)) as $key) {
            $env[$key] = $credentials[$key] ?? '';
        }
        foreach ($env as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        require_once $this->backend.'/vendor/autoload.php';
        $app = require $this->backend.'/bootstrap/app.php';
        $app->useEnvironmentPath($d.'/empty-env');
        $app->useStoragePath($d.'/storage');
        $app->make(Kernel::class)->bootstrap();
        config(['database.default' => 'sqlite', 'database.connections' => ['sqlite' => [
            'driver' => 'sqlite', 'database' => $d.'/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]],
            'session.domain' => null, 'session.path' => '/', 'session.same_site' => 'lax',
            'queue.failed.database' => 'sqlite', 'queue.failed.table' => 'failed_jobs', 'mail.default' => 'array']);
        DB::purge('sqlite');
        if (DB::connection()->getDatabaseName() !== $d.'/database.sqlite' || DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('QA_DATABASE_BOUNDARY_FAILED');
        }
        // App messages/exceptions can contain SQL bindings or OAuth queries: discard
        // message/context rather than attempting incomplete secret-string redaction.
        Log::getLogger()->pushProcessor(fn (\Monolog\LogRecord $record) => $record->with(message: 'QA_APPLICATION_EVENT', context: [], extra: []));
        Http::globalOptions(['allow_redirects' => false, 'proxy' => '', 'debug' => false, 'connect_timeout' => 5, 'timeout' => 15]);
        Http::globalRequestMiddleware(function ($request) use ($github) {
            OutboundPolicy::enforce($request->getMethod(), (string) $request->getUri(), $github);
            return $request;
        });
        return $app;
    }

    public function scheduleTick(): array
    {
        $executed = [];
        foreach (['github:webhooks-recover' => 60, 'nlp:recover-pending' => 300, 'extension:sessions-prune' => 3600] as $command => $interval) {
            $lock = Cache::lock('integration-qa:'.$command, 120);
            if (! $lock->get()) continue;
            try {
                $key = 'integration-qa:last:'.$command;
                if (time() - (int) Cache::get($key, 0) >= $interval) {
                    if (Artisan::call($command, str_contains($command, 'recover') ? ['--limit' => 100] : []) !== 0) {
                        throw new RuntimeException('QA_RECOVERY_FAILED');
                    }
                    Cache::put($key, time(), 7200);
                    $executed[] = $command;
                }
            } finally {
                $lock->release();
            }
        }
        return $executed;
    }

    public function status(): array
    {
        return ['profile' => basename($this->directory), 'database' => 'isolated SQLite',
            'issues' => \App\Models\Issue::count(), 'completed' => \App\Models\Issue::where('detection_status', 'COMPLETED')->count(),
            'github_connections' => \App\Models\GithubAppConnection::count(),
            'jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count()];
    }
}
