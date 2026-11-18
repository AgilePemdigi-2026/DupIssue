<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\GithubAppConnection;
use App\Services\GithubAppInstallationTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use App\Services\GithubAppJwtService;

class GithubAppConnectController extends Controller
{
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
                && is_int($pending['installation_id'] ?? null)
                && $pending['installation_id'] > 0
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
        DB::transaction(function () use (
            $project,
            $user,
            $pending,
            $repositoryId,
            $repoFullName
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

            $connection->save();
        });

        // Hanya hapus sesi verifikasi setelah database berhasil
        // menyimpan koneksi.
        $request->session()->forget(
            'source_issue.github_app_verified_repositories'
        );

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
        Request $request
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
                && is_int($pending['installation_id'] ?? null)
                && $pending['installation_id'] > 0
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

            $installationId = $pending['installation_id'];

            $repositoriesResponse = $github->get(
                "/user/installations/{$installationId}/repositories",
                [
                    'per_page' => 100,
                    'page' => 1,
                ]
            );

            abort_unless(
                $repositoriesResponse->ok(),
                403,
                'Pengguna tidak memiliki akses ke instalasi GitHub.'
            );

            $total = $repositoriesResponse->json('total_count');
            $repositories = $repositoriesResponse->json('repositories');

            // Pembatasan sementara: jangan abaikan repository
            // yang berada di halaman pagination berikutnya.
            abort_unless(
                is_int($total)
                    && $total > 0
                    && $total <= 100
                    && is_array($repositories)
                    && count($repositories) === $total,
                422,
                'Daftar repository tidak valid atau melebihi 100.'
            );

            $verified = [];

            foreach ($repositories as $repo) {
                abort_unless(
                    is_array($repo)
                        && is_int($repo['id'] ?? null)
                        && ($repo['id'] ?? 0) > 0
                        && is_string($repo['full_name'] ?? null)
                        && preg_match(
                            '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/',
                            $repo['full_name']
                        ),
                    502,
                    'Metadata repository GitHub tidak valid.'
                );

                $verified[$repo['id']] = [
                    'id' => $repo['id'],
                    'full_name' => $repo['full_name'],
                ];
            }

            abort_unless(
                count($verified) === $total,
                502,
                'Daftar repository GitHub berisi ID duplikat.'
            );

            $request->session()->put(
                'source_issue.github_app_verified_repositories',
                [
                    'project_id' => (int) $project->id,
                    'actor_id' => (int) $user->id,
                    'installation_id' => $installationId,
                    'github_user_id' => $identity->json('id'),
                    'repositories' => array_values($verified),
                    'expires_at' => now()->addMinutes(10)->timestamp,
                ]
            );
        } catch (ConnectionException $exception) {
            abort(502, 'GitHub tidak dapat dihubungi.');
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
                && is_int($pending['installation_id'] ?? null)
                && $pending['installation_id'] > 0
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
}
