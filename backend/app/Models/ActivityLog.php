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
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'github_token',
        'installation_access_token',
        'pat',
        'pat_encrypted',
        'pat_auth',
        'secret',
        'client_secret',
        'api_key',
        'private_key',
        'public_key',
        'cookie',
        'authorization',
        'auth_token',
        'bearer_token',
    ];

    /**
     * Determine whether a key is sensitive.
     * Prevents false positives for common non-sensitive keys like 'path', 'pattern', 'status'.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower(trim($key));
        if (in_array($lower, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return (bool) preg_match('/(^|_)(password|secret|token|pat|api_key|private_key)(_|$)/i', $lower);
    }

    /**
     * Mask sensitive values inside old/new details using key inspection.
     * Non-sensitive keys (such as 'path', 'status') will not be redacted.
     */
    public static function sanitizeValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];
        foreach ($values as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeValues($value);
            } else {
                $sanitized[$key] = is_string($value) ? self::sanitizeText($value) : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Redact raw security tokens embedded in free-form text.
     */
    public static function sanitizeText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // Redact GitHub Personal Access Tokens (classic & fine-grained) and OAuth tokens
        $sanitized = preg_replace('/(ghp_[A-Za-z0-9]{15,}|github_pat_[A-Za-z0-9_]{15,}|gho_[A-Za-z0-9]{15,}|ghu_[A-Za-z0-9]{15,}|ghs_[A-Za-z0-9]{15,})/', '***REDACTED***', $text);

        // Redact Bearer tokens
        $sanitized = preg_replace('/(Bearer\s+)[A-Za-z0-9\-_.~+\/]{20,}/i', '$1***REDACTED***', $sanitized);

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

    public function getSanitizedReasonAttribute(): ?string
    {
        return self::sanitizeText($this->reason);
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
