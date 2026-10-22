<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issue extends Model
{
    use HasFactory;

    // Project, author, status, dan metadata sistem tidak berasal dari mass-assignment input user.
    protected $fillable = ['title', 'description'];

    protected function casts(): array
    {
        return [
            'vote_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parentIssue(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of');
    }

    public function childDuplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of');
    }
}
