<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubConfig extends Model
{
    protected $table = 'github_configs';

    // Hanya nama repository yang boleh diisi melalui mass assignment.
    // project_id dan PAT harus ditetapkan secara eksplisit oleh backend.
    protected $fillable = [
        'repo_full_name',
    ];

    // Jangan sertakan token saat model diubah menjadi array atau JSON.
    protected $hidden = [
        'pat_encrypted',
    ];

    protected function casts(): array
    {
        return [
            // Laravel mengenkripsi saat menyimpan dan mendekripsi
            // saat atribut dibaca melalui Eloquent.
            'pat_encrypted' => 'encrypted',

            'last_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    // Project yang memiliki konfigurasi GitHub ini.
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
