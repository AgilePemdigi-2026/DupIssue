<?php

// Dedicated integration environment. Default: GitHub disabled, NLP loopback only.
require_once __DIR__.'/integration/Environment.php';

use DupIssueQa\Environment;
use Illuminate\Support\Facades\{Artisan, Http};

ini_set('display_errors', '0');
try {
    $http = PHP_SAPI === 'cli-server';
    $mode = $http ? 'http' : ($argv[1] ?? 'status');
    if (! in_array($mode, ['init', 'serve', 'http', 'worker', 'drain', 'schedule', 'scheduler', 'status', 'check-config', 'nlp-smoke'], true)) {
        throw new RuntimeException('QA_MODE_INVALID');
    }
    if ($http && (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
        || ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1' || ($_SERVER['SERVER_PORT'] ?? '') !== '18080'
        || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:18080')) {
        http_response_code(403); exit;
    }
    $qa = new Environment;
    if ($mode === 'init') {
        $qa->initialize();
        echo "QA initialized; local credentials in .qa/github-integration/accounts.json. GitHub connections: 0.\n";
        exit;
    }
    $github = getenv('QA_GITHUB_NETWORK') === '1';
    if ($mode === 'check-config') {
        $qa->credentials(); // Explicit operator action; never prints values or reads PEM contents.
        echo "QA configuration structure valid; GitHub validity not verified.\n";
        exit;
    }
    $app = $qa->boot($github);
    if ($mode === 'serve') {
        echo "Integration QA on 127.0.0.1:18080; access logs suppressed. GitHub ".($github ? 'allowlisted' : 'disabled').".\n";
        // PHP access logs can include OAuth code/state query strings. Do not capture them.
        // Replace the launcher process: Ctrl-C/SIGTERM cannot orphan a child server.
        // Reopen descriptors 1/2 before exec so PHP's native access log is suppressed.
        fclose(STDOUT); $quietOut = fopen('/dev/null', 'w');
        fclose(STDERR); $quietError = fopen('/dev/null', 'w');
        pcntl_exec(PHP_BINARY, ['-d', 'display_errors=0', '-S', '127.0.0.1:18080', '-t', $qa->backend.'/public', __FILE__]);
        exit(1);
    } elseif ($mode === 'http') {
        // Serve files explicitly; never fall back to the PHP server's document routing.
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $file = realpath($qa->backend.'/public'.$path);
        if ($file && str_starts_with($file, $qa->backend.'/public/') && is_file($file)
            && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['css', 'js', 'svg', 'png', 'ico', 'woff', 'woff2'], true)) {
            header('Content-Type: '.(['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml'][pathinfo($file, PATHINFO_EXTENSION)] ?? mime_content_type($file)));
            readfile($file); exit;
        }
        $app->handleRequest(\Illuminate\Http\Request::capture());
    } elseif (in_array($mode, ['worker', 'drain'], true)) {
        $options = ['connection' => 'database', '--queue' => 'default,nlp', '--sleep' => 1, '--tries' => 3, '--timeout' => 60, '--max-time' => 7200, '--force' => true];
        if ($mode === 'drain') $options += ['--stop-when-empty' => true, '--max-jobs' => 20];
        $exit = Artisan::call('queue:work', $options);
        echo "QA_WORKER_EXIT_".$exit."\n"; exit($exit);
    } elseif ($mode === 'schedule' || $mode === 'scheduler') {
        $until = time() + 7200;
        do {
            echo json_encode(['executed' => $qa->scheduleTick()])."\n";
            if ($mode === 'schedule') break;
            sleep(30);
        } while (time() < $until);
    } elseif ($mode === 'nlp-smoke') {
        $health = Http::get('http://127.0.0.1:18765/health');
        $score = Http::post('http://127.0.0.1:18765/candidates', ['project_id' => 1,
            'new_issue' => ['issue_id' => 1, 'title' => 'Checkout payment fails', 'description' => 'Cannot complete checkout'],
            'candidates' => [['issue_id' => 2, 'project_id' => 1, 'title' => 'Checkout payment fails', 'description' => 'Cannot complete checkout']]]);
        if (! $health->successful() || ! $score->successful()) throw new RuntimeException('QA_NLP_FAILED');
        echo json_encode(['health' => $health->status(), 'nlp_status' => $score->status(), 'candidates' => $score->json('candidates')])."\n";
    } else {
        echo json_encode($qa->status(), JSON_PRETTY_PRINT)."\n";
    }
} catch (Throwable $e) {
    // Never expose exception messages: JSON, DB and HTTP errors can contain secrets.
    if (PHP_SAPI === 'cli-server') {
        http_response_code(503); header('Cache-Control: no-store'); echo '{"code":"QA_UNAVAILABLE"}';
    } else {
        fwrite(STDERR, "QA_FAILED: check private paths, config completeness and runtime prerequisites.\n"); exit(1);
    }
}
