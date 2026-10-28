<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'author_id',
        'title',
        'description',
        'source_type',
        'source_id',
        'github_repo',
        'github_number',
        'status',
        'detection_status',
        'duplicate_of',
        'vote_count',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parentIssue()
    {
        return $this->belongsTo(Issue::class, 'duplicate_of');
    }

    public function duplicates()
    {
        return $this->hasMany(Issue::class, 'duplicate_of');
    }

    public function votes()
    {
        return $this->hasMany(Vote::class, 'issue_id');
    }
}