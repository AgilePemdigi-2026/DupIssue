<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Soulcodex\Behat\Addon\Context;

final class IssueRecommendationBddContext extends Context
{
    private array $sameProjectIds = [];
    private ?int $foreignIssueId = null;
    private int $baselineIssueCount = 0;
    private array $responseData = [];
    private ?int $responseStatus = null;
    private ?int $parentIssueId = null;
    private ?int $duplicateIssueId = null;


    /**
     * @Given I have four comparison issues in my project and one in another project
     */
    public function iHaveFourComparisonIssues(): void
    {
        $project = Project::findOrFail(1);
        $reporter = User::where(
            'email',
            'reporter.sprint2@example.test'
        )->firstOrFail();

        for ($i = 1; $i <= 4; $i++) {
            $issue = $this->createIssue(
                $project,
                $reporter,
                "Issue pembanding {$i}"
            );

            $this->sameProjectIds[] = (int) $issue->id;
        }

        $foreignProject = Project::factory()->create();

        $foreignIssue = $this->createIssue(
            $foreignProject,
            $reporter,
            'Issue dari project lain'
        );

        $this->foreignIssueId = (int) $foreignIssue->id;
        $this->baselineIssueCount = Issue::count();
    }

    /**
     * @Given I have deterministic NLP rankings for Sprint 2
     */
    public function iHaveDeterministicNlpRankings(): void
    {
        if (count($this->sameProjectIds) !== 4 ||
            $this->foreignIssueId === null) {
            throw new RuntimeException(
                'Fixture issue harus dibuat terlebih dahulu.'
            );
        }

        $ids = $this->sameProjectIds;

        Http::fake([
            'http://nlp.test/candidates' => Http::response([
                'candidates' => [
                    [
                        'issue_id' => $this->foreignIssueId,
                        'similarity' => 0.99,
                    ],
                    [
                        'issue_id' => $ids[3],
                        'similarity' => 0.41,
                    ],
                    [
                        'issue_id' => $ids[2],
                        'similarity' => 0.73,
                    ],
                    [
                        'issue_id' => $ids[0],
                        'similarity' => 0.92,
                    ],
                    [
                        'issue_id' => $ids[1],
                        'similarity' => 0.81,
                    ],
                ],
            ], 200),
        ]);
    }

    /**
     * @When I request pre-submit recommendations with title :title and description :description
     */
    public function iRequestPreSubmitRecommendations(
        string $title,
        string $description
    ): void {
        $this->visitUrl('/projects/1/submit');

        $tokenField = $this->session()
            ->getPage()
            ->find('css', 'input[name="_token"]');

        if ($tokenField === null) {
            throw new RuntimeException('CSRF token tidak ditemukan.');
        }

        $token = $tokenField->getValue();

        $driver = $this->session()->getDriver();

        if (!method_exists($driver, 'getClient')) {
            throw new RuntimeException(
                'BDD membutuhkan Laravel BrowserKit driver.'
            );
        }

        $client = $driver->getClient();

        $client->request(
            'POST',
            'http://localhost/projects/1/issues/recommendations',
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ],
            json_encode([
                'title' => $title,
                'description' => $description,
            ], JSON_THROW_ON_ERROR)
        );

        $response = $client->getResponse();

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(
                'Expected HTTP 200, received HTTP ' .
                $response->getStatusCode() .
                ': ' . $response->getContent()
            );
        }

        $this->responseData = json_decode(
            $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @Then I should receive exactly 3 recommendations ordered by similarity
     */
    public function iShouldReceiveTopThree(): void
    {
        $recommendations = $this->responseData['recommendations'] ?? [];

        if (count($recommendations) !== 3) {
            throw new RuntimeException(
                'Expected 3 recommendations, received ' .
                count($recommendations)
            );
        }

        $actualIds = array_map(
            fn (array $item): int => (int) $item['issue_id'],
            $recommendations
        );

        $expectedIds = array_slice($this->sameProjectIds, 0, 3);

        if ($actualIds !== $expectedIds) {
            throw new RuntimeException(
                'Top 3 issue tidak sesuai urutan similarity.'
            );
        }

        $actualScores = array_map(
            fn (array $item): float => (float) $item['similarity'],
            $recommendations
        );

        $expectedScores = [0.92, 0.81, 0.73];

        foreach ($expectedScores as $index => $expected) {
            if (abs($actualScores[$index] - $expected) > 0.00001) {
                throw new RuntimeException(
                    'Similarity tidak sesuai pada posisi ' . $index
                );
            }
        }
    }

    /**
     * @Then I should receive no recommendations from another project
     */
    public function iShouldReceiveNoForeignRecommendations(): void
    {
        foreach ($this->responseData['recommendations'] ?? [] as $item) {
            if ((int) $item['issue_id'] === $this->foreignIssueId ||
                (int) $item['target_issue_id'] === $this->foreignIssueId) {
                throw new RuntimeException(
                    'Issue dari project lain muncul dalam rekomendasi.'
                );
            }
        }
    }

    /**
     * @Then I should find no new draft issue in the database
     */
    public function iShouldFindNoNewDraftIssue(): void
    {
        if (Issue::count() !== $this->baselineIssueCount) {
            throw new RuntimeException(
                'Request rekomendasi tidak boleh menyimpan issue baru.'
            );
        }
    }


    /**
     * @When I request pre-submit recommendations while NLP is unavailable
     */
    public function iRequestRecommendationsWhileNlpUnavailable(): void
    {
        $this->visitUrl('/projects/1/submit');

        $tokenField = $this->session()
            ->getPage()
            ->find('css', 'input[name="_token"]');

        if ($tokenField === null) {
            throw new RuntimeException('CSRF token tidak ditemukan.');
        }

        $driver = $this->session()->getDriver();

        if (!method_exists($driver, 'getClient')) {
            throw new RuntimeException(
                'BDD membutuhkan Laravel BrowserKit driver.'
            );
        }

        // Catat jumlah issue sebelum request rekomendasi.
        // Fixture dari FeatureContext sudah dibuat pada langkah Given.
        $this->baselineIssueCount = Issue::count();

        $client = $driver->getClient();

        $client->request(
            'POST',
            'http://localhost/projects/1/issues/recommendations',
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => (string) $tokenField->getValue(),
            ],
            json_encode([
                'title' => 'Tombol bayar tidak merespons',
                'description' => 'Tombol pembayaran gagal memproses transaksi.',
            ], JSON_THROW_ON_ERROR)
        );

        $response = $client->getResponse();

        $this->responseStatus = $response->getStatusCode();

        $this->responseData = json_decode(
            $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @Then I should receive HTTP :status with code :code
     */
    public function iShouldReceiveHttpWithCode(
        int $status,
        string $code
    ): void {
        if ($this->responseStatus !== $status) {
            throw new RuntimeException(
                "Expected HTTP {$status}, received HTTP " .
                ($this->responseStatus ?? 'null')
            );
        }

        $actualCode = $this->responseData['code'] ?? null;

        if ($actualCode !== $code) {
            throw new RuntimeException(
                "Expected error code {$code}, received " .
                ($actualCode ?? 'null')
            );
        }

        $recommendations = $this->responseData['recommendations'] ?? null;

        if ($recommendations !== []) {
            throw new RuntimeException(
                'NLP gagal, tetapi respons rekomendasi tidak kosong.'
            );
        }
    }


    /**
     * @When I request pre-submit recommendations with invalid :field :value
     */
    public function iRequestRecommendationsWithInvalidInput(
        string $field,
        string $value
    ): void {
        if (!in_array($field, ['title', 'description'], true)) {
            throw new RuntimeException('Unsupported validation field.');
        }

        $this->visitUrl('/projects/1/submit');

        $tokenField = $this->session()
            ->getPage()
            ->find('css', 'input[name="_token"]');

        if ($tokenField === null) {
            throw new RuntimeException('CSRF token tidak ditemukan.');
        }

        $driver = $this->session()->getDriver();

        if (!method_exists($driver, 'getClient')) {
            throw new RuntimeException(
                'BDD membutuhkan Laravel BrowserKit driver.'
            );
        }

        $this->baselineIssueCount = Issue::count();

        $payload = [
            'title' => 'Judul issue yang valid',
            'description' => 'Deskripsi issue yang memenuhi batas minimal.',
        ];

        $payload[$field] = $value;

        $client = $driver->getClient();

        $client->request(
            'POST',
            'http://localhost/projects/1/issues/recommendations',
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => (string) $tokenField->getValue(),
            ],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $response = $client->getResponse();

        $this->responseStatus = $response->getStatusCode();

        $this->responseData = json_decode(
            $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @Then I should receive HTTP :status with validation error for :field
     */
    public function iShouldReceiveValidationError(
        int $status,
        string $field
    ): void {
        if ($this->responseStatus !== $status) {
            throw new RuntimeException(
                "Expected HTTP {$status}, received " .
                ($this->responseStatus ?? 'null')
            );
        }

        $errors = $this->responseData['errors'] ?? [];

        if (!isset($errors[$field]) || !is_array($errors[$field])) {
            throw new RuntimeException(
                "Expected validation error for {$field}."
            );
        }

        // Input tidak valid harus ditolak sebelum memanggil NLP.
        Http::assertNothingSent();
    }

    /**
     * @Given I have a duplicate issue linked to its parent in my project
     */
    public function iHaveDuplicateIssueLinkedToParent(): void
    {
        $project = Project::findOrFail(1);

        $reporter = User::where(
            'email',
            'reporter.sprint2@example.test'
        )->firstOrFail();

        $parent = $this->createIssue(
            $project,
            $reporter,
            'Issue induk checkout gagal'
        );

        $duplicate = $this->createIssue(
            $project,
            $reporter,
            'Issue duplikat checkout gagal'
        );

        Issue::query()
            ->whereKey($duplicate->id)
            ->update([
                'status' => 'DUPLICATE',
                'duplicate_of' => $parent->id,
            ]);

        $this->parentIssueId = (int) $parent->id;
        $this->duplicateIssueId = (int) $duplicate->id;

        $this->baselineIssueCount = Issue::count();
    }

    /**
     * @Given I have an NLP candidate pointing to the duplicate issue
     */
    public function iHaveNlpCandidatePointingToDuplicate(): void
    {
        if ($this->duplicateIssueId === null) {
            throw new RuntimeException(
                'Duplicate fixture belum dibuat.'
            );
        }

        Http::fake([
            'http://nlp.test/candidates' => Http::response([
                'candidates' => [
                    [
                        'issue_id' => $this->duplicateIssueId,
                        'similarity' => 0.94,
                    ],
                ],
            ], 200),
        ]);
    }

    /**
     * @Then I should receive a recommendation linking to the original issue
     */
    public function iShouldReceiveRecommendationLinkingToOriginal(): void
    {
        if ($this->parentIssueId === null ||
            $this->duplicateIssueId === null) {
            throw new RuntimeException(
                'Fixture parent dan duplicate belum lengkap.'
            );
        }

        $recommendations = $this->responseData['recommendations'] ?? [];

        if (count($recommendations) !== 1) {
            throw new RuntimeException(
                'Expected exactly one recommendation.'
            );
        }

        $recommendation = $recommendations[0];

        if ((int) ($recommendation['issue_id'] ?? 0)
            !== $this->duplicateIssueId) {
            throw new RuntimeException(
                'Candidate issue ID tidak sesuai.'
            );
        }

        if ((int) ($recommendation['target_issue_id'] ?? 0)
            !== $this->parentIssueId) {
            throw new RuntimeException(
                'Rekomendasi tidak mengarah ke issue induk.'
            );
        }

        $expectedUrl = '/projects/1/issues/' . $this->parentIssueId;
        $actualUrl = $recommendation['detail_url'] ?? null;

        if ($actualUrl !== $expectedUrl) {
            throw new RuntimeException(
                "Expected URL {$expectedUrl}, received " .
                ($actualUrl ?? 'null')
            );
        }
    }




    private function createIssue(
        Project $project,
        User $reporter,
        string $title
    ): Issue {
        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'title' => $title,
            'description' => 'Deskripsi issue pembanding untuk BDD Sprint 2.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
        ]);
    }
}
