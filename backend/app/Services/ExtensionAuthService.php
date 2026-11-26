<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use LogicException;

class ExtensionAuthService
{
    public const PAIRING_TTL_MINUTES = 5;

    public const ACCESS_TTL_HOURS = 8;

    private const PAIRING_PREFIX = 'dup_pair_';

    private const ACCESS_PREFIX = 'dup_ext_';

    /**
     * Create a short-lived, single-use pairing code.
     *
     * The caller must obtain the actor from an authenticated
     * DupIssue web session, never from client-provided user IDs.
     */
    public function createPairingCode(User $actor): string
    {
        $user = User::query()->find($actor->getKey());

        abort_unless(
            $user !== null
                && $user->role === 'REPORTER'
                && (int) $user->auth_version
                    === (int) $actor->auth_version,
            403,
            'Akses extension tidak tersedia.'
        );

        $code = self::PAIRING_PREFIX
            .bin2hex(random_bytes(32));

        $now = now();

        DB::table('extension_sessions')->insert([
            'user_id' => $user->getKey(),
            'auth_version' => (int) $user->auth_version,
            'pairing_hash' => $this->digest($code),
            'pairing_expires_at' => $now->copy()
                ->addMinutes(self::PAIRING_TTL_MINUTES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Plaintext is returned once, not stored in the database.
        return $code;
    }

    /**
     * Atomically exchange a valid one-time pairing code.
     *
     * @return array{access_token: string, expires_at: string}|null
     */
    public function exchange(string $code): ?array
    {
        if (! $this->validFormat(
            $code,
            self::PAIRING_PREFIX
        )) {
            return null;
        }

        return DB::transaction(function () use ($code): ?array {
            $session = DB::table('extension_sessions')
                ->where('pairing_hash', $this->digest($code))
                ->whereNull('paired_at')
                ->whereNull('revoked_at')
                ->where('pairing_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return null;
            }

            if ($this->validUser($session) === null) {
                return null;
            }

            $token = self::ACCESS_PREFIX
                .bin2hex(random_bytes(32));

            $now = now();

            $expiresAt = $now->copy()
                ->addHours(self::ACCESS_TTL_HOURS);

            // Consume the pairing code and issue the token
            // in one transaction. No replay is permitted.
            $updated = DB::table('extension_sessions')
                ->where('id', $session->id)
                ->whereNull('paired_at')
                ->whereNull('revoked_at')
                ->where('pairing_expires_at', '>', $now)
                ->update([
                    'pairing_hash' => null,
                    'paired_at' => $now,
                    'access_hash' => $this->digest($token),
                    'access_expires_at' => $expiresAt,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return null;
            }

            return [
                'access_token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }, 3);
    }

    /**
     * Resolve the current user for an extension access token.
     *
     * Project membership is deliberately checked separately
     * by each project-scoped extension endpoint.
     * Mutations can lock the session and user until their transaction completes.
     */
    public function authenticate(string $token, bool $lockForUpdate = false): ?User
    {
        if ($lockForUpdate && DB::transactionLevel() === 0) {
            throw new LogicException('Credential locks require an active transaction.');
        }

        if (! $this->validFormat(
            $token,
            self::ACCESS_PREFIX
        )) {
            return null;
        }

        $session = DB::table('extension_sessions')
            ->where('access_hash', $this->digest($token))
            ->whereNotNull('paired_at')
            ->whereNull('revoked_at')
            ->where('access_expires_at', '>', now())
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate())
            ->first();

        // Recheck time after acquiring the lock, which may have required a wait.
        if ($session === null || Carbon::parse($session->access_expires_at)->lte(now())) {
            return null;
        }

        return $this->validUser($session, $lockForUpdate);
    }

    /**
     * Revoke only the session identified by the presented token.
     *
     * Repeated revocation is safe.
     */
    public function revoke(string $token): void
    {
        if (! $this->validFormat(
            $token,
            self::ACCESS_PREFIX
        )) {
            return;
        }

        DB::table('extension_sessions')
            ->where('access_hash', $this->digest($token))
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'access_hash' => null,
                'updated_at' => now(),
            ]);
    }

    private function validUser(object $session, bool $lockForUpdate = false): ?User
    {
        $user = User::query()
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate())
            ->find($session->user_id);

        if (
            $user === null
            || $user->role !== 'REPORTER'
            || (int) $user->auth_version
                !== (int) $session->auth_version
        ) {
            return null;
        }

        return $user;
    }

    private function digest(string $credential): string
    {
        return hash('sha256', $credential);
    }

    private function validFormat(
        string $credential,
        string $prefix
    ): bool {
        return preg_match(
            '/\A'.preg_quote($prefix, '/').'[a-f0-9]{64}\z/',
            $credential
        ) === 1;
    }
}
