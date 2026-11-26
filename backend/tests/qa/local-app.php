<?php

// Isolated interactive QA launcher. Never reads the application's real .env.
use App\Jobs\DetectIssueCandidatesJob;
use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\ConsoleOutput;

$backend = realpath(__DIR__.'/../..');
$root = dirname($backend);
$qa = $root.'/.qa/interactive';
$mode = PHP_SAPI === 'cli-server' ? 'http' : ($argv[1] ?? 'status');
if (! in_array($mode, ['init', 'http', 'worker', 'schedule', 'status'], true)) {
    throw new RuntimeException('Allowed modes: init, worker, schedule, status.');
}
if (is_file($backend.'/bootstrap/cache/config.php') || is_link($root.'/.qa') || is_link($qa)) {
    throw new RuntimeException('Uncached workspace-local QA paths required.');
}
if ($mode === 'init') {
    if (file_exists($qa)) {
        throw new RuntimeException('QA directory already exists; refusing to overwrite.');
    }
    mkdir($qa, 0700, true);
    foreach (['empty-env', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'storage/app/private'] as $dir) {
        mkdir($qa.'/'.$dir, 0700, true);
    }
    file_put_contents($qa.'/key', 'base64:'.base64_encode(random_bytes(32)));
    chmod($qa.'/key', 0600);
    fclose(fopen($qa.'/database.sqlite', 'x'));
    chmod($qa.'/database.sqlite', 0600);
}
if (! is_file($qa.'/key') || ! is_file($qa.'/database.sqlite') || is_link($qa.'/database.sqlite')) {
    throw new RuntimeException('Initialize the dedicated QA directory first.');
}
$env = ['APP_ENV' => 'local-qa', 'APP_DEBUG' => 'false', 'APP_KEY' => file_get_contents($qa.'/key'), 'APP_URL' => 'http://127.0.0.1:18080',
    'APP_CONFIG_CACHE' => $qa.'/no-cached-config.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $qa.'/database.sqlite', 'DB_URL' => '', 'DATABASE_URL' => '',
    'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'dupissue_interactive_qa', 'SESSION_SECURE_COOKIE' => 'false',
    'QUEUE_CONNECTION' => 'database', 'DB_QUEUE_CONNECTION' => 'sqlite', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'single', 'NLP_SERVICE_URL' => 'http://127.0.0.1:18765'];
foreach ($env as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->useEnvironmentPath($qa.'/empty-env');
$app->useStoragePath($qa.'/storage');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections' => ['sqlite' => [
    'driver' => 'sqlite', 'database' => $qa.'/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
]], 'services.github_app' => [], 'mail.default' => 'array']);
DB::purge('sqlite');
if (DB::connection()->getDatabaseName() !== $qa.'/database.sqlite' || DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
    throw new RuntimeException('QA database boundary failed.');
}
Http::preventStrayRequests();
Http::allowStrayRequests(['http://127.0.0.1:18765/*']);

if ($mode === 'init') {
    Artisan::call('migrate', ['--force' => true]);
    $password = bin2hex(random_bytes(16));
    $maintainer = User::forceCreate(['name' => 'QA Maintainer', 'email' => 'maintainer@qa.invalid', 'role' => 'MAINTAINER', 'password' => Hash::make($password), 'email_verified_at' => now()]);
    $reporter = User::forceCreate(['name' => 'QA Reporter', 'email' => 'reporter@qa.invalid', 'role' => 'REPORTER', 'password' => Hash::make($password), 'email_verified_at' => now()]);
    foreach (['QA Checkout', 'QA Mobile Checkout'] as $name) {
        $project = Project::forceCreate(['owner_id' => $maintainer->id, 'name' => $name, 'description' => 'Synthetic local fixtures; no actual GitHub authorization.']);
        Membership::forceCreate(['project_id' => $project->id, 'user_id' => $reporter->id, 'is_active' => true]);
        $connection = new GithubAppConnection;
        $connection->forceFill(['project_id' => $project->id, 'installation_id' => 123, 'repository_id' => 456, 'repo_full_name' => 'valentinokevin/dupissue-testing', 'verified_github_user_id' => 789, 'verified_at' => now()])->save();
        foreach ([['Checkout payment fails', 'Clicking the payment button does not complete checkout.'], ['Checkout button does not respond', 'The checkout button stays disabled after entering payment details.'], ['Unable to complete payment', 'Payment processing fails and the order is never created.'], ['Profile image upload fails', 'Uploading a profile photograph returns an error.']] as [$title,$description]) {
            Issue::forceCreate(['project_id' => $project->id, 'title' => $title, 'description' => $description, 'status' => 'OPEN', 'source_type' => 'FORM', 'author_id' => $reporter->id, 'detection_status' => 'COMPLETED']);
        }
    }
    $pending = Issue::forceCreate(['project_id' => 1, 'title' => 'Checkout payment is broken', 'description' => 'Clicking the payment button does not complete checkout.', 'status' => 'OPEN', 'source_type' => 'FORM', 'author_id' => $reporter->id, 'detection_status' => 'PENDING']);
    Bus::dispatch(new DetectIssueCandidatesJob($pending->id));
    file_put_contents($qa.'/accounts.json', json_encode(['reporter' => 'reporter@qa.invalid', 'maintainer' => 'maintainer@qa.invalid', 'password' => $password], JSON_PRETTY_PRINT));
    chmod($qa.'/accounts.json', 0600);
    echo "QA initialized. Local-only credentials: .qa/interactive/accounts.json\n";
} elseif ($mode === 'http') {
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:18080') {
        http_response_code(403);
        exit;
    }
    $path = realpath($backend.'/public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($path && str_starts_with($path, $backend.'/public/') && is_file($path) && pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
    $app->handleRequest(Request::capture());
} elseif ($mode === 'worker') {
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default,nlp', '--sleep' => 1, '--tries' => 3, '--timeout' => 60, '--max-time' => 7200], new ConsoleOutput);
} elseif ($mode === 'schedule') {
    // Execute the same recovery actions within this guarded process, not an
    // unguarded artisan subprocess that could read a subsequently created .env.
    foreach (['github:webhooks-recover', 'nlp:recover-pending', 'extension:sessions-prune'] as $command) {
        Artisan::call($command, [], new ConsoleOutput);
    }
} else {
    echo json_encode(['database' => 'isolated SQLite', 'issues' => Issue::count(), 'completed' => Issue::where('detection_status', 'COMPLETED')->count(), 'jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count()], JSON_PRETTY_PRINT)."\n";
}
