<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ExtensionRepositoryService
{
    /**
     * Validate a GitHub repository identifier, not a URL.
     */
    public static function repositoryRules(): array
    {
        return [
            'required',
            'string',
            'max:140',
            'regex:/\A[A-Za-z0-9-]{1,39}\/(?!\.{1,2}\z)[A-Za-z0-9_.-]{1,100}\z/',
        ];
    }

    /**
     * Return only projects currently available to this Reporter
     * for the selected GitHub repository.
     */
    public function availableProjects(
        User $actor,
        string $repository
    ): array {
        return $this->authorizedProjects(
            $actor,
            $repository
        )
            ->orderBy('projects.id')
            ->get(['projects.id', 'projects.name'])
            ->map(static fn (Project $project): array => [
                'id' => (int) $project->id,
                'name' => $project->name,
            ])
            ->all();
    }

    /**
     * Resolve an explicitly selected project.
     *
     * Recommendation and Vote APIs must call this method again
     * for every request. A previously resolved project ID is
     * not sufficient evidence of current authorization.
     */
    public function resolveProject(
        User $actor,
        string $repository,
        int $projectId
    ): ?Project {
        return $this->authorizedProjects(
            $actor,
            $repository
        )
            ->whereKey($projectId)
            ->first();
    }

    private function authorizedProjects(
        User $actor,
        string $repository
    ): Builder {
        abort_unless(
            $actor->role === 'REPORTER',
            403,
            'Akses tidak tersedia.'
        );

        return Project::query()
            ->whereHas(
                'memberships',
                static function (Builder $query) use ($actor): void {
                    $query
                        ->where('user_id', $actor->getKey())
                        ->where('is_active', true);
                }
            )
            ->whereExists(
                static function ($query) use ($repository): void {
                    $query->selectRaw('1')
                        ->from('github_app_connections')
                        ->whereColumn(
                            'github_app_connections.project_id',
                            'projects.id'
                        )
                        ->whereRaw(
                            'LOWER(github_app_connections.repo_full_name) = ?',
                            [strtolower($repository)]
                        )
                        ->where(
                            'github_app_connections.repository_id',
                            '>',
                            0
                        )
                        ->where(
                            'github_app_connections.installation_id',
                            '>',
                            0
                        )
                        ->whereNotNull(
                            'github_app_connections.verified_at'
                        );
                }
            );
    }
}
