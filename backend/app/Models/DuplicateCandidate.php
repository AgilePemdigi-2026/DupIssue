<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class DuplicateCandidate extends Model
{
    protected $fillable = [
        'issue_id',
        'candidate_issue_id',
        'similarity_score',
    ];

    protected function casts(): array
    {
        return [
            'issue_id' => 'integer',
            'candidate_issue_id' => 'integer',
            'similarity_score' => 'float',
        ];
    }

    public function issue()
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function candidateIssue()
    {
        return $this->belongsTo(Issue::class, 'candidate_issue_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $candidate): void {
            if ($candidate->issue_id === null || $candidate->candidate_issue_id === null) {
                throw new InvalidArgumentException('Both issue IDs are required.');
            }

            if ((int) $candidate->issue_id === (int) $candidate->candidate_issue_id) {
                throw new InvalidArgumentException('An issue cannot be its own duplicate candidate.');
            }

            $issue = Issue::query()->find($candidate->issue_id);
            $candidateIssue = Issue::query()->find($candidate->candidate_issue_id);
            if ($issue === null || $candidateIssue === null
                || (string) $issue->project_id !== (string) $candidateIssue->project_id) {
                throw new InvalidArgumentException('Duplicate candidates must belong to the same project.');
            }

            $score = $candidate->similarity_score;
            if (! is_int($score) && ! is_float($score)) {
                throw new InvalidArgumentException('Similarity score must be numeric.');
            }

            if (! is_finite((float) $score) || $score < 0 || $score > 1) {
                throw new InvalidArgumentException('Similarity score must be finite and between 0 and 1.');
            }
        });
    }

    /**
     * Scope candidates for one source issue.
     */
    public function scopeForIssue(Builder $query, int $issueId): Builder
    {
        return $query->where('issue_id', $issueId);
    }
}
