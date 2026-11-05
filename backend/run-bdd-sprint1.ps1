param(
    [switch]$IncludePhpUnit
)

# DupIssue BDD integration: Register + 27-scenario Sprint 1 + Auth/OTP.
# This script NEVER invokes migrations directly. Each Behat context must first
# verify the configured database is SQLite :memory: before fixture migrations.
$ErrorActionPreference = 'Stop'
$oldDir = (Get-Location).Path
$vars = @('APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL',
          'SESSION_DRIVER', 'CACHE_STORE', 'MAIL_MAILER', 'QUEUE_CONNECTION')
$previous = @{}
foreach ($name in $vars) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

try {
    Set-Location $PSScriptRoot

    $files = @(
        '.\vendor\bin\behat',
        '.\behat.register.yml',
        '.\behat.yml',
        '.\behat.auth.yml',
        '.\features\bootstrap\AuthBddContext.php',
        '.\features\register-bootstrap\FeatureContext.php'
    )
    foreach ($file in $files) {
        if (-not (Test-Path $file)) {
            throw "Missing file: $file. Install the overlay and Composer dependencies first."
        }
    }

    if (Test-Path '.\bootstrap\cache\config.php') {
        throw 'STOP: Laravel config cache exists; BDD must use uncached config.'
    }

    $sqlite = & php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
    if ($LASTEXITCODE -ne 0) {
        throw 'STOP: pdo_sqlite PHP extension is missing.'
    }

    if (-not (Test-Path '.\.env.behat')) {
        if (-not (Test-Path '.\.env.behat.example')) {
            throw 'Missing safe .env.behat.example template.'
        }
        Copy-Item '.\.env.behat.example' '.\.env.behat'
    }
    $bddFile = Get-Content '.\.env.behat' -Raw
    foreach ($required in @(
        '(?m)^APP_ENV=behat\s*$',
        '(?m)^DB_CONNECTION=sqlite\s*$',
        '(?m)^DB_DATABASE=:memory:\s*$'
    )) {
        if ($bddFile -notmatch $required) {
            throw 'STOP: .env.behat must require APP_ENV=behat, SQLite :memory:.'
        }
    }

    # APP_KEY is temporary and never printed or written to Git-tracked files.
    $key = & php -r "echo 'base64:'.base64_encode(random_bytes(32));"
    if ($LASTEXITCODE -ne 0 -or $key -notmatch '^base64:[A-Za-z0-9+/]+=*$') {
        throw 'Unable to generate temporary BDD encryption key.'
    }
    $env:APP_KEY = $key
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = ':memory:'
    $env:DB_URL = ''
    $env:SESSION_DRIVER = 'array'
    $env:CACHE_STORE = 'array'
    $env:MAIL_MAILER = 'array'

    function Invoke-BddSuite([string]$title, [string]$file, [string]$suite) {
        Write-Host "`n=== $title ===" -ForegroundColor Cyan
        & php '.\vendor\bin\behat' "--config=$file" "--suite=$suite" '--format=progress'
        if ($LASTEXITCODE -ne 0) {
            throw "BDD failed: $title (exit code: $LASTEXITCODE)"
        }
    }

    $env:APP_ENV = 'testing'
    $env:QUEUE_CONNECTION = 'sync'
    Invoke-BddSuite 'BDD Register' 'behat.register.yml' 'register'

    $env:APP_ENV = 'behat'
    $env:QUEUE_CONNECTION = 'database'
    Invoke-BddSuite 'BDD Submit, NLP, Status, Logout, Project' 'behat.yml' 'sprint1'
    Invoke-BddSuite 'BDD Login/RBAC and OTP' 'behat.auth.yml' 'auth'

    Write-Host "`nALL THREE BDD SUITES PASSED" -ForegroundColor Green

    if ($IncludePhpUnit) {
        $env:APP_ENV = 'testing'
        $env:QUEUE_CONNECTION = 'sync'
        Write-Host "`n=== Optional PHPUnit regression ===" -ForegroundColor Cyan
        & php artisan test
        if ($LASTEXITCODE -ne 0) {
            throw 'PHPUnit regression failed.'
        }
        Write-Host 'PHPUnit regression passed.' -ForegroundColor Green
    }
}
finally {
    foreach ($name in $vars) {
        $old = $previous[$name]
        if ($null -eq $old) {
            [Environment]::SetEnvironmentVariable($name, $null, 'Process')
        }
        else {
            [Environment]::SetEnvironmentVariable($name, $old, 'Process')
        }
    }
    Set-Location $oldDir
}
