<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use HasFactory;

    /**
     * Tabel memberships tidak menggunakan created_at / updated_at bawaan Laravel,
     * melainkan joined_at dan removed_at sesuai PDM.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Kolom yang dapat diisi secara mass-assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'user_id',
        'joined_at',
        'removed_at',
        'is_active',
    ];

    /**
     * Type casting atribut model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'removed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi ke Project tempat keanggotaan ini terdaftar.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Relasi ke User yang menjadi anggota (Reporter).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
