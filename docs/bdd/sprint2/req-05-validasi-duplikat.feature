# Feature: REQ-05 – Review & Validasi Duplikat Issue
# Author : Fakhri Reza Abinaya (REZA) – Kelompok 4, Kelas I1
# Sprint : 2
# SRS Ref: Tabel 4.28 – REQ-05.1 s/d REQ-05.14
#
# ⚠️  BDD BLOCKED
# Behat runner (branch feature/sprint1-bdd-behat / PR #35) belum di-merge ke main.
# composer.json di main TIDAK memiliki behat/behat.
# File ini mendokumentasikan skenario BDD sesuai SRS dan siap dijalankan begitu
# PR #35 di-merge dan runner tersedia.

Feature: Review & Validasi Duplikat Issue (REQ-05)
  Sebagai Maintainer pemilik project
  Saya ingin meninjau dan memvalidasi kandidat duplikat issue yang terdeteksi NLP
  Agar daftar issue tetap bersih dan tidak redundan

  Background:
    Given terdapat project "Proyek Alpha" milik Maintainer "reza@kel4.dev"
    And terdapat Reporter "budi@kel4.dev" yang aktif di project tersebut
    And terdapat Issue #1 "Bug Login" dengan detection_status "COMPLETED" dan vote_count 5
    And terdapat Issue #2 "Gagal Masuk Akun" dengan detection_status "COMPLETED" dan vote_count 2
    And terdapat DuplicateCandidate dengan similarity_score 0.885 antara Issue #1 dan Issue #2

  # REQ-05.1 – Daftar kandidat duplikat terpusat
  Scenario: Maintainer melihat daftar kandidat duplikat project
    Given saya login sebagai Maintainer "reza@kel4.dev"
    When saya mengunjungi halaman "/projects/{project}/duplicates"
    Then saya melihat heading "Daftar Kandidat Duplikat"
    And saya melihat pasangan "Bug Login" dan "Gagal Masuk Akun"
    And saya melihat skor kemiripan "88,50%"

  # REQ-05.2 – Tampilkan issue FAILED
  Scenario: Maintainer melihat issue yang gagal dideteksi NLP
    Given terdapat Issue #3 "Issue Gagal NLP" dengan detection_status "FAILED"
    And saya login sebagai Maintainer "reza@kel4.dev"
    When saya mengunjungi halaman "/projects/{project}/duplicates"
    Then saya melihat "Issue Gagal NLP" di bagian "Issue Deteksi Gagal"
    And saya melihat tombol "Proses Ulang"

  # REQ-05.3 – Perbandingan berdampingan
  Scenario: Maintainer membuka halaman perbandingan dua issue
    Given saya login sebagai Maintainer "reza@kel4.dev"
    When saya mengunjungi halaman compare untuk pasangan Issue #1 dan Issue #2
    Then saya melihat heading "Bandingkan Pasangan Issue"
    And saya melihat detail Issue #1 dengan "Vote: 5"
    And saya melihat detail Issue #2 dengan "Vote: 2"
    And saya melihat rekomendasi "Disarankan — Vote Terbanyak (5 vs 2)" untuk Issue #1

  # REQ-05.4 – Rekomendasi NLP bukan keputusan otomatis
  Scenario: Skor NLP hanya ditampilkan sebagai rekomendasi bukan keputusan otomatis
    Given saya login sebagai Maintainer "reza@kel4.dev"
    When saya mengunjungi halaman compare untuk pasangan Issue #1 dan Issue #2
    Then saya melihat skor kemiripan "88.50" di halaman
    And status kandidat masih "PENDING" (belum otomatis DUPLICATE)
    And saya harus memilih "Konfirmasi Duplikat" atau "Bukan Duplikat" secara manual

  # REQ-05.5 – Aturan relasi
  Scenario: Maintainer melihat aturan relasi duplikat di halaman perbandingan
    Given saya login sebagai Maintainer "reza@kel4.dev"
    When saya mengunjungi halaman compare untuk pasangan Issue #1 dan Issue #2
    Then saya melihat kotak "Aturan Relasi Duplikat"
    And saya melihat kotak "Ringkasan Konsekuensi Status"

  # REQ-05.6 – Rekomendasi vote-based
  Scenario: Sistem merekomendasikan issue dengan vote terbanyak sebagai induk
    Given saya login sebagai Maintainer "reza@kel4.dev"
    When saya mengunjungi halaman compare
    Then Issue #1 (vote 5) ditandai "Disarankan — Vote Terbanyak"

  # REQ-05.7 – Konfirmasi DUPLICATE mengubah status child
  Scenario: Maintainer mengonfirmasi Issue #2 sebagai duplikat dari Issue #1
    Given saya login sebagai Maintainer "reza@kel4.dev"
    And Issue #1 berstatus "OPEN"
    And Issue #2 berstatus "OPEN"
    When saya submit keputusan "DUPLICATE" dengan induk Issue #1
    Then Issue #2 berstatus "DUPLICATE"
    And Issue #2 memiliki duplicate_of = Issue #1
    And kandidat memiliki decision "DUPLICATE"

  # REQ-05.7 – Child CLOSED jika parent RESOLVED/CLOSED
  Scenario: Anak langsung CLOSED jika induk sudah RESOLVED
    Given saya login sebagai Maintainer "reza@kel4.dev"
    And Issue #1 berstatus "RESOLVED"
    When saya submit keputusan "DUPLICATE" dengan induk Issue #1
    Then Issue #2 berstatus "CLOSED"
    And Issue #2 memiliki duplicate_of = Issue #1

  # REQ-05.8 – NOT_DUPLICATE tidak mengubah status
  Scenario: Maintainer memutuskan bukan duplikat
    Given saya login sebagai Maintainer "reza@kel4.dev"
    When saya submit keputusan "NOT_DUPLICATE"
    Then Issue #1 tetap "OPEN"
    And Issue #2 tetap "OPEN"
    And kandidat memiliki decision "NOT_DUPLICATE"

  # REQ-05.9 – Pembatalan duplikat wajib alasan
  Scenario: Pembatalan penandaan duplikat ditolak tanpa alasan
    Given Issue #2 sudah berstatus "DUPLICATE" dari Issue #1
    And saya login sebagai Maintainer "reza@kel4.dev"
    When saya submit pembatalan dengan reason kosong
    Then saya mendapat error validasi "Alasan Koreksi wajib diisi"
    And Issue #2 masih berstatus "DUPLICATE"

  Scenario: Pembatalan penandaan duplikat berhasil dengan alasan valid
    Given Issue #2 sudah berstatus "DUPLICATE" dari Issue #1
    And saya login sebagai Maintainer "reza@kel4.dev"
    When saya submit pembatalan dengan reason "Setelah analisis lebih lanjut kedua issue memiliki akar masalah berbeda."
    Then Issue #2 kembali berstatus "OPEN"
    And Issue #2 tidak memiliki duplicate_of
    And kandidat kembali ke decision "PENDING"
    And terdapat activity_log dengan action "DUPLICATE_CORRECTED"

  # REQ-05.10 – Validasi server: induk harus dari pasangan
  Scenario: Keputusan ditolak jika parent_issue_id bukan bagian dari pasangan
    Given saya login sebagai Maintainer "reza@kel4.dev"
    When saya submit keputusan "DUPLICATE" dengan parent_issue_id = 99999
    Then respon status 422 (Unprocessable Entity)

  # REQ-05.11 – Validasi server: induk tidak boleh duplikat
  Scenario: Keputusan ditolak jika calon induk sendiri adalah duplikat
    Given Issue #1 sudah berstatus "DUPLICATE" (memiliki duplicate_of)
    And saya login sebagai Maintainer "reza@kel4.dev"
    When saya submit keputusan "DUPLICATE" dengan induk Issue #1
    Then respon status 409 dengan pesan "Induk bukan duplikat"

  # REQ-05.11 – Validasi server: tidak membentuk siklus
  Scenario: Keputusan ditolak jika akan membentuk siklus duplikat
    Given Issue #2 sudah memiliki anak duplikat Issue #3
    And saya login sebagai Maintainer "reza@kel4.dev"
    When saya submit keputusan "DUPLICATE" dengan induk Issue #1 (menjadikan Issue #2 anak)
    Then respon status 409 dengan pesan "Tidak membentuk siklus"

  # REQ-05.12 – Retry deteksi FAILED
  Scenario: Maintainer memproses ulang issue gagal deteksi NLP
    Given terdapat Issue #3 "Issue Gagal" dengan detection_status "FAILED"
    And saya login sebagai Maintainer "reza@kel4.dev"
    When saya POST ke "/projects/{project}/issues/{issue}/detection/retry"
    Then Issue #3 berstatus "PENDING" (detection_status)
    And job DetectIssueCandidatesJob di-dispatch

  # REQ-05.13 – Concurrency guard pada retry
  Scenario: Retry kedua ditolak jika status bukan FAILED (concurrency guard)
    Given Issue #3 sudah di-retry dan sekarang berstatus "PENDING"
    And saya login sebagai Maintainer "reza@kel4.dev"
    When saya POST retry lagi
    Then respon redirect dengan flash "status_notice"
    And tidak ada job baru yang di-dispatch

  # REQ-05.14 – Activity log dicatat
  Scenario: Setiap keputusan review tercatat di activity_logs
    Given saya login sebagai Maintainer "reza@kel4.dev"
    When saya submit keputusan "NOT_DUPLICATE"
    Then terdapat satu record di activity_logs dengan action "DUPLICATE_REVIEW"
    And record tersebut memiliki user_id Maintainer dan project_id project

  # Keamanan – Akses terbatas
  Scenario: Reporter tidak dapat mengakses halaman duplikat
    Given saya login sebagai Reporter "budi@kel4.dev"
    When saya mengunjungi halaman "/projects/{project}/duplicates"
    Then respon status 403 (Forbidden)

  Scenario: Maintainer project lain tidak dapat mengakses duplikat
    Given saya login sebagai Maintainer dari project lain
    When saya mengunjungi halaman "/projects/{project}/duplicates"
    Then respon status 403 (Forbidden)

  Scenario: Guest diarahkan ke halaman login
    Given saya tidak login
    When saya mengunjungi halaman "/projects/{project}/duplicates"
    Then saya diarahkan ke "/login"

