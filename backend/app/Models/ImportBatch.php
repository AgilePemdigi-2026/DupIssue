<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    protected $table = 'import_batches';

    // PDM hanya menyediakan created_at, tanpa updated_at.
    // created_at akan diisi otomatis oleh database.
    public $timestamps = false;

    // Hanya atribut hasil proses impor yang boleh diisi massal.
    // project_id dan actor_id harus ditentukan oleh backend
    // berdasarkan project dan pengguna yang sudah diotorisasi.
    protected $fillable = [
        'source_type',
        'file_name',
        'total_rows',
        'valid_rows',
        'skipped_rows',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'skipped_rows' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    // Project tempat proses impor dilakukan.
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // Maintainer yang menjalankan proses impor.
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
