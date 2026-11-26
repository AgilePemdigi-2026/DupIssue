# Traceability SRS dan perubahan scope E5–F

## Feature 5 final checkpoint - 26 November 2026

> This checkpoint supersedes earlier statements concerning
> current test totals, opened-only webhook scope and required
> GitHub App permissions. Previous checkpoints are retained
> as historical records. Merge, deployment and formal
> acceptance remain pending.

### Feature 5 traceability

| Area | SRS relationship | Acceptance |
| --- | --- | --- |
| Explicit Maintainer status changes | REQ-06 | Local QA passed |
| Existing status transitions | REQ-06 | Regression passed |
| Status history | REQ-06.11 | Actual QA passed |
| Failure and retry safeguards | REQ-06.10 and extension | Automated QA passed |
| Repository identity and isolation | REQ-04 and extension | Actual QA passed |
| Project authorization | REQ-07.3 | Regression passed |
| Bidirectional GitHub status synchronization | Additional scope | Formal approval pending |

Bidirectional GitHub state synchronization extends the original
SRS. Local implementation and verification do not constitute
formal scope approval.

Duplicate review remains distinct from explicit issue status
management. A duplicate decision does not automatically imply
GitHub issue closure.

### Review and release gates

- Technical Lead review of shared controllers, schema changes,
  locking and queue configuration.
- NLP optimization review by the responsible Technical Lead.
- Voting integration review by the responsible module owner.
- Issue-status and duplicate-review module-owner approval.
- Formal Product Owner or appropriate course-team acceptance
  of the additional Feature 5 scope.
- Production configuration, migration and deployment approval.

See FINAL_QA_REPORT.md for current QA results.

---

## Final local QA checkpoint — 26 November 2026

### Final restoration and cleanup status

The user confirmed restoration of the known development
GitHub App settings, including:

- Original homepage, callback URLs and setup URL.
- Additional QA callback removed.
- Webhook disabled and Issues event subscription removed.
- Original read-only permissions retained.
- Repository access restricted to the original testing repository.

Restoration is based on user confirmation, not a separate API audit.
Exact original webhook secret and SSL settings were not established.

All dedicated QA service listeners have been stopped or verified inactive.
Private QA artifacts and database evidence remain preserved.

The local integration database retains its final tested connection
to the second repository as historical QA evidence.
That repository is no longer authorized by the development GitHub App.

No new staging, commit, push, PR, merge or deployment performed.



This checkpoint supersedes earlier status statements. Previous sections remain as historical evidence.

### Final local acceptance status

| Enhancement | Status |
| --- | --- |
| Extension recommendation and voting | Tested scenarios PASS |
| Automatic GitHub issue import | Two actual issues PASS |
| Webhook redelivery deduplication | PASS |
| CSV Top 3 and threshold | PASS |
| CSV 5000-row asynchronous import | PASS |
| Full 5000-job backlog | Not established |
| Repository replacement and cancellation | Tested scenarios PASS |
| Multi-installation selection | Automated coverage |
| PAT retirement and history preservation | Automated regression PASS |
| Cold-cache response target | Not accepted |
| Production deployment | Not executed |

Local implementation testing is not formal SRS acceptance.

### Remaining limitations and approval gates

- REQ-NF-02 and REQ-NF-05 remain partially verified.
- Cold-cache response does not meet the 3-second SRS target.
- Full 5000-job backlog completion is not established.
- Twenty-user sustained load and production SLA remain unverified.
- Docker and remote CI have not been executed.
- Actual replacement across separate GitHub installations was not tested.
- Actual Maintainer-specific duplicate review remains unverified.
- NLP optimization requires Technical Lead approval.
- Shared voting changes require the relevant owner review.
- PAT retirement and replacement confirmation require scope approval.
- Known development GitHub App settings restored by user confirmation.
- No new staging, commit, push, PR, merge or deployment authorized.

---

Sumber: [SRS v1.0](artifacts/srs/DupIssue_SRS_v1.0.pdf), dibaca sebagai dokumen
109 halaman. Nomor di bawah merujuk halaman PDF, bukan nomor cetak (selisih empat).
SRS tidak diubah. User menyetujui pengembangan lokal E5–F; persetujuan Technical
Lead atau perubahan baseline SRS belum tersedia.
Update FINAL QA: original-model web/extension parity dan actual Chrome for Testing
dengan GitHub fixture lulus. Actual GitHub DOM/Edge belum diuji. Hasil performance
di bawah adalah pengukuran lokal, bukan jaminan SLA atau approval production.

Update F2: bounded embedding cache eksperimental lokal disetujui pengguna untuk
implementasi/testing saja. [Laporan performa](NLP_PERFORMANCE_REPORT.md) memisahkan
cold/warm/incremental, model asli dan mocked regression. Shared NLP tetap pending
Technical Lead review; model/threshold/parent/Top3/human review tidak berubah.

| Requirement | Dampak implementasi | Evidence / status |
| --- | --- | --- |
| REQ-01.1–01.4 (PDF 46) | Active membership, batas draft, debounce500ms, Top3 satu project dipakai extension | ExtensionWorkflow/Recommendation/Vote tests; Node workspace tests PASS_LOCAL |
| REQ-01.5–01.9 (PDF 47) | Stale response dibuang, valid parent, detail aman, empty/error tidak mengubah draft GitHub | Node contracts/workspace/render; live browser pending |
| REQ-01.15–01.18 (PDF 47) | Votes existing, unique ballot, count atomic, VOTE_ADDED/REMOVED | E4 regression; MySQL concurrent vote/unvote nyata PASS |
| REQ-04.1,04.4,04.6,04.15 (PDF 71–72) | Manual owner/preview/confirmation tetap; webhook adalah jalur service-to-service baru | Existing import tests PASS; pengecualian automatic import terhadap04.15 perlu amendemen |
| REQ-04.3 (PDF 72) | CSV UTF8/5MB/5000-row validation existing dipertahankan | CSV suite dan kapasitas5000 insertion PASS_LOCAL |
| REQ-04.5,04.14 (PDF 72) | Identitas repository/number diperkuat stable ID, history/source metadata/last_synced preserved | Sync tests + MySQL manual/webhook race PASS |
| REQ-04.7 (PDF 72) | Semua issue baru dari import tetap OPEN/PENDING/author_id null | Shared persistence dan regression closed-remote snapshot |
| REQ-04.8 (PDF 72) | Manual import_batches dipertahankan; automatic delivery memakai task + SYNC_GITHUB/system attribution | Scope amendment diperlukan: bukan human import_batch palsu |
| REQ-04.9 (PDF 72) | Database queue setelah commit, failure tidak menghapus issue | Real worker integration, rollback, recovery PASS |
| REQ-04.10–04.12 dan REQ-NF-19 (PDF72,95) | PAT UI/routes retired pada tahap sebelumnya; GitHub App/token sementara menggantikan PAT runtime | Legacy retirement/replacement tests PASS; amendemen PAT requirement diperlukan |
| REQ-04.13 (PDF72) | Manual pagination/PR exclusion preserved; webhook mengambil satu issue asli dengan ID terverifikasi | Existing GitHub client tests + webhook invalid PR/identity tests PASS |
| REQ-04.16 (PDF73) | GitHub failure tidak memengaruhi CSV/lokal | Full regression PASS |
| REQ-NF-02 (PDF93) | Target95% rekomendasi≤3s pada5000 issues | PARTIAL / belum accepted: F2 HTTP warm median0.0490s, incremental0.0622s, cold10.4926s;5samples/phase bukan p95. Warm web0.2215s/extension0.2232s; cold tetap gagal |
| REQ-NF-04 (PDF93) | CSV validation≤30s/import≤60s target | Satu run insertion5000≈1,595s; bukan bukti semua workload/percentile |
| REQ-NF-05 (PDF93) | Deteksi≤60s per issue pada5000 | PARTIAL: F2 worker median cold10.6885/warm0.4338/incremental0.4533s; cold menggunakan timeout QA120s, production10s tetap gap. Backlog5000 belum diuji |
| REQ-NF-06 (PDF93) | Target20 pengguna aktif | Empat actual concurrent warm HTTP requests PASS; cold competitor503 dalam2s dan recovery PASS. Bukan bukti20users/sustained load |
| REQ-NF-08,09,12,14 (PDF94) | Atomic rollback, retry idempotent, count/audit konsisten | SQLite tests + sembilan real MySQL checks PASS |
| REQ-NF-10 (PDF94) | Removal tidak menghapus votes/history | Existing extension membership tests PASS |
| REQ-NF-15 (PDF94) | Web session/CSRF tetap; extension Bearer dan webhook HMAC sebagai auth surface baru | Scope/security amendment perlu Joshua; tidak ada global CSRF exemption |
| REQ-NF-16,17,20–24 (PDF94–95) | Server role/project checks, fixed origin, safe rendering/errors, rate limiting | PHP/Node tests PASS; TLS/proxy/deployment QA pending |

## Perubahan scope yang perlu disahkan

1. Browser extension adalah client Reporter baru. UX private memakai extension-owned
   window karena DOM GitHub tidak menjamin confidentiality. Dukungan Markdown
   terverifikasi saja; structured templates memerlukan tambahan validasi.
2. `issues.opened` memicu import otomatis satu arah GitHub → DupIssue tanpa
   confirmation per issue. Manual confirmation tetap wajib di jalur manual.
3. Attribution otomatis menggunakan task delivery + activity log actor sistem.
   Tidak ada pemalsuan user Maintainer ataupun human import batch.
4. GitHub App menggantikan PAT aktif. Historical PAT metadata/schema tidak dihapus;
   SRS dan user-story PAT lama harus ditandai superseded setelah persetujuan tim.
5. Stable repository ID, secure credential sessions, dan delivery state merupakan
   additive schema. Migration/backfill/retention perlu review operasional.

Reverse synchronization, penutupan GitHub otomatis, penandaan duplicate otomatis,
dan status push ke GitHub tetap di luar scope. Human duplicate review dipertahankan.

## Approval pending

- **Elsa:** review shared VoteService extraction, original redirect/session messages,
  authorization/lock order, API/web interoperability, dan hasil concurrency nyata.
- **Joshua (Technical Lead):** review shared routing, service-to-service webhook
  boundary, perubahan SRS04.8/04.15/NF15/NF19, GitHub App retirement dan deployment.
  Tambahan F2: shared NLP cache/model revision pinning, batas memory/TTL, overload503,
  tolerance float32, project/content isolation, cold-timeout gap dan production
  prewarming lifecycle sebelum merge/deployment. User approval eksperimen lokal
  tidak menggantikan approval Technical Lead.
- **Pengguna:** actual testing repository `valentinokevin/dupissue-testing` dan App
  `dupissue-kel4-dev-valentinokevin`, termasuk issue creation, credential use, webhook
  settings/delivery; commit/push/PR/merge/deploy terpisah dan belum diizinkan.

Tidak ada pesan eksternal dikirim, tidak ada approval Elsa/Joshua yang diasumsikan.
