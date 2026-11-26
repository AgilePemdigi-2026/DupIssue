<?php

// Opt-in standalone harness: PHPUnit's mandatory SQLite guards are unchanged.
use App\Http\Controllers\GithubWebhookController;
use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\GithubAppIssueFetchService;
use App\Services\GithubIssueImportService;
use App\Services\GithubIssuePreviewService;
use App\Services\GithubWebhookDispatchService;
use App\Services\GithubWebhookSyncService;
use App\Services\VoteService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 3);
$backend = $root.'/backend';
if (getenv('APP_ENV') !== 'testing' || is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Uncached isolated test environment required.');
}
$qa = realpath($root.'/.qa');
if ($qa !== $root.'/.qa' || ! is_dir($qa.'/mysql')) {
    throw new RuntimeException('Dedicated QA datadir required.');
}
$socket = $qa.'/mysql.sock';
$pdo = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$datadir = rtrim($pdo->query('SELECT @@datadir')->fetchColumn(), '/');
if (realpath($datadir) !== realpath($qa.'/mysql')) {
    throw new RuntimeException('Refusing non-QA MySQL server.');
}
$database = 'dupissue_qa_'.date('YmdHis').'_'.getmypid();
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = null;
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysqlqa', 'database.connections' => ['mysqlqa' => [
    'driver' => 'mysql', 'unix_socket' => $socket, 'database' => $database, 'username' => 'root', 'password' => '',
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
]], 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'database',
    'queue.connections.database' => ['driver' => 'database', 'connection' => 'mysqlqa', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false],
    'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'services.github_app.webhook_secret' => 'synthetic-local-mysql-webhook-secret-only']);
DB::purge();
Artisan::call('migrate', ['--database' => 'mysqlqa', '--force' => true]);
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }echo 'PASS '.$message.PHP_EOL;
}
function race(array $operations): void
{
    DB::disconnect();
    $children = [];
    $start = microtime(true) + .15;
    foreach ($operations as $operation) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork');
        }
        if ($pid === 0) {
            DB::purge();
            usleep((int) max(0, ($start - microtime(true)) * 1000000));
            try {
                $operation();
                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
                exit(1);
            }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Concurrent child failed');
        }
    }
    DB::purge();
}
$project = Project::factory()->create();
$reporters = [User::factory()->create(['role' => 'REPORTER'])->refresh(), User::factory()->create(['role' => 'REPORTER'])->refresh()];
foreach ($reporters as $reporter) {
    Membership::factory()->create(['project_id' => $project->id, 'user_id' => $reporter->id, 'is_active' => true]);
}
$issue = Issue::forceCreate(['project_id' => $project->id, 'title' => 'Concurrent voting issue', 'description' => 'Synthetic MySQL concurrency fixture']);
$actions = [];
for ($i = 0; $i < 8; $i++) {
    $actor = $reporters[$i % 2];
    $actions[] = fn () => app(VoteService::class)->execute($actor, $project, $issue, true);
}
race($actions);
check(DB::table('votes')->count() === 2 && (int) $issue->fresh()->vote_count === 2, 'concurrent idempotent votes preserve unique ballots and count');
check(DB::table('activity_logs')->where('action', 'VOTE_ADDED')->count() === 2, 'concurrent vote audit occurs exactly once per actor');
$actions = [];
for ($i = 0; $i < 8; $i++) {
    $actor = $reporters[$i % 2];
    $actions[] = fn () => app(VoteService::class)->execute($actor, $project, $issue, false);
}race($actions);
check(DB::table('votes')->count() === 0 && (int) $issue->fresh()->vote_count === 0, 'concurrent unvote never becomes negative');
$connection = new GithubAppConnection;
$connection->forceFill(['project_id' => $project->id, 'installation_id' => 12, 'repository_id' => 34, 'repo_full_name' => 'example/repo', 'verified_github_user_id' => 56, 'verified_at' => now()])->save();
$raw = json_encode(['action' => 'opened', 'installation' => ['id' => 12], 'repository' => ['id' => 34], 'issue' => ['number' => 7]]);
$receive = function () use ($raw) {
    $request = Request::create('/api/github/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'issues', 'HTTP_X_GITHUB_DELIVERY' => '11111111-2222-3333-4444-555555555555',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, config('services.github_app.webhook_secret'))], $raw);
    $response = app(GithubWebhookController::class)($request, app(GithubWebhookDispatchService::class));
    if ($response->status() !== 202) {
        throw new RuntimeException('Webhook not accepted');
    }
};
race([$receive, $receive, $receive, $receive]);
check(DB::table('github_webhook_deliveries')->count() === 1 && DB::table('github_webhook_tasks')->count() === 1 && DB::table('jobs')->count() === 1, 'concurrent webhook redelivery produces one durable task and queue job');
$remote = ['number' => 7, 'title' => 'Synthetic imported issue', 'body' => 'Synthetic issue description for concurrency testing', 'state' => 'open'];
$app->instance(GithubAppIssueFetchService::class, new class($remote) extends GithubAppIssueFetchService
{
    public function __construct(private array $remote) {}

    public function fetchIssue(GithubAppConnection $connection, int $number): array
    {
        return $this->remote;
    }
});
$preview = app(GithubIssuePreviewService::class)->preview($project, 'example/repo', [$remote]);
$fingerprint = hash('sha256', json_encode($preview['rows'], JSON_THROW_ON_ERROR));
$taskId = (int) DB::table('github_webhook_tasks')->value('id');
$manual = function () use ($project, $connection, $remote, $fingerprint) {
    try {
        app(GithubIssueImportService::class)->importFromApp($project, $project->owner, $connection->id, 12, 34, 'example/repo', [$remote], $fingerprint);
    } catch (ValidationException $e) {
        if (! Issue::where('project_id', $project->id)->where('github_number', 7)->exists()) {
            throw $e;
        }
    }
};
$automatic = fn () => app(GithubWebhookSyncService::class)->process($taskId);
race([$manual, $automatic, $automatic]);
check(Issue::where('source_type', 'GITHUB')->count() === 1, 'manual import and concurrent webhook share one stable GitHub identity');
check(DB::table('activity_logs')->whereIn('action', ['SYNC_GITHUB', 'IMPORT_GITHUB'])->count() === 1, 'manual/webhook race writes one import audit');
check(DB::table('jobs')->where('queue', 'nlp')->count() === 1, 'manual/webhook race dispatches NLP once after commit');
// A queued old-repository task must fail after replacement commits under project lock.
$deliveryId = DB::table('github_webhook_deliveries')->insertGetId(['delivery_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'body_hash' => hash('sha256', 'synthetic'),
    'installation_id' => 12, 'repository_id' => 34, 'issue_number' => 8, 'received_at' => now()]);
$oldTask = DB::table('github_webhook_tasks')->insertGetId(['delivery_id' => $deliveryId, 'connection_id' => $connection->id, 'project_id' => $project->id, 'repository' => 'example/repo',
    'status' => 'RECEIVED', 'created_at' => now(), 'updated_at' => now()]);
$signal = $qa.'/replacement-'.getmypid();
$replace = function () use ($project, $connection, $signal) {
    DB::transaction(function () use ($project, $connection, $signal) {
        Project::whereKey($project->id)->lockForUpdate()->first();
        GithubAppConnection::whereKey($connection->id)->update(['repository_id' => 99, 'repo_full_name' => 'example/replacement']);
        file_put_contents($signal, 'locked');
        usleep(300000);
    });
};
$stale = function () use ($oldTask, $signal) {
    $deadline = microtime(true) + 5;
    while (! is_file($signal) && microtime(true) < $deadline) {
        usleep(10000);
    }app(GithubWebhookSyncService::class)->process($oldTask);
};
race([$replace, $stale]);
check(DB::table('github_webhook_tasks')->where('id', $oldTask)->value('status') === 'REJECTED', 'repository replacement race rejects stale automatic import');
check(Issue::where('source_type','GITHUB')->count() === 1,'repository replacement preserves historical issue');
echo json_encode(['database' => $database, 'mysql_version' => DB::selectOne('SELECT VERSION() AS version')->version, 'checks' => 9, 'status' => 'PASS_LIVE_LOCAL_MYSQL'],JSON_PRETTY_PRINT).PHP_EOL;
