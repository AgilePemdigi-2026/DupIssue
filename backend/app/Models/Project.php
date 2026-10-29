<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    /**
     * Kolom yang dapat diisi secara mass-assignment dari input form.
     * Catatan: owner_id sengaja tidak dimasukkan ke $fillable untuk mencegah
     * manipulasi kepemilikan project dari form (REQ-03, Bab 5 Panduan Integrasi).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * Type casting atribut model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Relasi ke User sebagai pemilik project (Maintainer).
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Relasi ke data keanggotaan project (Memberships).
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Relasi ke data undangan project (Invitations).
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
