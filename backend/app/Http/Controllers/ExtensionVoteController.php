<?php

namespace App\Http\Controllers;

use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Services\ExtensionAuthService;
use App\Services\ExtensionRepositoryService;
use App\Services\VoteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExtensionVoteController extends Controller
{
    public function __construct(
        private readonly ExtensionRepositoryService $repositories,
        private readonly ExtensionAuthService $auth,
        private readonly VoteService $votes
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $input = $request->validate([
                'repository' => ExtensionRepositoryService::repositoryRules(),
                'project_id' => ['required', 'integer', 'min:1'],
                'issue_id' => ['required', 'integer', 'min:1'],
            ]);
            $actor = $request->user();
            $repository = strtolower($input['repository']);
            $project = $this->repositories->resolveProject($actor, $repository, (int) $input['project_id']);
            abort_unless($project !== null, 403);

            // Snapshot before the transaction, just as web route binding does.
            $issue = Issue::query()->where('project_id', $project->id)->findOrFail($input['issue_id']);
            $connection = GithubAppConnection::where('project_id', $project->id)->first();
            abort_unless($connection !== null, 403);

            $guard = function () use ($request, $actor, $project, $repository, $connection): void {
                $currentActor = $this->auth->authenticate((string) $request->bearerToken(), lockForUpdate: true);
                abort_unless($currentActor && (int) $currentActor->id === (int) $actor->id, 401);

                // The service already holds project, user and membership locks.
                // Lock the actual connection row, not only an EXISTS subquery.
                $currentConnection = GithubAppConnection::where('project_id', $project->id)
                    ->lockForUpdate()->first();
                abort_unless($currentConnection
                    && $currentConnection->id === $connection->id
                    && $currentConnection->repository_id === $connection->repository_id
                    && $currentConnection->installation_id === $connection->installation_id
                    && $this->repositories->resolveProject($currentActor, $repository, (int) $project->id), 403);
            };

            $desiredVote = match ($request->method()) {
                'POST' => true,
                'DELETE' => false,
                default => null,
            };
            $result = $this->votes->execute($actor, $project, $issue, $desiredVote, $guard);

            return $this->json([
                'issue_id' => (int) $issue->id,
                'target_issue_id' => (int) $result['issue']->id,
                'voted' => $result['voted'],
                'vote_count' => $result['vote_count'],
                'changed' => $result['changed'],
            ]);
        } catch (ValidationException $exception) {
            return $this->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Permintaan voting tidak valid.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (ModelNotFoundException $exception) {
            return $this->json(['code' => 'NOT_FOUND', 'message' => 'Issue tidak tersedia.'], 404);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $code = match ($status) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'VOTE_TARGET_CHANGED',
                default => 'VOTE_UNAVAILABLE',
            };

            return $this->json(['code' => $code, 'message' => 'Voting tidak tersedia. Muat ulang status issue.'], $status);
        } catch (QueryException $exception) {
            // SQL and bindings may contain sensitive data; never log them here.
            Log::warning('Extension voting database operation unavailable', [
                'exception_type' => get_class($exception),
            ]);

            return $this->json(['code' => 'VOTING_UNAVAILABLE', 'message' => 'Voting belum dapat diproses.'], 503);
        }
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];
        if ($status === 401) {
            $headers['WWW-Authenticate'] = 'Bearer';
        }

        return response()->json($data, $status, $headers);
    }
}
