# F2 — NLP performance optimization (local experiment)

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

### Latest performance evidence

| Metric | Result |
| --- | ---: |
| CSV imported issues | 5000 |
| Database jobs created | 5000 |
| NLP HTTP requests during import | 0 |
| CSV import duration | 1.625s |
| CSV import PHP peak memory | 72.50 MB |
| Large-project preparation | 10.9271s |
| Web warm median | 0.2037s |
| Extension warm median | 0.2022s |
| Web warm maximum | 0.2103s |
| Extension warm maximum | 0.3638s |

All ten observed warm requests completed within 3 seconds.
Preparation used an extended QA-only timeout.

| Project issues | First queue rerun | Hardened queue rerun |
| --- | ---: | ---: |
| 10 | 0.0504s | 0.0615s |
| 100 | 0.2883s | 0.2309s |
| 1000 | 2.4088s | 2.2771s |
| 5000 | 11.4244s | 2.7259s |

Each sample processed one detection job and persisted three candidates.
The hardened test verifies actual database dispatch and worker execution.
Cache conditions were not controlled identically between reruns.
The faster sample does not prove a new optimization or cold-cache SLA.
The production NLP model, scoring and timeout remain unchanged.

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

Date: 2026-11-24. Branch `fix/github-app-repository-management`, HEAD
`b62f83bef6746771d8c30745308162d1a0a52607`. **Pending Technical Lead review.**
User authorized local experimental implementation/testing, not staging, commit,
external integration, merge or deployment. Previous implementation is preserved.

## Decision and architecture audit

Before: Laravel sends the current same-project issue texts; FastAPI encodes query
and every candidate on every call, computes one vectorized cosine matrix, sorts,
and returns all scores. Laravel retains its existing parent resolution, Top 3,
CSV 0.75 threshold, tie ordering, persistence and human review.

Actual profiling of five baseline 5,000-issue HTTP requests: mean model encoding
10.8574s versus mean cosine computation 0.000913s. Encoding accounts for almost all
request time. Encoding was already batched (default 32); cosine already vectorized.
Replacing cosine or lowering model quality would not address the measured cause.

After: an in-process bounded LRU stores only independent detached embeddings and
fixed-size hashed keys. Only missing/changed texts are encoded together, using the
same model, normalization, precision and batch size. Cosine/ranking code is unchanged.
No new API fields, production endpoints, database tables, distributed cache,
background production scheduler, ANN index, quantization or timeout increase.

Audited shared callers (unchanged):
- [NlpClient](../backend/app/Services/NlpClient.php): JSON contract validation;
  default 10s timeout, 5s connection timeout.
- [IssueRecommendationService](../backend/app/Services/IssueRecommendationService.php):
  reads all current project issues, checks returned IDs, resolves root parents,
  keeps signed cosine and Top 3 distinct targets. Both web and extension use it.
- [DetectIssueCandidatesJob](../backend/app/Jobs/DetectIssueCandidatesJob.php):
  current project read, threshold, CSV Top 3/other Top 10, idempotent transaction,
  human decisions, retryable 503/timeouts. Its per-candidate DB lookups remain a
  secondary bottleneck: 5,021 total queries per 5,000-issue worker sample.
- [CSV import](../backend/app/Services/CsvIssueImportService.php) and
  [GitHub import](../backend/app/Services/GithubIssueImportService.php): enqueue
  one detection per imported issue after commit; no repeated NLP inside transaction.
- [Queue config](../backend/config/queue.php): database retry_after 90s.
  [Compose](../docker-compose.yaml): worker timeout 60s; one NLP service process;
  no public NLP port. [NLP Dockerfile](../nlp-service/Dockerfile) copies the new
  module automatically. More Uvicorn processes would duplicate model/cache RAM.
  Docker execution was unavailable; source review is not image/deployment PASS.

## Cache correctness, security and lifecycle

Identity hash includes model name + pinned revision + text format version, project
ID and issue ID (existing string/int identity semantics). Separate content hash
includes exact validated title and description, not a lossy text normalization.
Pinned model revision is the same original validated snapshot:
`e8f8c211226b894fcb81acc59f3b34ba3efd5f42`.

Limits: 10,000 entries, 32 MiB accounted storage (actual tensor bytes + conservative
1 KiB metadata allowance per entry), absolute TTL 900s. Entry cap bounds Python
object count independently of byte accounting. This is not a whole-process RSS cap.
Vectors are cloned individually so one cached row cannot retain the entire batch.
No raw text, raw IDs or embeddings are written to disk. Diagnostic counters are
in-process only; `/qa/*` routes exist solely in the isolated benchmark host.

| Event | Behavior |
| --- | --- |
| Content edit | Hash mismatch; encode new content and replace previous identity entry |
| New CSV/GitHub issue | New identity miss; subsequent requests reuse its vector |
| Delete / candidate omitted | Not in request, therefore cannot appear in output; old vector expires/evicts |
| Repository replacement | Backend authorization/mapping and current candidate list remain authoritative; retained historical issues preserve existing semantics |
| Model version | Different key namespace; normal deployment restart also starts empty |
| Service restart | Cold cache; nothing persistent to restore |
| Several queue workers | Shared cache only if they reach the same NLP process |
| Concurrent same content | Single-flight model/cache fill; no duplicate encoding |
| Concurrent old/new content | Each request scores its own content snapshot; subsequent lookup checks hash again |
| Capacity/TTL | LRU eviction; expired entries removed on next cache request (idle RAM is not proactively purged) |
| Inference failure | No partial batch insertion; lock releases; generic 503, existing queue retry |
| Busy inference | Wait at most 2s, then generic 503; no indefinite thread blocking |

The cache is neither an issue index nor an authorization source. Only supplied,
same-project, non-self candidates are scored. Cached IDs cannot be enumerated to
recover deleted/private issues. Backend role, membership, repository and ownership
checks were not weakened. The NLP service still belongs on the private network.
It does not independently authenticate caller-supplied project IDs.

## Measurement method

Original model loaded offline from the existing shared Hugging Face snapshot;
no model/dependency downloads. ARM64 Mac, 8 GiB RAM, Python3.12.6,
sentence-transformers6.1.0, torch2.15.0, CPU two threads, unchanged float precision.
Each size uses the exact previous synthetic checkout/account-number dataset:
N project issues = one query + N−1 candidates. This is not all possible title/body
lengths or a production distribution. Model startup is excluded from request time;
the first cold sample still includes lazy inference initialization.

Five runs per size/phase. Baseline has no embedding cache. Cold clears only the
ephemeral experiment embedding cache; warm reuses identical inputs; incremental
changes one candidate and the query each time (two misses). HTTP measurements
include JSON transfer/validation/serialization, exclude the separate stats probe.
Server RSS sampled every50ms; misses short peaks and includes model/allocator/shared
pages, not just cache. Process high-water RSS is not substituted for these samples.
Queue timings measure a real Laravel database worker using SQLite :memory: and
real FastAPI, with five completed jobs per size/phase. Benchmark-only timeout120s
and worker150s let cold measurements complete; production remains10s/60s.

An initial queue harness attempt reset a stale Eloquent object without dirtying
its PENDING field, causing repeated idempotent no-op jobs. Those measurements are
excluded. The corrected harness explicitly updates DB status and asserts PENDING,
query count and expected encoded-text count for every delivery.

## HTTP results (seconds, median of five)

| Issues | Before uncached | After cold | After warm | After incremental |
| ---: | ---: | ---: | ---: | ---: |
|10|0.03035|0.02485|0.00227|0.01568|
|100|0.21729|0.23455|0.00548|0.02926|
|1,000|2.25421|2.09018|0.01293|0.02244|
|5,000|10.61953|10.49264|0.04903|0.06224|

5,000 ranges: baseline10.50915–11.59675s; cold10.41905–10.55840s;
warm0.04610–0.05559s; incremental0.05527–0.20502s.
Observed sequential throughput: baseline5.51, cold5.72, warm1197.82,
incremental668.76 HTTP requests/minute, computed from these five request durations.
This is not sustained capacity, backend rate-limit bypass, or a 20-user load test.
No percentile/statistical-confidence claim is made from five samples.

## Memory and hit/miss evidence

| Issues | Baseline sampled RSS peak MiB | Optimized cold peak MiB | Vector bytes | Accounted cache bytes |
| ---: | ---: | ---: | ---: | ---: |
|10|217.92|239.27|15,360|25,600|
|100|287.53|294.84|153,600|256,000|
|1,000|307.36|305.61|1,536,000|2,560,000|
|5,000|339.38|533.63|7,680,000|12,800,000|

5,000 warm sampled RSS peak300.11MiB; incremental479.81MiB. Cross-process RSS
deltas cannot all be attributed to cache: transient tensors/allocator residency and
macOS memory accounting also vary. Cache retained vector payload is7.324MiB,
accounted total12.207MiB for5,000 entries. Peak memory increased in the cold sample;
do not claim the optimization is memory-neutral.

Per 5,000 request: cold0hits/5,000misses; warm5,000hits/0misses;
incremental4,998hits/2misses. After five incremental requests entries stay5,000,
with10replacements. No eviction at this working set. Unit tests force entry/byte
eviction, expired access, disabled retention and detached storage ownership.

## Actual database worker results (seconds, median of five)

| Project issues | Before uncached | After cold | After warm | After incremental |
| ---: | ---: | ---: | ---: | ---: |
|10|0.02649|0.02707|0.00367|0.01665|
|100|0.23283|0.21568|0.01157|0.02954|
|1,000|2.14656|2.14371|0.09272|0.10582|
|5,000|10.87972|10.68846|0.43380|0.45327|

Completed20baseline and60optimized worker deliveries across the bounded matrix,
with current candidates/CSV threshold/Top3 persisted and empty jobs/failed_jobs
asserted each time. These deliberately repeat the same synthetic issue after
resetting its status; they are not80distinct imported issues or a5,000job backlog.
Benchmark PHPUnit: baseline1test/180assertions; optimized1test/540assertions.

5,000 warm observed throughput121.00jobs/min, incremental131.79jobs/min versus
baseline5.35jobs/min (five samples only). PHP peak88.5MiB for both. Median DB query
time roughly57–59ms, with5,021queries per typical delivery; most remaining warm
time includes ORM/PHP/serialization and worker overhead. No PHP business logic
was refactored: the minimal NLP cache already materially improves this workload.
Future batched candidate validation needs its own concurrency/security review.

## Parity, concurrency and isolated precomputation

All four sizes cold/warm matched baseline scores exactly in the captured samples;
Top 3 IDs identical. Additional actual-model multilingual/long-text/tie fixtures
tested cold, warm, changed draft, changed candidate, model namespace and project:
maximum absolute cosine error1.7881393432617188e−7, full ordering and >=0.75
decisions identical. Allowed absolute tolerance1e−6 reflects float32 batch-padding
roundoff; no scores rounded or altered to force parity. Near-identical scores at
a threshold/tie can still differ within floating-point tolerance across batch shapes.

Actual HTTP concurrency: one5,000cold request completed10.697s; a competing request
received503 after2.028s; four subsequent simultaneous warm requests all returned200,
identical Top 3, in0.135–0.173s. This proves bounded overload/recovery for this case,
not fairness or availability under sustained load. A cold project can block warm
projects behind the single inference lock; production scheduling remains a review gate.

Isolated prewarming evaluation: three trials,20 sequential chunks of at most256
candidates through the existing API, then one full5,000request. Preparation cost
11.049/11.030/12.217s; first full request0.05186/0.04950/0.22908s. Top3 unchanged;
maximum score error1.7881393432617188e−7. Total5,000texts encoded per trial,
not5,000independent detection jobs. No production prewarm endpoint/job was added.

A future lifecycle could prewarm bounded project batches after commit/restart and
on content changes, using durable deduplication, current content/repository checks,
single-project scheduling, cancellation and readiness monitoring. It must handle
TTL/eviction and avoid competing with interactive requests. Persisting embeddings
would add privacy/retention/version-migration obligations. These are explicit
follow-up design/review requirements, not silently installed background behavior.

## CSV 5,000-row implications and SRS decision

The first detection can populate5,000vectors; later jobs reuse them until content
change/TTL/eviction. The importer still enqueues5,000jobs. Each job still transfers
the full candidate payload and performs comparisons; total work is not reduced to
one job. High-similarity fixtures still incur5,021DB queries/job in Laravel. At
large backlogs, TTL expiry and other projects competing for10,000entries can
cause additional cold fills. No5,000-job completion or sustained backlog claim.

**REQ-NF-02 remains PARTIAL/NOT ACCEPTED end-to-end.** Warm and incremental observed
samples meet3s, but cold samples fail3s and exceed the unchanged default10s timeout.
No95th-percentile SLA or20-user acceptance has been established. A restart/import/
eviction may still cause initial user failures. Prewarming experiment demonstrates
a viable preparation step, not an operational guarantee. REQ-NF-05 remains partial
for cold/default configuration and full backlog capacity.

## Reproduction and evidence

QA scripts: [profiler](../backend/tests/qa/profile-nlp.py),
[worker benchmark](../backend/tests/qa/NlpPerformanceTest.php),
[live parity](../backend/tests/qa/live-cache-parity.py),
[web/extension integration](../backend/tests/qa/LiveRecommendationTest.php).
Use the existing `.qa/nlp-venv/bin/python` with `HF_HUB_OFFLINE=1`,
`TRANSFORMERS_OFFLINE=1`, `HF_HUB_DISABLE_IMPLICIT_TOKEN=1`,
`HF_HUB_DISABLE_TELEMETRY=1`, `OMP_NUM_THREADS=2`, `MKL_NUM_THREADS=2`,
`TOKENIZERS_PARALLELISM=false`. No credentials are required.

1. Baseline source is preserved in ignored `.qa/f2-baseline-main.py` (the original
   `nlp-service/main.py` from this HEAD). Do not overwrite it with optimized code.
   SHA256 verified against HEAD:
   `0a5446c2c2285da3d82b1d4ec1486f55fea8f8e69fb8bcbcfc0fed8f9094a58a`.
2. Run `backend/tests/qa/profile-nlp.py --serve baseline` or `--serve optimized`.
   Only one at a time, loopback18766. Stop only the process you started.
3. Run the same script with `--benchmark baseline` or `--benchmark optimized`.
   For optimized host also run `--precompute` and `--concurrency` sequentially.
4. From backend run PHP8.3 `vendor/bin/phpunit tests/qa/NlpPerformanceTest.php`
   with `QA_NLP_MODE=baseline` or `optimized` matching the active host.
5. Run live-cache-parity.py offline. For normal integration use optimized
   `main:app` on loopback18765, then `NLP_LIVE_TEST_URL=http://127.0.0.1:18765`
   for LiveRecommendationTest and the existing live queue opt-in test.

Raw measurements remain ignored locally: `.qa/f2-baseline.json`,
`.qa/f2-optimized.json`, `.qa/f2-scores-{size}.json`, `.qa/f2-queue-*.json`,
`.qa/f2-precompute.json`, `.qa/f2-concurrency.json`, `.qa/f2-live-parity.json`,
and `.qa/f2-web-extension.json`. No synthetic account secrets in these results.

## Technical Lead checkpoint

Joshua must review shared NLP/model pinning, cache bounds/TTL, overload policy,
privacy/lifecycle assumptions, floating-point tolerance, prewarming plan and cold
timeout/SRS gap before merge/deployment. Human duplicate review, parent handling,
CSV threshold and shared website/extension behavior remain unchanged.
Stop before actual GitHub App integration; external approval is still required.

## Final regression and changed files

| Validation | Actual result after optimization |
| --- | --- |
| NLP unit/endpoint/cache/concurrency |29/29 PASS (12 existing +17 cache regressions), mocked model|
| Full PHPUnit |610tests,608passed,5,065assertions,2existing skips,0failures/errors;14.950s,84.5MiB|
| JavaScript |38/38 PASS (17web +21extension)|
| Existing live submit→database worker→FastAPI |1test/21assertions PASS, original model|
| LiveRecommendationTest |3tests/56assertions PASS, original model; existing2tests retained plus large-project regression|
| Repeated worker performance |Baseline1/180; optimized1/540 PASS;20+60completed deliveries|
| Actual-model extra parity |6phases PASS, full ranking/threshold decisions unchanged|
| Existing `/similarity` actual-model smoke |Identical text cosine0.9999998807907104; within1e−6 of1|
| Actual HTTP contention/recovery |Cold200, competing503 within bounded wait, subsequent4warm200|
| Syntax/editor diagnostics/Pint/diff whitespace |PASS locally|
| Chrome for Testing7flows / MySQL9checks |Prior accepted evidence retained, not rerun; browser/vote/schema unchanged|
| Docker / remote CI / actual GitHub |Not executed; no new PASS claim|

New live regression includes a project with5,000existing candidates +a draft
(5,001texts, distinct from benchmark5,000total issue texts). Preparation11.7339s
with QA120s timeout; five web/extension pairs then use unchanged10s timeout.
Web range0.2110–0.2422s, median0.2215s; extension0.2101–0.8405s,
median0.2232s. Responses match exactly across both clients and initial response;
editing the previous top candidate and deleting another excludes stale results.
These are Laravel HTTP-kernel integration timings, not Chrome/UI/network-to-user
latencies. Live suite total32.040s, PHP process peak100.5MiB.

Changed during F2:
- [main.py](../nlp-service/main.py): pinned existing model snapshot, shared inference
  gate and cache invocation. Public endpoint schemas unchanged.
- [embedding_cache.py](../nlp-service/embedding_cache.py): new bounded cache.
- [test_candidates.py](../nlp-service/tests/test_candidates.py): mocked model now
  returns tensors, retaining original endpoint expectations.
- [test_embedding_cache.py](../nlp-service/tests/test_embedding_cache.py):17new tests.
- [README](../nlp-service/README.md): lifecycle/limits/overload and reproduction link.
- [profile-nlp.py](../backend/tests/qa/profile-nlp.py),
  [live-cache-parity.py](../backend/tests/qa/live-cache-parity.py),
  [NlpPerformanceTest.php](../backend/tests/qa/NlpPerformanceTest.php):new isolated QA harnesses.
- [LiveRecommendationTest.php](../backend/tests/qa/LiveRecommendationTest.php):
  large-project live parity/content invalidation regression.
- This report, [FINAL_QA_REPORT](FINAL_QA_REPORT.md),
  [DEVELOPMENT_PROGRESS](DEVELOPMENT_PROGRESS.md),
  [SCOPE_TRACEABILITY](SCOPE_TRACEABILITY.md), and
  [EXTENSION_SYNC_OPERATIONS](EXTENSION_SYNC_OPERATIONS.md):actual evidence/checkpoints.

Final worktree:19modified,73untracked,0staged; same branch/HEAD. Six new files in
F2, three additional tracked files modified (all under nlp-service); no existing
files deleted. No PHP production logic, migrations, queue/Compose configuration,
application dependencies, browser source or production timeout changed during F2.
Disk8.0GiB free at start,6.9GiB at final verification; no cleanup/model download.
Benchmark diagnostic server18766 stopped; regular optimized offline FastAPI18765
remains loopback-only for local use. No other environment/database was modified.
