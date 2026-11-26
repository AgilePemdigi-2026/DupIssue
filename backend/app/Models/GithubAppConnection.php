<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubAppConnection extends Model
{
    protected $table = 'github_app_connections';

    // Seluruh atribut harus ditetapkan secara eksplisit
    // oleh backend setelah proses verifikasi GitHub.
    protected $guarded = ['*'];

    protected $attributes = [
        'status_sync_generation' => 1,
    ];

    protected static function booted(): void
    {
        static::updating(function (self $connection): void {
            if ($connection->isDirty([
                'installation_id', 'repository_id', 'repo_full_name',
            ])) {
                $connection->status_sync_generation =
                    max(1, (int) $connection->getOriginal('status_sync_generation')) + 1;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'installation_id' => 'integer',
            'repository_id' => 'integer',
            'verified_github_user_id' => 'integer',
            'verified_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Project DupIssue yang memiliki koneksi GitHub App.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
