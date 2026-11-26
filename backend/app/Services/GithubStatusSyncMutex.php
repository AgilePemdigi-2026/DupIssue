<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

final class GithubStatusSyncMutex
{
    private static ?self $active = null;

    private function __construct(
        private readonly PDO $pdo,
        private readonly ?string $name,
        private $file = null
    ) {}

    /**
     * MySQL uses a session-owned advisory lock.
     * SQLite uses a local filesystem lock for development/testing.
     * No database transaction is held across remote HTTP.
     */
    public static function acquire(): ?self
    {
        if (self::$active !== null) {
            return null;
        }

        $connection = DB::connection();
        $pdo = $connection->getPdo();

        if ($connection->getDriverName() === 'mysql') {
            $name = 'dupissue-status-'.substr(
                hash('sha256', $connection->getDatabaseName()), 0, 40
            );

            $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $statement->execute([$name]);

            if ((int) $statement->fetchColumn() !== 1) {
                return null;
            }

            return self::$active = new self($pdo, $name);
        }

        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unsupported synchronization database.', 503);
        }

        $file = fopen(
            storage_path('framework/cache/github-status-sync.lock'),
            'c+'
        );

        if ($file === false) {
            throw new RuntimeException('Synchronization lock unavailable.', 503);
        }

        if (! flock($file, LOCK_EX | LOCK_NB)) {
            fclose($file);
            return null;
        }

        return self::$active = new self($pdo, null, $file);
    }

    public static function assertHeld(): void
    {
        $lock = self::$active;

        if (! $lock || DB::connection()->getPdo() !== $lock->pdo) {
            throw new RuntimeException('Synchronization lock lost.', 503);
        }

        if ($lock->name !== null) {
            $statement = $lock->pdo->prepare(
                'SELECT IS_USED_LOCK(?) = CONNECTION_ID()'
            );
            $statement->execute([$lock->name]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('Synchronization lock lost.', 503);
            }
        }
    }

    public function release(): void
    {
        try {
            if ($this->name !== null) {
                $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$this->name]);
            } elseif (is_resource($this->file)) {
                flock($this->file, LOCK_UN);
                fclose($this->file);
            }
        } catch (Throwable) {
            // A disconnected MySQL session releases its advisory locks.
        } finally {
            self::$active = null;
        }
    }
}
