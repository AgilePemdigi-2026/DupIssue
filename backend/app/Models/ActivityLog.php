<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    /**
     * Read-only model to prevent accidental mutation of audit logs.
     */
    protected $guarded = [];

    public function save(array $options = []): bool
    {
        // Allow creating during testing/factory if needed, but prevent update on existing logs
        if ($this->exists) {
            throw new \BadMethodCallException('ActivityLog is read-only and cannot be modified.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \BadMethodCallException('ActivityLog is read-only and cannot be deleted.');
    }

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const CATEGORY_PROJECT = 'PROJECT';
    public const CATEGORY_MEMBERSHIP = 'MEMBERSHIP';
    public const CATEGORY_ISSUE_STATUS = 'ISSUE_STATUS';
    public const CATEGORY_DUPLICATE_REVIEW = 'DUPLICATE_REVIEW';
    public const CATEGORY_ISSUE_SOURCE = 'ISSUE_SOURCE';
    public const CATEGORY_OTHER = 'OTHER';

    public static function categories(): array
    {
        return [
            self::CATEGORY_PROJECT => 'Project',
            self::CATEGORY_MEMBERSHIP => 'Keanggotaan',
            self::CATEGORY_ISSUE_STATUS => 'Status Issue',
            self::CATEGORY_DUPLICATE_REVIEW => 'Review Duplikat',
            self::CATEGORY_ISSUE_SOURCE => 'Sumber Issue',
            self::CATEGORY_OTHER => 'Lainnya',
        ];
    }

    /**
     * Explicit mapping matrix from database action string to UI Category.
     */
    public static function actionToCategoryMap(): array
    {
        return [
            // Verified in origin/main
            'CREATE_PROJECT' => self::CATEGORY_PROJECT,
            'UPDATE_PROJECT' => self::CATEGORY_PROJECT,
            'INVITE_REPORTER' => self::CATEGORY_MEMBERSHIP,
            'CANCEL_INVITATION' => self::CATEGORY_MEMBERSHIP,
            'ACCEPT_INVITATION' => self::CATEGORY_MEMBERSHIP,
            'REJECT_INVITATION' => self::CATEGORY_MEMBERSHIP,
            'REMOVE_MEMBER' => self::CATEGORY_MEMBERSHIP,
            'ISSUE_STATUS_CHANGED' => self::CATEGORY_ISSUE_STATUS,
            'DUPLICATE_AUTO_CLOSED' => self::CATEGORY_ISSUE_STATUS,
            'DUPLICATE_REVIEW' => self::CATEGORY_DUPLICATE_REVIEW,
            'DUPLICATE_CORRECTED' => self::CATEGORY_DUPLICATE_REVIEW,
            'VOTE_ADDED' => self::CATEGORY_OTHER,
            'VOTE_REMOVED' => self::CATEGORY_OTHER,

            // Forward-compatible with PR #48 (recorded as GAP until merged to main)
            'IMPORT_CSV' => self::CATEGORY_ISSUE_SOURCE,
            'IMPORT_GITHUB' => self::CATEGORY_ISSUE_SOURCE,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    /**
     * Get computed UI category based on action.
     */
    public function getUiCategoryAttribute(): string
    {
        return self::actionToCategoryMap()[$this->action] ?? self::CATEGORY_OTHER;
    }

    /**
     * Get actor display name, fallback to 'Sistem' if null.
     */
    public function getActorNameAttribute(): string
    {
        return $this->user ? $this->user->name : 'Sistem';
    }

    public const SENSITIVE_KEYS = [
        'password',
        'token',
        'access_token',
        'github_token',
        'pat',
        'secret',
        'api_key',
        'private_key',
        'cookie',
        'authorization',
    ];

    /**
     * Mask sensitive values inside old/new details using an explicit key whitelist.
     * Non-sensitive keys (such as 'path', 'status') will not be redacted.
     */
    public static function sanitizeValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];
        foreach ($values as $key => $value) {
            $lowerKey = strtolower(trim((string) $key));
            if (in_array($lowerKey, self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeValues($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public function getSanitizedOldValuesAttribute(): ?array
    {
        return self::sanitizeValues($this->old_values);
    }

    public function getSanitizedNewValuesAttribute(): ?array
    {
        return self::sanitizeValues($this->new_values);
    }

    /**
     * Scope query by category with fallback support for OTHER.
     */
    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        $map = self::actionToCategoryMap();
        $actions = array_keys(array_filter($map, fn ($cat) => $cat === $category));

        if ($category === self::CATEGORY_OTHER) {
            $knownActions = array_keys($map);
            return $query->where(function (Builder $q) use ($actions, $knownActions) {
                $q->whereIn('action', $actions)
                    ->orWhereNotIn('action', $knownActions);
            });
        }

        return $query->whereIn('action', $actions);
    }
}
