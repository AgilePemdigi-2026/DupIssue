<?php

declare(strict_types=1);

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Soulcodex\Behat\Addon\Context;

/**
 * REQ-05 Duplicate Review BDD acceptance steps through Mink and real Laravel controllers.
 */
final class DuplicateReviewBddContext extends Context
{
    private const PASSWORD = 'BddTemporaryPassword123!';
    private ?Project $project = null;
    private ?User $maintainer = null;
    private ?User $reporter = null;
    /** @var array<int, Issue> */
    private array $issues = [];
    private ?DuplicateCandidate $candidatePair = null;
    private int $dispatchedJobCount = 0;
    private ?int $lastStatusCode = null;
    private ?string $lastResponseContent = null;
    private mixed $lastResponse = null;

    /** @Given terdapat project :name milik Maintainer :email */
    public function terdapatProjectMilikMaintainer(string $name, string $email): void
    {
        $this->maintainer = User::factory()->create([
            'email' => $email,
            'role' => 'MAINTAINER',
            'password' => self::PASSWORD,
        ]);

        $this->project = Project::forceCreate([
            'name' => $name,
            'description' => 'Project untuk BDD Sprint 2 REQ-05',
            'owner_id' => $this->maintainer->id,
        ]);
    }

    /** @Given terdapat Reporter :email yang aktif di project tersebut */
    public function terdapatReporterYangAktif(string $email): void
    {
        $this->reporter = User::factory()->create([
            'email' => $email,
            'role' => 'REPORTER',
            'password' => self::PASSWORD,
        ]);

        Membership::create([
            'project_id' => $this->project->id,
            'user_id' => $this->reporter->id,
            'is_active' => true,
        ]);
    }

    /** @Given terdapat Issue #:id :title dengan detection_status :status dan vote_count :votes */
    public function terdapatIssueDenganDetectionStatusDanVotes(string $id, string $title, string $status, string $votes): void
    {
        $issue = Issue::forceCreate([
            'id' => (int) $id,
            'project_id' => $this->project->id,
            'author_id' => $this->reporter?->id ?? $this->maintainer->id,
            'title' => $title,
            'description' => 'Deskripsi untuk ' . $title,
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'vote_count' => (int) $votes,
            'detection_status' => $status,
        ]);
        $this->issues[(int) $id] = $issue;
    }

    /** @Given terdapat DuplicateCandidate dengan similarity_score :score antara Issue #:id1 dan Issue #:id2 */
    public function terdapatDuplicateCandidate(string $score, string $id1, string $id2): void
    {
        $this->candidatePair = DuplicateCandidate::create([
            'issue_id' => (int) $id1,
            'candidate_issue_id' => (int) $id2,
            'similarity_score' => (float) $score,
            'decision' => 'PENDING',
        ]);
    }

    /** @Given saya login sebagai Maintainer :email */
    public function sayaLoginSebagaiMaintainer(string $email): void
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            $user = User::factory()->create([
                'email' => $email,
                'role' => 'MAINTAINER',
                'password' => self::PASSWORD,
            ]);
        }
        $this->loginAsUser($user);
    }

    /** @Given saya login sebagai Reporter :email */
    public function sayaLoginSebagaiReporter(string $email): void
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            $user = User::factory()->create([
                'email' => $email,
                'role' => 'REPORTER',
                'password' => self::PASSWORD,
            ]);
        }
        $this->loginAsUser($user);
    }

    /** @Given saya login sebagai Maintainer dari project lain */
    public function sayaLoginSebagaiMaintainerLain(): void
    {
        $user = User::factory()->create([
            'email' => 'other_maintainer@kel4.dev',
            'role' => 'MAINTAINER',
            'password' => self::PASSWORD,
        ]);
        $this->loginAsUser($user);
    }

    /** @Given saya tidak login */
    public function sayaTidakLogin(): void
    {
        Auth::logout();
        $this->session()->reset();
        app('session.store')->flush();
        app('auth')->forgetGuards();
        $this->lastStatusCode = null;
    }

    private function loginAsUser(User $user): void
    {
        $this->visitUrl('/login');
        $page = $this->session()->getPage();
        $page->fillField('email', $user->email);
        $page->fillField('password', self::PASSWORD);
        $form = $page->find('css', 'form');
        if ($form === null) {
            throw new RuntimeException('Login form not found on ' . $this->session()->getCurrentUrl());
        }
        $form->submit();
    }

    /** @When /^saya mengunjungi halaman "(\/[^"]*)"$/ */
    public function sayaMengunjungiHalaman(string $path): void
    {
        $actualPath = str_replace('{project}', (string) $this->project->id, $path);
        try {
            $this->visitUrl($actualPath);
            $this->lastStatusCode = $this->session()->getStatusCode();
        } catch (\Exception $e) {
            $this->lastStatusCode = $this->session()->getDriver()->getStatusCode();
        }
    }

    /** @Then saya melihat heading :text */
    public function sayaMelihatHeading(string $text): void
    {
        $page = $this->session()->getPage();
        if (! str_contains($page->getText(), $text)) {
            throw new RuntimeException("Heading '$text' tidak ditemukan.");
        }
    }

    /** @Then saya melihat pasangan :p1 dan :p2 */
    public function sayaMelihatPasangan(string $p1, string $p2): void
    {
        $text = $this->session()->getPage()->getText();
        if (! str_contains($text, $p1) || ! str_contains($text, $p2)) {
            throw new RuntimeException("Pasangan '$p1' dan '$p2' tidak ditemukan di halaman.");
        }
    }

    /** @Then saya melihat skor kemiripan :score */
    public function sayaMelihatSkorKemiripan(string $score): void
    {
        $text = $this->session()->getPage()->getText();
        if (! str_contains($text, $score)) {
            throw new RuntimeException("Skor kemiripan '$score' tidak ditemukan di halaman.");
        }
    }

    /** @Then /^saya melihat skor kemiripan "([^"]*)" di halaman$/ */
    public function sayaMelihatSkorKemiripanDiHalaman(string $score): void
    {
        $text = $this->session()->getPage()->getText();
        $altScore = str_replace('.', ',', $score);
        if (! str_contains($text, $score) && ! str_contains($text, $altScore)) {
            throw new RuntimeException("Skor kemiripan '$score' tidak ditemukan di halaman.");
        }
    }

    /** @Given terdapat Issue #:id :title dengan detection_status :status */
    public function terdapatIssueDenganDetectionStatus(string $id, string $title, string $status): void
    {
        $issue = Issue::forceCreate([
            'id' => (int) $id,
            'project_id' => $this->project->id,
            'author_id' => $this->reporter?->id ?? $this->maintainer->id,
            'title' => $title,
            'description' => 'Deskripsi untuk ' . $title,
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'vote_count' => 0,
            'detection_status' => $status,
        ]);
        $this->issues[(int) $id] = $issue;
    }

    /** @Then saya melihat :title di bagian :section */
    public function sayaMelihatDiBagian(string $title, string $section): void
    {
        $text = $this->session()->getPage()->getText();
        if (! str_contains($text, $title)) {
            throw new RuntimeException("'$title' tidak ditemukan di halaman.");
        }
    }

    /** @Then saya melihat tombol :button */
    public function sayaMelihatTombol(string $button): void
    {
        $text = $this->session()->getPage()->getText();
        if (! str_contains($text, $button)) {
            throw new RuntimeException("Tombol '$button' tidak ditemukan di halaman.");
        }
    }

    /** @When saya mengunjungi halaman compare untuk pasangan Issue #:id1 dan Issue #:id2 */
    public function sayaMengunjungiHalamanCompare(string $id1, string $id2): void
    {
        $cand = DuplicateCandidate::where('issue_id', (int) $id1)
            ->where('candidate_issue_id', (int) $id2)
            ->firstOrFail();
        $url = "/projects/{$this->project->id}/issues/{$id1}/candidates/{$cand->id}/compare";
        try {
            $this->visitUrl($url);
            $this->lastStatusCode = $this->session()->getStatusCode();
        } catch (\Exception $e) {
            $this->lastStatusCode = $this->session()->getDriver()->getStatusCode();
        }
    }

    /** @When /^saya mengunjungi halaman compare$/ */
    public function sayaMengunjungiHalamanCompareDefault(): void
    {
        $this->sayaMengunjungiHalamanCompare('1', '2');
    }


    /** @Then saya melihat detail Issue #:id dengan :text */
    public function sayaMelihatDetailIssueDengan(string $id, string $text): void
    {
        $pageText = $this->session()->getPage()->getText();
        if (! str_contains($pageText, $text)) {
            throw new RuntimeException("Detail '$text' untuk Issue #$id tidak ditemukan.");
        }
    }

    /** @Then saya melihat rekomendasi :text untuk Issue #:id */
    public function sayaMelihatRekomendasiUntukIssue(string $text, string $id): void
    {
        $pageText = $this->session()->getPage()->getText();
        if (! str_contains($pageText, $text)) {
            throw new RuntimeException("Rekomendasi '$text' tidak ditemukan.");
        }
    }

    /** @Then status kandidat masih :status */
    public function statusKandidatMasih(string $status): void
    {
        $cand = DuplicateCandidate::where('issue_id', 1)->where('candidate_issue_id', 2)->first();
        if ($cand === null || $cand->decision !== $status) {
            throw new RuntimeException("Status kandidat bukan '$status'.");
        }
    }

    /** @Then /^status kandidat masih "([^"]*)" \(belum otomatis DUPLICATE\)$/ */
    public function statusKandidatMasihBelumOtomatis(string $status): void
    {
        $this->statusKandidatMasih($status);
    }

    /** @Then saya harus memilih :opt1 atau :opt2 secara manual */
    public function sayaHarusMemilihSecaraManual(string $opt1, string $opt2): void
    {
        $pageText = $this->session()->getPage()->getText();
        if (! str_contains($pageText, $opt1) || ! str_contains($pageText, $opt2)) {
            throw new RuntimeException("Opsi '$opt1' atau '$opt2' tidak lengkap di halaman.");
        }
    }

    /** @Then saya melihat kotak :text */
    public function sayaMelihatKotak(string $text): void
    {
        $pageText = $this->session()->getPage()->getText();
        if (! str_contains($pageText, $text)) {
            throw new RuntimeException("Kotak '$text' tidak ditemukan.");
        }
    }

    /** @Then Issue #:id ditandai :text */
    public function issueDitandai(string $id, string $text): void
    {
        $pageText = $this->session()->getPage()->getText();
        if (! str_contains($pageText, $text)) {
            throw new RuntimeException("Issue #$id tidak ditandai '$text'.");
        }
    }

    /** @Then /^Issue #(\d+) \(vote (\d+)\) ditandai "([^"]*)"$/ */
    public function issueVoteDitandai(string $id, string $votes, string $label): void
    {
        $pageText = $this->session()->getPage()->getText();
        if (! str_contains($pageText, $label)) {
            throw new RuntimeException("Issue #$id (vote $votes) tidak ditandai '$label'");
        }
    }

    /** @Given Issue #:id berstatus :status */
    public function issueBerstatus(string $id, string $status): void
    {
        DB::table('issues')->where('id', (int) $id)->update(['status' => $status]);
    }

    private function postWithCsrf(string $url, array $parameters = []): void
    {
        $client = $this->session()->getDriver()->getClient();
        $client->followRedirects(false);
        $token = csrf_token() ?: (string) session()->token();
        $parameters['_token'] = $token;
        $referer = $this->session()->getCurrentUrl() ?: ('http://localhost' . $url);
        $server = [
            'HTTP_X_CSRF_TOKEN' => $token,
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'HTTP_REFERER' => $referer,
        ];
        try {
            $client->request('POST', $url, $parameters, [], $server);
            $this->lastResponse = $client->getResponse();
            $this->lastStatusCode = $client->getResponse()->getStatusCode();
            $this->lastResponseContent = (string) $client->getResponse()->getContent();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->lastResponse = $client->getResponse();
            $this->lastStatusCode = $e->getStatusCode();
            $this->lastResponseContent = $e->getMessage();
        } catch (\Throwable $e) {
            $this->lastResponse = $client->getResponse();
            $this->lastStatusCode = 500;
            $this->lastResponseContent = $e->getMessage();
        }
    }

    /** @When saya submit keputusan :decision dengan induk Issue #:parentId */
    public function sayaSubmitKeputusan(string $decision, string $parentId): void
    {
        $source = Issue::findOrFail(1);
        $candidate = Issue::findOrFail(2);
        $pair = DuplicateCandidate::where('issue_id', 1)->where('candidate_issue_id', 2)->firstOrFail();

        $this->postWithCsrf("/projects/{$this->project->id}/issues/{$source->id}/candidates/{$pair->id}/decision", [
            'decision' => $decision,
            'parent_issue_id' => (int) $parentId,
            'expected_decision' => $pair->decision,
            'expected_source_status' => $source->status,
            'expected_candidate_status' => $candidate->status,
            'expected_source_parent' => (int) ($source->duplicate_of ?? 0),
            'expected_candidate_parent' => (int) ($candidate->duplicate_of ?? 0),
        ]);
    }

    /** @When saya submit keputusan :decision */
    public function sayaSubmitKeputusanNotDuplicate(string $decision): void
    {
        $source = Issue::findOrFail(1);
        $candidate = Issue::findOrFail(2);
        $pair = DuplicateCandidate::where('issue_id', 1)->where('candidate_issue_id', 2)->firstOrFail();

        $this->postWithCsrf("/projects/{$this->project->id}/issues/{$source->id}/candidates/{$pair->id}/decision", [
            'decision' => $decision,
            'expected_decision' => $pair->decision,
            'expected_source_status' => $source->status,
            'expected_candidate_status' => $candidate->status,
            'expected_source_parent' => (int) ($source->duplicate_of ?? 0),
            'expected_candidate_parent' => (int) ($candidate->duplicate_of ?? 0),
        ]);
    }

    /** @Then Issue #:id memiliki duplicate_of = Issue #:parentId */
    public function issueMemilikiDuplicateOf(string $id, string $parentId): void
    {
        $issue = Issue::findOrFail((int) $id);
        if ((int) $issue->duplicate_of !== (int) $parentId) {
            throw new RuntimeException("Issue #$id duplicate_of diharapkan #$parentId, tetapi bernilai {$issue->duplicate_of}");
        }
    }

    /** @Then kandidat memiliki decision :decision */
    public function kandidatMemilikiDecision(string $decision): void
    {
        $pair = DuplicateCandidate::where('issue_id', 1)->where('candidate_issue_id', 2)->firstOrFail();
        if ($pair->decision !== $decision) {
            throw new RuntimeException("Kandidat decision diharapkan $decision, tetapi bernilai {$pair->decision}");
        }
    }

    /** @Then Issue #:id tetap :status */
    public function issueTetapStatus(string $id, string $status): void
    {
        $issue = Issue::findOrFail((int) $id);
        if ($issue->status !== $status) {
            throw new RuntimeException("Issue #$id status diharapkan $status, tetapi bernilai {$issue->status}");
        }
    }

    /** @Given Issue #:childId sudah berstatus :status dari Issue #:parentId */
    public function issueSudahBerstatusDari(string $childId, string $status, string $parentId): void
    {
        DB::table('issues')->where('id', (int) $childId)->update([
            'status' => $status,
            'duplicate_of' => (int) $parentId,
        ]);
        DB::table('duplicate_candidates')
            ->where('issue_id', (int) $parentId)
            ->where('candidate_issue_id', (int) $childId)
            ->update([
                'decision' => 'DUPLICATE',
                'decided_by' => $this->maintainer->id,
                'decided_at' => now(),
            ]);
    }

    /** @When saya submit pembatalan dengan reason kosong */
    public function sayaSubmitPembatalanDenganReasonKosong(): void
    {
        $child = Issue::findOrFail(2);
        $this->postWithCsrf("/projects/{$this->project->id}/issues/{$child->id}/duplicate/cancel", [
            'reason' => '',
            'expected_duplicate_of' => (int) $child->duplicate_of,
            'expected_status' => $child->status,
        ]);
    }

    /** @When /^saya submit pembatalan dengan reason "([^"]+)"$/ */
    public function sayaSubmitPembatalanDenganReason(string $reason): void
    {
        $child = Issue::findOrFail(2);
        $this->postWithCsrf("/projects/{$this->project->id}/issues/{$child->id}/duplicate/cancel", [
            'reason' => $reason,
            'expected_duplicate_of' => (int) $child->duplicate_of,
            'expected_status' => $child->status,
        ]);
    }

    /** @Then /^saya mendapat error validasi "([^"]*)"$/ */
    public function sayaMendapatErrorValidasi(string $error): void
    {
        $allErrors = [];

        // 1. Check from RedirectResponse session if available
        $resp = $this->lastResponse;
        if ($resp && method_exists($resp, 'getSession') && $resp->getSession()) {
            $bag = $resp->getSession()->get('errors');
            if ($bag && method_exists($bag, 'all')) {
                $allErrors = array_merge($allErrors, $bag->all());
            }
        }

        // 2. Check from global session
        if (session('errors')) {
            $allErrors = array_merge($allErrors, session('errors')->all());
        }
        if (app('session.store')->get('errors')) {
            $allErrors = array_merge($allErrors, app('session.store')->get('errors')->all());
        }

        $allErrors = array_unique($allErrors);

        $found = false;
        foreach ($allErrors as $msg) {
            if (str_contains($msg, $error)) {
                $found = true;
                break;
            }
        }

        if (! $found) {
            $client = $this->session()->getDriver()->getClient();
            try {
                if ($this->lastStatusCode === 302) {
                    $client->followRedirect();
                }
                $pageText = $this->session()->getPage()->getText();
                if (str_contains($pageText, $error)) {
                    $found = true;
                }
            } catch (\Throwable) {}
        }

        if (! $found) {
            throw new RuntimeException("Error validasi '$error' tidak ditemukan di session atau halaman. Error yang ada: " . json_encode($allErrors));
        }
    }

    /** @Then Issue #:id masih berstatus :status */
    public function issueMasihBerstatus(string $id, string $status): void
    {
        $this->issueTetapStatus($id, $status);
    }

    /** @Then Issue #:id kembali berstatus :status */
    public function issueKembaliBerstatus(string $id, string $status): void
    {
        $this->issueTetapStatus($id, $status);
    }

    /** @Then Issue #:id tidak memiliki duplicate_of */
    public function issueTidakMemilikiDuplicateOf(string $id): void
    {
        $issue = Issue::findOrFail((int) $id);
        if ($issue->duplicate_of !== null) {
            throw new RuntimeException("Issue #$id masih memiliki duplicate_of: {$issue->duplicate_of}");
        }
    }

    /** @Then /^kandidat kembali ke decision "([^"]*)"$/ */
    public function kandidatKembaliKeDecision(string $decision): void
    {
        $cand = DuplicateCandidate::where('issue_id', 1)->where('candidate_issue_id', 2)->first();
        if ($cand === null || $cand->decision !== $decision) {
            throw new RuntimeException("Kandidat decision diharapkan $decision, tapi bernilai {$cand?->decision}");
        }
    }

    /** @Then terdapat activity_log dengan action :action */
    public function terdapatActivityLogDenganAction(string $action): void
    {
        $exists = DB::table('activity_logs')
            ->where('project_id', $this->project->id)
            ->where('action', $action)
            ->exists();
        if (! $exists) {
            throw new RuntimeException("activity_log dengan action '$action' tidak ditemukan.");
        }
    }

    /** @When saya submit keputusan :decision dengan parent_issue_id = :parentId */
    public function sayaSubmitKeputusanInvalidParent(string $decision, string $parentId): void
    {
        $source = Issue::findOrFail(1);
        $candidate = Issue::findOrFail(2);
        $pair = DuplicateCandidate::where('issue_id', 1)->where('candidate_issue_id', 2)->firstOrFail();

        $this->postWithCsrf("/projects/{$this->project->id}/issues/{$source->id}/candidates/{$pair->id}/decision", [
            'decision' => $decision,
            'parent_issue_id' => (int) $parentId,
            'expected_decision' => $pair->decision,
            'expected_source_status' => $source->status,
            'expected_candidate_status' => $candidate->status,
            'expected_source_parent' => 0,
            'expected_candidate_parent' => 0,
        ]);
    }

    /** @Then respon status :code */
    public function responStatus(string $code): void
    {
        if ((int) $this->lastStatusCode !== (int) $code) {
            throw new RuntimeException("Status code diharapkan $code, tetapi didapatkan {$this->lastStatusCode}");
        }
    }

    /** @Then respon status :code dengan pesan :msg */
    public function responStatusDenganPesan(string $code, string $msg): void
    {
        if ((int) $this->lastStatusCode !== (int) $code) {
            throw new RuntimeException("Status code diharapkan $code, tetapi didapatkan {$this->lastStatusCode}");
        }

        $content = $this->lastResponseContent ?? '';
        $pageText = '';
        try {
            $pageText = $this->session()->getPage()->getText();
        } catch (\Throwable $e) {}

        if (! str_contains($content, $msg) && ! str_contains($pageText, $msg)) {
            throw new RuntimeException("Pesan '$msg' tidak ditemukan dalam respon status $code. Konten respon: '$content'");
        }
    }

    /** @Given /^Issue #(\d+) sudah berstatus "([^"]*)" \(memiliki duplicate_of\)$/ */
    public function issueSudahBerstatusMemilikiDuplicateOf(string $id, string $status): void
    {
        $grandparent = Issue::forceCreate([
            'project_id' => $this->project->id,
            'author_id' => $this->maintainer->id,
            'title' => 'Grandparent Issue',
            'description' => 'Induk teratas',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);
        DB::table('issues')->where('id', (int) $id)->update([
            'status' => $status,
            'duplicate_of' => $grandparent->id,
        ]);
    }

    /** @Given Issue #:childId sudah memiliki anak duplikat Issue #:grandchildId */
    public function issueSudahMemilikiAnakDuplikat(string $childId, string $grandchildId): void
    {
        Issue::forceCreate([
            'id' => (int) $grandchildId,
            'project_id' => $this->project->id,
            'author_id' => $this->reporter->id,
            'title' => 'Grandchild Issue',
            'description' => 'Issue cucu',
            'source_type' => 'FORM',
            'status' => 'DUPLICATE',
            'duplicate_of' => (int) $childId,
            'vote_count' => 0,
            'detection_status' => 'COMPLETED',
        ]);
    }

    /** @When saya submit keputusan :decision dengan induk Issue #:parentId (menjadikan Issue #:childId anak) */
    public function sayaSubmitKeputusanCycle(string $decision, string $parentId, string $childId): void
    {
        $this->sayaSubmitKeputusan($decision, $parentId);
    }

    /** @When saya POST ke :path */
    public function sayaPostKe(string $path): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);
        $actualPath = str_replace(
            ['{project}', '{issue}'],
            [(string) $this->project->id, '3'],
            $path
        );
        $this->postWithCsrf($actualPath);
        $this->dispatchedJobCount = count(Bus::dispatched(DetectIssueCandidatesJob::class));
    }

    /** @Then /^Issue #(\d+) berstatus "([^"]*)" \(detection_status\)$/ */
    public function issueBerstatusDetectionStatus(string $id, string $status): void
    {
        $issue = Issue::findOrFail((int) $id);
        if ($issue->detection_status !== $status) {
            throw new RuntimeException("detection_status Issue #$id bernilai '{$issue->detection_status}', diharapkan '$status'");
        }
    }

    /** @Then job DetectIssueCandidatesJob di-dispatch */
    public function jobDetectIssueCandidatesJobDiDispatch(): void
    {
        if ($this->dispatchedJobCount < 1) {
            throw new RuntimeException('Job DetectIssueCandidatesJob tidak di-dispatch.');
        }
    }

    /** @Given Issue #:id sudah di-retry dan sekarang berstatus :status */
    public function issueSudahDiRetry(string $id, string $status): void
    {
        $issue = Issue::find((int) $id);
        if ($issue === null) {
            $this->terdapatIssueDenganDetectionStatus($id, 'Issue #' . $id, $status);
        } else {
            $issue->update(['detection_status' => $status]);
        }
    }

    /** @When saya POST retry lagi */
    public function sayaPostRetryLagi(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);
        $actualPath = "/projects/{$this->project->id}/issues/3/detection/retry";
        $this->postWithCsrf($actualPath);
        $this->dispatchedJobCount = count(Bus::dispatched(DetectIssueCandidatesJob::class));
    }

    /** @Then respon redirect dengan flash :key */
    public function responRedirectDenganFlash(string $key): void
    {
        if ($this->lastStatusCode !== 302 && $this->lastStatusCode !== 301) {
            throw new RuntimeException("Diharapkan redirect (302/301), didapatkan status {$this->lastStatusCode}");
        }

        $hasFlash = false;
        $resp = $this->lastResponse;
        if ($resp && method_exists($resp, 'getSession') && $resp->getSession()) {
            $session = $resp->getSession();
            $hasFlash = $session->has($key) || $session->get($key) !== null;
        }

        if (! $hasFlash) {
            $hasFlash = session()->has($key)
                || app('session.store')->has($key)
                || session()->get($key) !== null
                || app('session.store')->get($key) !== null;
        }

        if (! $hasFlash) {
            throw new RuntimeException("Flash message dengan key '$key' tidak ditemukan di session.");
        }
    }

    /** @Then tidak ada job baru yang di-dispatch */
    public function tidakAdaJobBaruDiDispatch(): void
    {
        if ($this->dispatchedJobCount > 0) {
            throw new RuntimeException("Terdapat {$this->dispatchedJobCount} job yang di-dispatch, seharusnya 0.");
        }
    }

    /** @Then terdapat satu record di activity_logs dengan action :action */
    public function terdapatSatuRecordDiActivityLogs(string $action): void
    {
        $count = DB::table('activity_logs')
            ->where('project_id', $this->project->id)
            ->where('action', $action)
            ->count();
        if ($count !== 1) {
            throw new RuntimeException("Jumlah record activity_logs untuk action '$action' adalah $count, diharapkan 1.");
        }
    }

    /** @Then record tersebut memiliki user_id Maintainer dan project_id project */
    public function recordMemilikiUserIdDanProjectId(): void
    {
        $log = DB::table('activity_logs')
            ->where('project_id', $this->project->id)
            ->latest('id')
            ->first();
        if ($log === null) {
            throw new RuntimeException('activity_logs record tidak ditemukan.');
        }
        if ((int) $log->user_id !== (int) $this->maintainer->id) {
            throw new RuntimeException("user_id di log ({$log->user_id}) tidak sesuai ID maintainer ({$this->maintainer->id}).");
        }
    }

    /** @Then /^respon status (\d+) \(Forbidden\)$/ */
    public function responStatusForbidden(string $code): void
    {
        if ((int) $this->lastStatusCode !== (int) $code) {
            throw new RuntimeException("Status code diharapkan $code, didapatkan {$this->lastStatusCode}");
        }
    }

    /** @Then /^respon status (\d+) \(Unprocessable Entity\)$/ */
    public function responStatusUnprocessableEntity(string $code): void
    {
        if ((int) $this->lastStatusCode !== (int) $code) {
            throw new RuntimeException("Status code diharapkan $code, didapatkan {$this->lastStatusCode}");
        }
    }

    /** @Then saya diarahkan ke :path */
    public function sayaDiarahkanKe(string $path): void
    {
        $currentUrl = $this->session()->getCurrentUrl();
        if (! str_contains($currentUrl, $path)) {
            throw new RuntimeException("URL saat ini ($currentUrl) tidak mengarah ke '$path'.");
        }
    }
}
