<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Additive, idempotent Sprint 2 demonstration fixture. CLI only; never runs on deploy.
 */
final class SeedSprint2Demo extends Command
{
    protected $signature = 'demo:sprint2
        {--create : Create only missing demo accounts, memberships and example issues}
        {--reset-passwords : Rotate passwords of existing demo users (requires --create)}';

    protected $description = 'Prepare isolated demonstration accounts and sample Sprint 2 issues without deleting production data';

    private const PROJECT_NAME = '[DEMO SPRINT 2] Bug Tracker Kampus';

    private const USERS = [
        ['role' => 'MAINTAINER', 'name' => 'Maintainer Demo Sprint 2', 'email' => 'sprint2.maintainer@dupissue.test'],
        ['role' => 'REPORTER', 'name' => 'Reporter A Demo Sprint 2', 'email' => 'sprint2.reporter.a@dupissue.test'],
        ['role' => 'REPORTER', 'name' => 'Reporter B Demo Sprint 2', 'email' => 'sprint2.reporter.b@dupissue.test'],
    ];

    public function handle(): int
    {
        if ($this->option('reset-passwords') && ! $this->option('create')) {
            $this->error('--reset-passwords requires --create.');
            return self::FAILURE;
        }

        if (! $this->option('create')) {
            $this->info('DRY RUN: no database changes.');
            $this->line('Command: php artisan demo:sprint2 --create');
            $this->line('Accounts: sprint2.maintainer@dupissue.test; sprint2.reporter.a@dupissue.test; sprint2.reporter.b@dupissue.test');
            $this->line('Project: '.self::PROJECT_NAME);
            $this->warn('This creates TEST accounts in the currently configured database. Verify container and DB before --create.');
            return self::SUCCESS;
        }

        foreach (['users', 'projects', 'memberships', 'issues'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing table {$table}. Run approved migrations first (never migrate:fresh on production).");
                return self::FAILURE;
            }
        }

        // Never populate SQLite :memory: for a deployed demonstration.
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'dupissue') {
            $this->error('REFUSED: requires MySQL connection to the dedicated DupIssue database.');
            return self::FAILURE;
        }

        /** @var array<string, string|null> $credentials */
        $credentials = [];
        $createdIssues = [];
        $projectId = null;
        $reset = (bool) $this->option('reset-passwords');

        try {
            DB::transaction(function () use (&$credentials, &$createdIssues, &$projectId, $reset): void {
                $people = [];
                foreach (self::USERS as $spec) {
                    $person = User::query()->where('email', $spec['email'])->lockForUpdate()->first();
                    $new = $person === null;
                    if ($new) {
                        $person = new User();
                        $person->name = $spec['name'];
                        $person->email = $spec['email'];
                        $person->role = $spec['role'];
                        $person->email_verified_at = now();
                    } elseif ($person->role !== $spec['role'] || $person->name !== $spec['name']) {
                        throw new RuntimeException('Refusing to take over an existing account: '.$spec['email']);
                    }

                    $password = null;
                    if ($new || $reset) {
                        // Random 24-character alphanumeric password, never stored in source or logs.
                        $password = Str::random(24);
                        $person->password = Hash::make($password);
                        $person->save();
                    }
                    $people[$spec['role'].'|'.$spec['email']] = $person;
                    $credentials[$spec['email']] = $password;
                }

                $owner = $people['MAINTAINER|sprint2.maintainer@dupissue.test'];
                $reporterA = $people['REPORTER|sprint2.reporter.a@dupissue.test'];
                $reporterB = $people['REPORTER|sprint2.reporter.b@dupissue.test'];

                $project = Project::query()->where('name', self::PROJECT_NAME)->lockForUpdate()->first();
                if ($project === null) {
                    $project = new Project();
                    $project->owner_id = $owner->getKey();
                    $project->name = self::PROJECT_NAME;
                    $project->description = 'Data dummy khusus demonstrasi Sprint 2: rekomendasi NLP, review duplikat, Voting, dan Issue Status.';
                    $project->save();
                } elseif ((int) $project->owner_id !== (int) $owner->getKey()) {
                    throw new RuntimeException('Existing demo project is owned by a different user; refusing to edit it.');
                }
                $projectId = (int) $project->getKey();

                foreach ([$reporterA, $reporterB] as $reporter) {
                    $membership = Membership::query()
                        ->firstOrNew(['project_id' => $projectId, 'user_id' => $reporter->getKey()]);
                    $membership->joined_at = $membership->joined_at ?? now();
                    $membership->removed_at = null;
                    $membership->is_active = true;
                    $membership->save();
                }

                $samples = [
                    [
                        'title' => '[DEMO S2] Tombol Login tidak merespons di Chrome',
                        'description' => 'Ketika Reporter memasukkan email dan password di halaman login lalu menekan tombol Masuk, halaman tetap berada di formulir dan tidak menampilkan pesan kesalahan. Masalah dapat direproduksi pada browser Google Chrome.',
                        'status' => 'OPEN', 'author' => $reporterA,
                    ],
                    [
                        'title' => '[DEMO S2] Halaman dashboard project lambat dimuat',
                        'description' => 'Halaman dashboard project membutuhkan waktu lama untuk menampilkan daftar issue, terutama ketika banyak issue sudah terdaftar di dalam project. Pengguna melihat loading berkepanjangan.',
                        'status' => 'IN_PROGRESS', 'author' => $reporterB,
                    ],
                    [
                        'title' => '[DEMO S2] Lampiran screenshot gagal diunggah',
                        'description' => 'Ketika Reporter mencoba menambahkan screenshot pada laporan masalah, aplikasi tidak menampilkan berkas yang dipilih dan pengguna mengira proses unggah telah selesai.',
                        'status' => 'OPEN', 'author' => $reporterA,
                    ],
                    [
                        'title' => '[DEMO S2] Filter daftar issue tidak diperbarui',
                        'description' => 'Filter status pada halaman daftar issue sebelumnya tidak memperbarui hasil ketika nilai status diubah. Masalah sudah ditangani dan disediakan sebagai contoh issue RESOLVED.',
                        'status' => 'RESOLVED', 'author' => $reporterB,
                    ],
                ];
                $saved = [];
                foreach ($samples as $spec) {
                    $saved[$spec['title']] = $this->ensureIssue($projectId, $spec['author'], $spec['title'], $spec['description'], $spec['status']);
                    $createdIssues[$spec['title']] = (int) $saved[$spec['title']]->getKey();
                }

                // This is an explicitly seeded duplicate demonstration fixture.
                $original = $saved['[DEMO S2] Tombol Login tidak merespons di Chrome'];
                $childTitle = '[DEMO S2] Duplikat: tombol login tidak merespons';
                $child = Issue::query()->where('project_id', $projectId)->where('title', $childTitle)->first();
                if ($child === null) {
                    $child = new Issue();
                    $child->project_id = $projectId;
                    $child->author_id = $reporterB->getKey();
                    $child->title = $childTitle;
                    $child->description = 'Saat tombol Masuk di halaman login ditekan setelah mengisi email dan password, halaman tidak berpindah. Issue dummy ini sudah ditautkan ke issue induk untuk demonstrasi relasi duplikat.';
                    $child->source_type = 'FORM';
                    $child->status = 'DUPLICATE';
                    $child->detection_status = 'COMPLETED';
                    $child->duplicate_of = $original->getKey();
                    $child->vote_count = 0;
                    $child->save();
                } elseif ((int) $child->duplicate_of !== (int) $original->getKey()) {
                    throw new RuntimeException('Existing demo duplicate has an unexpected parent; not overwriting it.');
                }
                $createdIssues[$childTitle] = (int) $child->getKey();
            }, 3);
        } catch (\Throwable $error) {
            $this->error('ABORTED (database transaction rolled back): '.$error->getMessage());
            return self::FAILURE;
        }

        $this->info("SUCCESS: Sprint 2 demo data ready in project #{$projectId}.");
        $this->table(['Role', 'Email', 'Password (new/rotated only)'], array_map(
            static fn (array $spec): array => [
                $spec['role'],
                $spec['email'],
                $credentials[$spec['email']] ?? '(existing; unchanged)',
            ],
            self::USERS
        ));
        $this->line('Sample issues (titles and IDs):');
        foreach ($createdIssues as $title => $id) {
            $this->line("  #{$id} {$title}");
        }
        $this->warn('COPY PASSWORDS NOW to a private password manager; passwords are displayed only upon creation or rotation.');
        $this->warn('Never screenshot or share terminal credentials. Account emails use reserved .test domain; OTP mail demo requires separate real inboxes.');
        $this->line('Project URL: '.rtrim((string) config('app.url'), '/').'/projects/'.$projectId);
        $this->line('To rotate demo passwords intentionally: php artisan demo:sprint2 --create --reset-passwords');

        return self::SUCCESS;
    }

    private function ensureIssue(int $projectId, User $author, string $title, string $description, string $status): Issue
    {
        $issue = Issue::query()->where('project_id', $projectId)->where('title', $title)->first();
        if ($issue !== null) {
            return $issue;
        }
        $issue = new Issue();
        $issue->project_id = $projectId;
        $issue->author_id = $author->getKey();
        $issue->title = $title;
        $issue->description = $description;
        $issue->source_type = 'FORM';
        $issue->status = $status;
        // Static seed fixtures are not a claim of a real NLP inference; new UI submissions trigger real NLP.
        $issue->detection_status = 'COMPLETED';
        $issue->duplicate_of = null;
        $issue->vote_count = 0;
        $issue->save();
        return $issue;
    }
}
