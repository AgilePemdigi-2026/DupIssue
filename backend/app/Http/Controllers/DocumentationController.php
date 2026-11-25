<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DocumentationController extends Controller
{
    private const TOPICS = [
        'introduction' => [
            'category' => 'GETTING STARTED',
            'title' => 'Introduction',
            'subtitle' => 'Memahami tujuan, peran, dan alur kerja DupIssue.',
            'search' => 'dupissue NLP semantic similarity reporter maintainer triase issue duplikat',
        ],
        'getting-started' => [
            'category' => 'GETTING STARTED',
            'title' => 'Getting Started',
            'subtitle' => 'Panduan awal membuat akun, login, dan membuka project.',
            'search' => 'registrasi login lupa password project dashboard reporter maintainer',
        ],
        'account-and-user-roles' => [
            'category' => 'USER GUIDES',
            'title' => 'Account & User Roles',
            'subtitle' => 'Memahami hak akses Reporter dan Maintainer.',
            'search' => 'reporter maintainer role membership vote submit project status validasi',
        ],
        'project-and-member-management' => [
            'category' => 'USER GUIDES',
            'title' => 'Project & Member Management',
            'subtitle' => 'Panduan Maintainer mengelola project dan anggota.',
            'search' => 'project membuat mengubah undangan anggota member aktif batalkan hapus',
        ],
        'submit-an-issue' => [
            'category' => 'USER GUIDES',
            'title' => 'Submit an Issue',
            'subtitle' => 'Melaporkan issue baru dan memeriksa rekomendasi serupa.',
            'search' => 'submit issue rekomendasi similarity vote batal submit laporan baru',
        ],
        'track-issue-status' => [
            'category' => 'USER GUIDES',
            'title' => 'Track Issue Status',
            'subtitle' => 'Mencari, membuka, dan memantau perkembangan issue.',
            'search' => 'status OPEN IN_PROGRESS RESOLVED CLOSED DUPLICATE PENDING PROCESSING COMPLETED FAILED',
        ],
        'review-and-validate-duplicates' => [
            'category' => 'USER GUIDES',
            'title' => 'Review & Validate Duplicates',
            'subtitle' => 'Panduan Maintainer meninjau dan memutuskan kandidat duplikat.',
            'search' => 'candidate duplicate maintain review parent relasi koreksi histori',
        ],
        'import-issues' => [
            'category' => 'USER GUIDES',
            'title' => 'Import Issues (CSV & GitHub)',
            'subtitle' => 'Memahami impor issue CSV dan GitHub untuk Maintainer.',
            'search' => 'CSV source_id title description GitHub PAT REST API preview import repository',
        ],
        'dashboard-and-activity-logs' => [
            'category' => 'USER GUIDES',
            'title' => 'Dashboard & Activity Logs',
            'subtitle' => 'Memahami statistik project dan histori aktivitas yang tersedia.',
            'search' => 'dashboard statistika total issue status duplicate vote triase activity logs histori',
        ],
        'frequently-asked-questions' => [
            'category' => 'HELP',
            'title' => 'Frequently Asked Questions',
            'subtitle' => 'Jawaban atas pertanyaan umum penggunaan DupIssue.',
            'search' => 'FAQ login project duplicate vote status impor detections',
        ],
        'troubleshooting' => [
            'category' => 'HELP',
            'title' => 'Troubleshooting',
            'subtitle' => 'Langkah dasar mengatasi kendala saat menggunakan aplikasi.',
            'search' => 'gagal login project submit rekomendasi deteksi vote validasi CSV GitHub',
        ],
    ];

    public function show(?string $topic = null): View
    {
        $topic = $topic ?: 'introduction';
        abort_unless(array_key_exists($topic, self::TOPICS), 404);

        $topics = self::TOPICS;
        $keys = array_keys($topics);
        $position = array_search($topic, $keys, true);

        return view('documentation.show', [
            'topic' => $topic,
            'topics' => $topics,
            'current' => $topics[$topic],
            'previousTopic' => $position > 0 ? $keys[$position - 1] : null,
            'nextTopic' => $position < count($keys) - 1 ? $keys[$position + 1] : null,
        ]);
    }
}
