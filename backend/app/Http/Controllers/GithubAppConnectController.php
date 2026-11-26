<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\GithubAppConnection;
use App\Models\GithubConfig;
use App\Services\GithubAppInstallationTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use App\Services\GithubAppJwtService;

class GithubAppConnectController extends Controller
{
    /**
     * Cancel pending GitHub App authorization for this owner and project.
     * Never change the active repository or historical issue data.
     */
    public function cancelConnection(
        Request $request,
        Project $project
    ): RedirectResponse {
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === 'MAINTAINER'
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Hanya Maintainer pemilik project yang diizinkan.'
        );

        $keys = [
            'source_issue.github_app_verified_repositories',
            'source_issue.github_app_oauth',
            'source_issue.github_app_pending_oauth',
            'source_issue.github_app_install',
        ];

        foreach ($keys as $key) {
            $pending = $request->session()->get($key);

            if (
                is_array($pending)
                && ($pending['project_id'] ?? null) === (int) $project->id
                && ($pending['actor_id'] ?? null) === (int) $user->id
            ) {
                $request->session()->forget($key);
            }
        }

        return redirect()
            ->route('sources.index', [
                'project' => $project->id,
            ])
            ->with(
                'success',
                'Pemilihan repository GitHub dibatalkan. '
                . 'Koneksi sebelumnya tidak berubah.'
            )
            ->header('Cache-Control', 'no-store');
    }

    public function saveConnection(
        Request $request,
        Project $project,
        GithubAppInstallationTokenService $tokenService
    ): RedirectResponse {
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === 'MAINTAINER'
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Hanya Maintainer pemilik project yang diizinkan.'
        );

        $validated = $request->validate([
            'repository_id' => ['required', 'integer', 'min:1'],
        ]);

        $repositoryId = (int) $validated['repository_id'];

        $pending = $request->session()->get(
            'source_issue.github_app_verified_repositories'
        );

        abort_unless(
            is_array($pending)
                && is_int($pending['project_id'] ?? null)
                && is_int($pending['actor_id'] ?? null)
                && array_key_exists('installation_id', $pending)
                && (
                    $pending['installation_id'] === null
                    || (
                        is_int($pending['installation_id'])
                        && $pending['installation_id'] > 0
                    )
                )
                && is_int($pending['github_user_id'] ?? null)
                && $pending['github_user_id'] > 0
                && is_int($pending['expires_at'] ?? null)
                && $pending['expires_at'] > now()->timestamp
                && is_array($pending['repositories'] ?? null)
                && $pending['project_id'] === (int) $project->id
                && $pending['actor_id'] === (int) $user->id,
            403,
            'Verifikasi GitHub tidak tersedia atau kedaluwarsa.'
        );

        // Repository harus berasal dari hasil OAuth yang telah
        // diverifikasi, bukan nama repository kiriman browser.
        $selected = null;

        foreach ($pending['repositories'] as $repository) {
            if (
                is_array($repository)
                && ($repository['id'] ?? null) === $repositoryId
            ) {
                $selected = $repository;
                break;
            }
        }

        abort_unless(
            is_array($selected)
                && is_string($selected['full_name'] ?? null)
                && strlen($selected['full_name']) <= 255
                && preg_match(
                    '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/',
                    $selected['full_name']
                ),
            403,
            'Repository tidak termasuk hasil verifikasi GitHub.'
        );

        $repoFullName = $selected['full_name'];

        $selectedInstallationId = $selected['installation_id']
            ?? $pending['installation_id'];

        abort_unless(
            is_int($selectedInstallationId)
                && $selectedInstallationId > 0,
            403,
            'Instalasi untuk repository tidak terverifikasi.'
        );

        $pending['installation_id'] = $selectedInstallationId;

        // Validation before external API calls. A repository may be changed
        // only by a fresh, explicitly confirmed request from its owner.
        $before = GithubAppConnection::query()
            ->where('project_id', $project->id)
            ->first();

        $replacement = $before !== null && (
            (int) $before->repository_id !== $repositoryId
            || (int) $before->installation_id !== $selectedInstallationId
            || strcasecmp($before->repo_full_name, $repoFullName) !== 0
        );

        $legacyMigration = false;

        if ($before === null) {
            $legacySnapshot = $this->legacyPatSnapshot($project);

            abort_unless(
                $legacySnapshot === ($pending['expected_legacy_pat'] ?? null),
                409,
                'Konfigurasi GitHub lama berubah. Ulangi otorisasi GitHub.'
            );

            $legacyMigration = $legacySnapshot !== null;
        }

        if ($replacement || $legacyMigration) {
            $request->validate([
                'confirm_replace' => ['required', 'accepted'],
            ]);
        }

        $confirmedReplace = $request->boolean('confirm_replace');

        // Token terbatas ke satu repository dan issues:read.
        try {
            $installationToken = $tokenService->createForRepository(
                $pending['installation_id'],
                $repositoryId
            );
        } catch (\RuntimeException $exception) {
            abort(502, 'Akses instalasi GitHub gagal diverifikasi.');
        }

        try {
            $response = Http::baseUrl('https://api.github.com')
                ->accept('application/vnd.github+json')
                ->withToken($installationToken)
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2026-03-10',
                    'User-Agent' => 'DupIssue-Laravel',
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->get('/repos/' . $repoFullName);
        } catch (ConnectionException $exception) {
            abort(502, 'GitHub tidak dapat dihubungi.');
        }

        // Verifikasi ulang bahwa token instalasi dapat mengakses
        // repository dengan identitas yang sama.
        abort_unless(
            $response->ok()
                && is_int($response->json('id'))
                && $response->json('id') === $repositoryId
                && $response->json('full_name') === $repoFullName,
            403,
            'Identitas atau akses repository GitHub tidak valid.'
        );

        // Perubahan database dilakukan hanya setelah verifikasi API.
        $mutex = \App\Services\GithubStatusSyncMutex::acquire();
        abort_if($mutex === null, 409, 'Sinkronisasi sedang berlangsung. Coba lagi.');

        try {
            DB::transaction(function () use (
            $project,
            $user,
            $pending,
            $repositoryId,
            $repoFullName,
            $confirmedReplace
        ): void {
            $lockedProject = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                (int) $lockedProject->owner_id === (int) $user->id,
                403,
                'Kepemilikan project telah berubah.'
            );

            $connection = GithubAppConnection::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            // Validate migration again under the project lock.
            // Keep historical PAT configuration unchanged.
            if ($connection === null) {
                $legacySnapshot = $this->legacyPatSnapshot($lockedProject);

                abort_unless(
                    $legacySnapshot === ($pending['expected_legacy_pat'] ?? null),
                    409,
                    'Konfigurasi GitHub lama berubah. Ulangi otorisasi GitHub.'
                );

                abort_unless(
                    $legacySnapshot === null || $confirmedReplace,
                    422,
                    'Migrasi koneksi PAT ke GitHub App harus dikonfirmasi.'
                );
            }

            // Detect concurrent changes made after OAuth was started.
            // The legacy setup callback has no snapshot: preserve its behavior.
            if (array_key_exists('expected_connection', $pending)) {
                $snapshot = $pending['expected_connection'];
                if ($snapshot === null) {
                    abort_unless(
                        $connection === null,
                        409,
                        'Koneksi project berubah selama OAuth. Ulangi proses.'
                    );
                } else {
                    abort_unless(
                        is_array($snapshot)
                            && $connection !== null
                            && (int) $connection->id === ($snapshot['id'] ?? null)
                            && (int) $connection->repository_id === ($snapshot['repository_id'] ?? null)
                            && (int) $connection->installation_id === ($snapshot['installation_id'] ?? null)
                            && $connection->repo_full_name === ($snapshot['repo_full_name'] ?? null),
                        409,
                        'Repository telah berubah selama OAuth. Ulangi proses.'
                    );
                }
            }

            $isChanging = $connection !== null && (
                (int) $connection->repository_id !== $repositoryId
                || (int) $connection->installation_id
                    !== $pending['installation_id']
                || strcasecmp($connection->repo_full_name, $repoFullName) !== 0
            );

            abort_unless(
                ! $isChanging || $confirmedReplace,
                422,
                'Penggantian repository harus dikonfirmasi.'
            );

            if ($connection === null) {
                $connection = new GithubAppConnection();
                $connection->project_id = $project->id;
            }

            $connection->installation_id =
                $pending['installation_id'];

            $connection->repository_id = $repositoryId;
            $connection->repo_full_name = $repoFullName;

            $connection->verified_github_user_id =
                $pending['github_user_id'];

            $connection->verified_at = now();

            // A new connection or repository replacement has not
            // synchronized any issues from its newly selected source.
            // Reauthorization of the same connection keeps its history.
            if (! $connection->exists || $isChanging) {
                $connection->last_synced_at = null;
            }

            $connection->save();
            });
        } finally {
            $mutex->release();
        }

        // Hanya hapus sesi verifikasi setelah database berhasil
        // menyimpan koneksi.
        $request->session()->forget([
            'source_issue.github_app_verified_repositories',
            'source_issue.github_app_preview',
            'source_issue.github_preview',
        ]);

        $response = redirect()
            ->route('sources.index', [
                'project' => $project->id,
            ])
            ->with(
                'success',
                'Koneksi GitHub App berhasil disimpan. '
                . 'Issue sekarang dapat dipratinjau dan diimpor melalui GitHub App.'
            );

        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function handleCallback(
        Request $request,
        GithubAppInstallationTokenService $tokenService
    ): RedirectResponse {
        $pending = $request->session()->get(
            'source_issue.github_app_oauth'
        );

        $state = $request->query('state');
        $user = $request->user();

        abort_unless(
            is_array($pending)
                && $user
                && $user->role === 'MAINTAINER'
                && is_string($state)
                && preg_match('/\A[a-f0-9]{64}\z/', $state)
                && is_string($pending['state_hash'] ?? null)
                && hash_equals(
                    $pending['state_hash'],
                    hash('sha256', $state)
                )
                && is_string($pending['code_verifier'] ?? null)
                && preg_match(
                    '/\A[A-Za-z0-9_-]{43,128}\z/',
                    $pending['code_verifier']
                )
                && is_int($pending['project_id'] ?? null)
                && is_int($pending['actor_id'] ?? null)
                && array_key_exists('installation_id', $pending)
                && (
                    $pending['installation_id'] === null
                    || (
                        is_int($pending['installation_id'])
                        && $pending['installation_id'] > 0
                    )
                )
                && is_int($pending['expires_at'] ?? null)
                && $pending['expires_at'] > now()->timestamp
                && $pending['actor_id'] === (int) $user->id,
            403,
            'Sesi OAuth GitHub tidak valid.'
        );

        // Cegah penggunaan ulang state meskipun proses API gagal.
        $request->session()->forget(
            'source_issue.github_app_oauth'
        );

        $project = Project::query()->find(
            $pending['project_id']
        );

        abort_unless(
            $project
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Akses project tidak diizinkan.'
        );

        abort_unless(
            $request->query('error') === null,
            422,
            'Otorisasi GitHub dibatalkan atau ditolak.'
        );

        $code = $request->query('code');

        abort_unless(
            is_string($code)
                && strlen($code) <= 512
                && preg_match('/\A[A-Za-z0-9_-]+\z/', $code),
            422,
            'Authorization code GitHub tidak valid.'
        );

        $clientId = config('services.github_app.client_id');
        $clientSecret = config('services.github_app.client_secret');
        $callback = config('services.github_app.callback_url');

        abort_unless(
            is_string($clientId) && $clientId !== ''
                && is_string($clientSecret) && $clientSecret !== ''
                && is_string($callback)
                && filter_var($callback, FILTER_VALIDATE_URL),
            503,
            'Konfigurasi OAuth GitHub tidak lengkap.'
        );

        try {
            // Authorization code hanya dikirim server-to-server.
            $exchange = Http::asForm()
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'DupIssue-Laravel',
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->post(
                    'https://github.com/login/oauth/access_token',
                    [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'code' => $code,
                        'redirect_uri' => $callback,
                        'code_verifier' => $pending['code_verifier'],
                    ]
                );

            abort_unless(
                $exchange->ok()
                    && $exchange->json('error') === null
                    && is_string($exchange->json('access_token'))
                    && $exchange->json('access_token') !== ''
                    && $exchange->json('token_type') === 'bearer',
                502,
                'Pertukaran token OAuth GitHub gagal.'
            );

            // Token dipakai sementara dan tidak disimpan.
            $accessToken = $exchange->json('access_token');

            $github = Http::baseUrl('https://api.github.com')
                ->accept('application/vnd.github+json')
                ->withToken($accessToken)
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2026-03-10',
                    'User-Agent' => 'DupIssue-Laravel',
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting();

            $identity = $github->get('/user');

            abort_unless(
                $identity->ok()
                    && is_int($identity->json('id'))
                    && is_string($identity->json('login')),
                403,
                'Identitas pengguna GitHub tidak terverifikasi.'
            );

            // Read only installations accessible to this authenticated
            // GitHub user, then filter them to OUR GitHub App.
            if ($pending['installation_id'] === null) {
                $installationsResponse = $github->get(
                    '/user/installations',
                    ['per_page' => 100, 'page' => 1]
                );

                $installationTotal = $installationsResponse->json(
                    'total_count'
                );
                $installationRows = $installationsResponse->json(
                    'installations'
                );

                // Fail closed instead of silently discarding later pages.
                abort_unless(
                    $installationsResponse->ok()
                        && is_int($installationTotal)
                        && $installationTotal >= 0
                        && $installationTotal <= 100
                        && is_array($installationRows)
                        && count($installationRows) === $installationTotal,
                    422,
                    'Daftar instalasi GitHub tidak lengkap.'
                );

                $installationIds = [];
                foreach ($installationRows as $installation) {
                    abort_unless(is_array($installation), 502);

                    if (
                        (string) ($installation['app_id'] ?? '')
                            !== (string) config('services.github_app.app_id')
                        || ($installation['suspended_at'] ?? null) !== null
                        || ! in_array(
                            $installation['permissions']['issues'] ?? null,
                            ['read', 'write'],
                            true
                        )
                    ) {
                        continue;
                    }

                    abort_unless(
                        is_int($installation['id'] ?? null)
                            && $installation['id'] > 0,
                        502,
                        'ID instalasi GitHub tidak valid.'
                    );
                    $installationIds[$installation['id']] =
                        $installation['id'];
                }

                if ($installationIds === []) {
                    // No installation of this App accessible to the user.
                    // Reuse the existing installation flow, including state.
                    return $this->startInstallation($request, $project);
                }

                $installationIds = array_values($installationIds);
            } else {
                // The callback arrived after a new installation setup.
                $installationIds = [$pending['installation_id']];
            }

            $verified = [];
            foreach ($installationIds as $installationId) {
                $repositoriesResponse = $github->get(
                    "/user/installations/{$installationId}/repositories",
                    ['per_page' => 100, 'page' => 1]
                );

                abort_unless(
                    $repositoriesResponse->ok(),
                    403,
                    'Pengguna tidak memiliki akses ke instalasi GitHub.'
                );

                $total = $repositoriesResponse->json('total_count');
                $repositories = $repositoriesResponse->json('repositories');

                abort_unless(
                    $repositoriesResponse->ok()
                        && is_int($total)
                        && $total >= 0
                        && $total <= 100
                        && is_array($repositories)
                        && count($repositories) === $total,
                    422,
                    'Daftar repository GitHub tidak lengkap.'
                );

                foreach ($repositories as $repo) {
                    abort_unless(
                        is_array($repo)
                            && is_int($repo['id'] ?? null)
                            && $repo['id'] > 0
                            && is_string($repo['full_name'] ?? null)
                            && strlen($repo['full_name']) <= 255
                            && preg_match(
                                '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/',
                                $repo['full_name']
                            ),
                        502,
                        'Metadata repository GitHub tidak valid.'
                    );

                    // Never silently replace a duplicate repository ID.
                    abort_unless(
                        ! array_key_exists($repo['id'], $verified),
                        502,
                        'ID repository GitHub duplikat.'
                    );

                    $verified[$repo['id']] = [
                        'id' => $repo['id'],
                        'full_name' => $repo['full_name'],
                    ];

                    // Preserve the original callback session format.
                    // Auto-discovery also records the installation per repo.
                    if ($pending['installation_id'] === null) {
                        $verified[$repo['id']]['installation_id'] =
                            $installationId;
                    }
                }
            }

            abort_unless(
                count($verified) > 0,
                422,
                'Tidak ada repository yang dapat diakses melalui GitHub App.'
            );

            $legacySnapshot = $this->legacyPatSnapshot($project);

            $request->session()->put(
                'source_issue.github_app_verified_repositories',
                [
                    'project_id' => (int) $project->id,
                    'actor_id' => (int) $user->id,
                    // Multiple installations can be represented per repo.
                    'installation_id' => count($installationIds) === 1
                        ? $installationIds[0]
                        : null,
                    'github_user_id' => $identity->json('id'),
                    'repositories' => array_values($verified),
                    'expected_connection' => $pending['expected_connection'] ?? null,
                    'expected_legacy_pat' => $legacySnapshot,
                    'expires_at' => now()->addMinutes(10)->timestamp,
                ]
            );
        } catch (ConnectionException $exception) {
            abort(502, 'GitHub tidak dapat dihubungi.');
        }

        // Never silently replace an existing project repository.
        // New projects still auto-connect when exactly one repo is available.
        $connected = GithubAppConnection::query()
            ->where('project_id', $project->id)
            ->first();
        $onlyRepository = count($verified) === 1
            ? reset($verified)
            : null;
        $sameRepository = $connected !== null
            && is_array($onlyRepository)
            && (int) $connected->repository_id === $onlyRepository['id']
            && (int) $connected->installation_id
                === ($onlyRepository['installation_id'] ?? $pending['installation_id'])
            && strcasecmp(
                $connected->repo_full_name,
                $onlyRepository['full_name']
            ) === 0;

        if (
            $pending['installation_id'] === null
            && count($verified) === 1
            && (
                ($connected === null && $legacySnapshot === null)
                || $sameRepository
            )
        ) {
            // User initiated the link; one accessible repository needs
            // no further selection. Reuse saveConnection's scoped token,
            // repository-ID verification and database transaction.
            $only = reset($verified);
            $request->merge(['repository_id' => $only['id']]);

            return $this->saveConnection(
                $request,
                $project,
                $tokenService
            );
        }

        // Redirect membersihkan authorization code dari address bar.
        $response = redirect()
            ->route('sources.index', [
                'project' => $project->id,
            ])
            ->with(
                'success',
                'Otorisasi GitHub berhasil. '
                . 'Pemilihan repository belum diselesaikan; '
                . 'koneksi belum aktif.'
            );

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function beginAuthorization(
        Request $request
    ): RedirectResponse {
        $pending = $request->session()->get(
            'source_issue.github_app_pending_oauth'
        );

        $user = $request->user();

        abort_unless(
            is_array($pending)
                && $user
                && $user->role === 'MAINTAINER'
                && is_int($pending['project_id'] ?? null)
                && is_int($pending['actor_id'] ?? null)
                && array_key_exists('installation_id', $pending)
                && (
                    $pending['installation_id'] === null
                    || (
                        is_int($pending['installation_id'])
                        && $pending['installation_id'] > 0
                    )
                )
                && is_int($pending['expires_at'] ?? null)
                && $pending['expires_at'] > now()->timestamp
                && $pending['actor_id'] === (int) $user->id,
            403,
            'Sesi otorisasi GitHub tidak valid.'
        );

        $project = Project::query()->find(
            $pending['project_id']
        );

        abort_unless(
            $project
                && (int) $project->owner_id ===
                    (int) $user->id,
            403,
            'Akses project tidak diizinkan.'
        );

        $clientId = config('services.github_app.client_id');
        $callback = config('services.github_app.callback_url');

        abort_unless(
            is_string($clientId)
                && trim($clientId) !== ''
                && is_string($callback)
                && filter_var($callback, FILTER_VALIDATE_URL)
                && parse_url($callback, PHP_URL_PATH)
                    === '/integrations/github/callback'
                && parse_url($callback, PHP_URL_QUERY) === null
                && parse_url($callback, PHP_URL_FRAGMENT) === null,
            503,
            'Konfigurasi OAuth GitHub App tidak valid.'
        );

        $state = bin2hex(random_bytes(32));

        $verifier = rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '='
        );

        $challenge = rtrim(
            strtr(
                base64_encode(
                    hash('sha256', $verifier, true)
                ),
                '+/',
                '-_'
            ),
            '='
        );

        $request->session()->forget(
            'source_issue.github_app_pending_oauth'
        );

        $request->session()->put(
            'source_issue.github_app_oauth',
            [
                'state_hash' => hash('sha256', $state),
                'code_verifier' => $verifier,
                'project_id' => (int) $project->id,
                'actor_id' => (int) $user->id,
                'installation_id' => $pending['installation_id'],
                'expected_connection' => $pending['expected_connection'] ?? null,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ]
        );

        $url = 'https://github.com/login/oauth/authorize?'
            . http_build_query(
                [
                    'client_id' => $clientId,
                    'redirect_uri' => $callback,
                    'state' => $state,
                    'code_challenge' => $challenge,
                    'code_challenge_method' => 'S256',
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $response = redirect()->away($url);

        $response->headers->set(
            'Cache-Control',
            'no-store'
        );

        return $response;
    }

    public function handleSetup(
        Request $request,
        GithubAppJwtService $jwtService
    ): RedirectResponse {
        $pending = $request->session()->get(
            'source_issue.github_app_install'
        );

        $state = $request->query('state');
        $rawId = $request->query('installation_id');
        $action = $request->query('setup_action');

        abort_unless(
            is_array($pending)
                && is_string($state)
                && preg_match('/\A[a-f0-9]{64}\z/', $state)
                && is_string($pending['state_hash'] ?? null)
                && hash_equals(
                    $pending['state_hash'],
                    hash('sha256', $state)
                )
                && is_int($pending['project_id'] ?? null)
                && is_int($pending['actor_id'] ?? null)
                && is_int($pending['expires_at'] ?? null)
                && $pending['expires_at'] > now()->timestamp
                && $request->user()
                && $request->user()->role === 'MAINTAINER'
                && $pending['actor_id'] === (int) $request->user()->id,
            403,
            'Sesi instalasi GitHub App tidak valid.'
        );

        abort_unless(
            $action === null || $action === 'install',
            422,
            'Aksi instalasi GitHub tidak didukung.'
        );

        $installationId = is_string($rawId)
            ? filter_var(
                $rawId,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            )
            : false;

        abort_unless(
            is_int($installationId),
            422,
            'Installation ID tidak valid.'
        );

        $project = Project::query()->find(
            $pending['project_id']
        );

        abort_unless(
            $project
                && (int) $project->owner_id ===
                    (int) $request->user()->id,
            403,
            'Akses project tidak diizinkan.'
        );

        try {
            $response = Http::accept('application/vnd.github+json')
                ->withToken($jwtService->generate())
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2026-03-10',
                    'User-Agent' => 'DupIssue-Laravel',
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->get(
                    'https://api.github.com/app/installations/'
                    . $installationId
                );
        } catch (ConnectionException $exception) {
            abort(502, 'GitHub belum dapat dihubungi.');
        }

        abort_unless(
            $response->ok()
                && (string) $response->json('app_id') ===
                    (string) config('services.github_app.app_id')
                && $response->json('permissions.issues') === 'read'
                && $response->json('suspended_at') === null,
            403,
            'Instalasi GitHub App belum dapat diverifikasi.'
        );

        // State instalasi hanya boleh digunakan sekali.
        $request->session()->forget(
            'source_issue.github_app_install'
        );

        // Belum dianggap sebagai koneksi yang terotorisasi.
        // OAuth masih harus memverifikasi akses user/repository.
        $request->session()->put(
            'source_issue.github_app_pending_oauth',
            [
                'project_id' => (int) $project->id,
                'actor_id' => (int) $request->user()->id,
                'installation_id' => $installationId,
                'expires_at' => now()->addMinutes(15)->timestamp,
            ]
        );

        $response = redirect()->route(
            'sources.github.app.authorize'
        );

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * Start OAuth first. The callback discovers installations the user can
     * access; no installation ID is entered by the browser.
     */
    public function startConnection(
        Request $request,
        Project $project
    ): RedirectResponse {
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === 'MAINTAINER'
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Hanya Maintainer pemilik project yang diizinkan.'
        );

        $existing = GithubAppConnection::query()
            ->where('project_id', $project->id)
            ->first();

        $expectedConnection = $existing === null ? null : [
            'id' => (int) $existing->id,
            'installation_id' => (int) $existing->installation_id,
            'repository_id' => (int) $existing->repository_id,
            'repo_full_name' => $existing->repo_full_name,
        ];

        $request->session()->forget([
            'source_issue.github_app_install',
            'source_issue.github_app_pending_oauth',
            'source_issue.github_app_oauth',
            'source_issue.github_app_verified_repositories',
        ]);

        $request->session()->put(
            'source_issue.github_app_pending_oauth',
            [
                'project_id' => (int) $project->id,
                'actor_id' => (int) $user->id,
                // null means discover the installation after OAuth.
                'installation_id' => null,
                'expected_connection' => $expectedConnection,
                'expires_at' => now()->addMinutes(15)->timestamp,
            ]
        );

        return redirect()
            ->route('sources.github.app.authorize')
            ->header('Cache-Control', 'no-store');
    }

    public function startInstallation(
        Request $request,
        Project $project
    ): RedirectResponse {
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === 'MAINTAINER'
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Hanya Maintainer pemilik project yang diizinkan.'
        );

        $slug = config('services.github_app.slug');

        abort_unless(
            is_string($slug)
                && preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/', $slug),
            503,
            'Konfigurasi GitHub App belum valid.'
        );

        $state = bin2hex(random_bytes(32));

        $request->session()->put(
            'source_issue.github_app_install',
            [
                'state_hash' => hash('sha256', $state),
                'project_id' => (int) $project->id,
                'actor_id' => (int) $user->id,
                'expires_at' => now()->addMinutes(15)->timestamp,
            ]
        );

        $url = 'https://github.com/apps/'
            . rawurlencode($slug)
            . '/installations/new?'
            . http_build_query(
                ['state' => $state],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $response = redirect()->away($url);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Read only legacy repository metadata, never its credential.
     */
    private function legacyPatSnapshot(Project $project): ?array
    {
        $legacy = GithubConfig::query()
            ->where('project_id', $project->id)
            ->first(['id', 'repo_full_name']);

        if ($legacy === null) {
            return null;
        }

        return [
            'id' => (int) $legacy->id,
            'repo_full_name' => $legacy->repo_full_name,
        ];
    }

}
