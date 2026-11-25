<?php

// Test-only server/worker, ALWAYS a fresh integration-tests/<random> database.
// Never used by integration-app.php and never reads development credentials.
require_once __DIR__.'/Environment.php';
use DupIssueQa\{Environment, OutboundPolicy};
use Illuminate\Support\Facades\{Artisan, DB, Http};

$profile = getenv('QA_TEST_PROFILE');
if (! is_string($profile) || ! preg_match('~\Aintegration-tests/[a-f0-9]{16}\z~', $profile)) {
    throw new RuntimeException('TEST_PROFILE_REQUIRED');
}
$qa = new Environment($profile);
$mode = PHP_SAPI === 'cli-server' ? 'http' : ($argv[1] ?? 'status');
if ($mode === 'init') {
    $qa->initialize();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($qa->directory.'/private-key.pem', $pem);
    $settings = json_decode(file_get_contents(__DIR__.'/github-config.example.json'), true);
    $settings['GITHUB_APP_ID'] = '1234';
    $settings['GITHUB_APP_CLIENT_ID'] = 'Iv1.qa-test-only';
    $settings['GITHUB_APP_CLIENT_SECRET'] = 'test-only-not-a-github-credential';
    $settings['GITHUB_APP_PRIVATE_KEY_PATH'] = $qa->directory.'/private-key.pem';
    $settings['GITHUB_APP_WEBHOOK_SECRET'] = 'test-only-webhook-secret-with-more-than-32-characters';
    file_put_contents($qa->directory.'/github-config.json', json_encode($settings));
    echo json_encode($qa->status()); exit;
}
$app = $qa->boot(true); // Structure validated with generated test-only key/config.
Http::preventStrayRequests();
Http::allowStrayRequests(['http://127.0.0.1:18765/health', 'http://127.0.0.1:18765/candidates']);
Http::fake(function ($request) {
    $url = $request->url();
    if (str_starts_with($url, 'http://127.0.0.1:18765/')) return null; // Actual offline NLP, optional test opt-in.
    OutboundPolicy::enforce($request->method(), $url, true);
    $repo = ['id' => 34, 'full_name' => 'valentinokevin/dupissue-testing', 'has_issues' => true];
    $installation = ['id' => 12, 'app_id' => 1234, 'permissions' => ['issues' => 'read'], 'suspended_at' => null];
    if ($url === 'https://github.com/login/oauth/access_token') return Http::response(['access_token' => 'test-only-user-token', 'token_type' => 'bearer']);
    if ($url === 'https://api.github.com/user') return Http::response(['id' => 56, 'login' => 'synthetic-test-user']);
    if (str_starts_with($url, 'https://api.github.com/user/installations?')) return Http::response(['total_count' => 1, 'installations' => [$installation]]);
    if ($url === 'https://api.github.com/app/installations/12') return Http::response($installation);
    if (str_starts_with($url, 'https://api.github.com/user/installations/12/repositories?')) return Http::response(['total_count' => 1, 'repositories' => [$repo]]);
    if ($url === 'https://api.github.com/app/installations/12/access_tokens') return Http::response(['token' => 'test-only-installation-token', 'expires_at' => now()->addHour()->toIso8601String(), 'permissions' => ['issues' => 'read']], 201);
    if ($url === 'https://api.github.com/repos/valentinokevin/dupissue-testing') return Http::response($repo);
    $issue = ['number' => 7, 'title' => 'Checkout payment fails', 'body' => 'Clicking the payment button does not complete checkout.', 'state' => 'open', 'repository_url' => 'https://api.github.com/repos/valentinokevin/dupissue-testing'];
    if ($url === 'https://api.github.com/repos/valentinokevin/dupissue-testing/issues/7') return Http::response($issue);
    if (str_starts_with($url, 'https://api.github.com/repos/valentinokevin/dupissue-testing/issues?')) return Http::response([$issue]);
    throw new RuntimeException('UNMOCKED_TEST_HTTP');
});
if ($mode === 'http') {
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:18080') { http_response_code(403); exit; }
    $app->handleRequest(\Illuminate\Http\Request::capture());
} elseif ($mode === 'drain') {
    $exit = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default,nlp', '--stop-when-empty' => true, '--max-jobs' => 20, '--max-time' => 120, '--sleep' => 0, '--timeout' => 60, '--force' => true]);
    echo json_encode(['exit' => $exit] + $qa->status());
} elseif ($mode === 'schedule') {
    echo json_encode(['executed' => $qa->scheduleTick()]);
} elseif ($mode === 'age-pending') {
    DB::table('issues')->where('detection_status', 'PENDING')->update(['updated_at' => now()->subMinutes(6)]);
    echo '{"synthetic_pending_aged":true}';
} elseif ($mode === 'safety') {
    $checks = 0;
    $reject = function (callable $call) use (&$checks): void {
        try { $call(); } catch (Throwable) { $checks++; return; }
        throw new RuntimeException('EXPECTED_REJECTION');
    };
    $reject(fn () => new Environment('../interactive'));
    $reject(fn () => $qa->initialize());
    $file = $qa->directory.'/github-config.json';
    $original = file_get_contents($file);
    try {
        foreach (['GITHUB_APP_ID' => '0', 'GITHUB_APP_SLUG' => 'other-app', 'GITHUB_APP_CALLBACK_URL' => 'https://wrong.invalid/callback',
            'GITHUB_APP_WEBHOOK_SECRET' => 'short', 'GITHUB_APP_PRIVATE_KEY_PATH' => '/outside/private.pem', 'UNKNOWN_KEY' => 'not-allowed'] as $key => $value) {
            $data = json_decode($original, true); $data[$key] = $value;
            file_put_contents($file, json_encode($data));
            $reject(fn () => $qa->credentials());
        }
        file_put_contents($file, $original);
        chmod($file, 0644); clearstatcache();
        $reject(fn () => $qa->credentials());
    } finally { chmod($file, 0600); file_put_contents($file, $original); clearstatcache(); }
    \Illuminate\Support\Facades\Log::error('SYNTHETIC_SECRET_MUST_NOT_LOG', ['payload' => 'SYNTHETIC_SECRET_MUST_NOT_LOG']);
    if (str_contains(file_get_contents($qa->directory.'/storage/logs/laravel.log'), 'SYNTHETIC_SECRET_MUST_NOT_LOG')) throw new RuntimeException('LOG_LEAK');
    $checks++;
    // Default mode must not parse/read credential values and cannot use GitHub.
    try {
        file_put_contents($file, 'invalid credential JSON intentionally never parsed');
        $qa->boot(false);
        $reject(fn () => Http::get('https://api.github.com/user'));
    } finally { file_put_contents($file, $original); }
    // Retain this deliberately invalid scratch profile; no files are deleted.
    $badProfile = 'integration-tests/'.bin2hex(random_bytes(8));
    $bad = new Environment($badProfile);
    mkdir($bad->directory, 0700, true);
    symlink($qa->directory.'/key', $bad->directory.'/linked-key');
    $reject(fn () => new Environment($badProfile));
    echo json_encode(['safety_checks' => $checks]);
} elseif ($mode === 'status') {
    echo json_encode($qa->status() + ['deliveries' => DB::table('github_webhook_deliveries')->count(),
        'tasks_completed' => DB::table('github_webhook_tasks')->where('status', 'COMPLETED')->count(),
        'github_issues' => DB::table('issues')->where('source_type', 'GITHUB')->count(),
        'candidates' => DB::table('duplicate_candidates')->count(),
        'sync_logs' => DB::table('activity_logs')->where('action', 'SYNC_GITHUB')->count(),
        'last_synced' => DB::table('github_app_connections')->value('last_synced_at'),
        'foreign_key_errors' => count(DB::select('PRAGMA foreign_key_check'))]);
} elseif ($mode === 'isolation') {
    // Inherited hostile env must not select any alternate DB/session/cache/queue.
    if (config('database.default') !== 'sqlite' || count(config('database.connections')) !== 1
        || config('queue.connections.database.connection') !== 'sqlite'
        || config('session.connection') !== 'sqlite' || config('cache.stores.database.connection') !== 'sqlite') throw new RuntimeException('ISOLATION_FAILED');
    foreach (['https://unapproved.invalid/private', 'https://api.github.com/repos/other/private'] as $url) {
        try { Http::get($url); throw new RuntimeException('DENY_FAILED'); }
        catch (RuntimeException $e) { if ($e->getMessage() !== 'QA_OUTBOUND_DENIED') throw $e; }
    }
    echo '{"isolation":"PASS"}';
} else { throw new RuntimeException('TEST_MODE_INVALID'); }
