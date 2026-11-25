<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class GithubIssuePreviewService
{
    /**
     * Validate GitHub issues and classify import eligibility.
     *
     * This method never inserts or updates database records.
     *
     * @param array<int, mixed> $githubIssues
     * @return array<string, mixed>
     */
    public function preview(
        Project $project,
        string $repoFullName,
        array $githubIssues
    ): array {
        $repoFullName = trim($repoFullName);

        if (
            strlen($repoFullName) > 255
            || ! preg_match(
                '~\A[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9._-]+\z~',
                $repoFullName
            )
            || in_array(
                explode('/', $repoFullName)[1] ?? '',
                ['.', '..'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid GitHub repository name.'
            );
        }

        // Exclude pull requests from the preview.
        $items = [];

        foreach ($githubIssues as $item) {
            if (
                is_array($item)
                && array_key_exists('pull_request', $item)
            ) {
                continue;
            }

            $items[] = $item;
        }

        // Collect GitHub issue numbers.
        $numbers = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $number = $item['number'] ?? null;

            if (
                is_int($number)
                && $number > 0
                && $number <= 4294967295
            ) {
                $numbers[] = $number;
            }
        }

        // Find existing issues in the same project
        // and GitHub repository.
        $existingNumbers = [];

        foreach (
            array_chunk(array_unique($numbers), 400) as $chunk
        ) {
            $found = Issue::query()
                ->where('project_id', $project->getKey())
                ->where('source_type', 'GITHUB')
                ->whereRaw(
                    'LOWER(github_repo) = ?',
                    [strtolower($repoFullName)]
                )
                ->whereIn('github_number', $chunk)
                ->pluck('github_number');

            foreach ($found as $number) {
                $existingNumbers[(int) $number] = true;
            }
        }

        $seenNumbers = [];
        $previewRows = [];

        $validCount = 0;
        $invalidCount = 0;
        $importedCount = 0;

        foreach ($items as $index => $item) {
            $number = is_array($item)
                ? ($item['number'] ?? null)
                : null;

            $title = is_array($item)
                && is_string($item['title'] ?? null)
                    ? trim($item['title'])
                    : null;

            $description = is_array($item)
                && is_string($item['body'] ?? null)
                    ? trim($item['body'])
                    : null;

            $sourceUrl = is_int($number) && $number > 0
                ? 'https://github.com/'
                    . $repoFullName
                    . '/issues/'
                    . $number
                : null;

            $data = [
                'github_repo' => $repoFullName,
                'github_number' => $number,
                'title' => $title,
                'description' => $description,
                'source_url' => $sourceUrl,
            ];

            $result = [
                'record_number' => $index + 1,
                'status' => 'INVALID',
                'reason' => null,
                'data' => $data,
            ];

            if (
                ! is_int($number)
                || $number <= 0
                || $number > 4294967295
            ) {
                $result['reason'] =
                    'Nomor issue GitHub tidak valid.';

                $invalidCount++;
                $previewRows[] = $result;
                continue;
            }

            // Apply the same content limits as CSV.
            $validator = Validator::make($data, [
                'title' => [
                    'required', 'string', 'min:5', 'max:200',
                ],
                'description' => [
                    'required', 'string', 'min:10', 'max:10000',
                ],
            ]);

            if ($validator->fails()) {
                $result['reason'] =
                    $validator->errors()->first();

                $invalidCount++;
                $previewRows[] = $result;
                continue;
            }

            if (isset($existingNumbers[$number])) {
                $result['status'] = 'ALREADY_IMPORTED';
                $result['reason'] =
                    'Issue GitHub sudah pernah diimpor.';

                $importedCount++;
            } elseif (isset($seenNumbers[$number])) {
                $result['reason'] =
                    'Nomor issue berulang dalam hasil GitHub.';

                $invalidCount++;
            } else {
                $seenNumbers[$number] = true;

                $result['status'] = 'VALID';
                $validCount++;
            }

            $previewRows[] = $result;
        }

        return [
            'repo_full_name' => $repoFullName,
            'total_rows' => count($previewRows),
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'already_imported_count' => $importedCount,
            'rows' => $previewRows,
        ];
    }
}
