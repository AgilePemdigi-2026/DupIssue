# Feature 11 — MySQL 8 Validation Evidence

- **Project:** DupIssue
- **Feature:** Manage Issue Sources (CSV and GitHub)
- **Branch:** `feature/sprint3-issue-sources`
- **Validated commit:** `288c315`
- **Date:** 2026-11-18
- **Database:** MySQL 8.0.46, local isolated test database

## 1. Test Environment

- Database: `dupissue_f11_review_20261118`
- Dedicated database account: `dupissue_f11_review`
- Laravel connection: MySQL, verified through PDO
- Database isolated from development and production
- No production database access or destructive reset commands

## 2. Migration Compatibility

| Test | Result |
|---|---|
| 13 baseline migrations | PASS |
| 3 Feature 11 migrations | PASS |
| Existing 2 users preserved | PASS |
| Existing project preserved | PASS |
| Existing 4 issues preserved | PASS |
| Legacy duplicate relationship preserved | PASS |
| New metadata nullable for legacy issues | PASS |

All 16 migrations were recorded as successfully applied.

## 3. MySQL Constraint Validation

| Test | Result |
|---|---|
| Duplicate CSV source identifier | PASS — MySQL 1062 |
| Duplicate GitHub issue identity | PASS — MySQL 1062 |
| Duplicate GitHub configuration per project | PASS — MySQL 1062 |
| Valid import batch foreign keys | PASS |
| Invalid project foreign key | PASS — MySQL 1452 |
| Invalid actor foreign key | PASS — MySQL 1452 |
| Transaction rollback preserves existing data | PASS |

## 4. CSV Import Service Integration

Used the actual Laravel CSV reader, preview, and import services.

| Test | Result |
|---|---|
| Preview: 2 valid and 2 invalid rows | PASS |
| Import 2 valid issues | PASS |
| Completed import batch and activity log | PASS |
| Reimport rejected without additional writes | PASS |
| Outer transaction rollback | PASS |
| Existing records preserved | PASS |

## 5. GitHub Import Service Integration

Used simulated GitHub API data and the actual Laravel preview and import services. No real PAT or external GitHub request was used.

| Test | Result |
|---|---|
| GitHub configuration encrypted at rest | PASS |
| Preview: 2 valid and 2 invalid issues | PASS |
| Pull request excluded | PASS |
| Invalid preview fingerprint rejected | PASS |
| Import 2 valid GitHub issues | PASS |
| Source URLs and synchronization timestamp | PASS |
| Completed import batch and activity log | PASS |
| Reimport rejected without additional writes | PASS |
| Outer transaction rollback | PASS |
| Existing records preserved | PASS |

## 6. Scope and Limitations

These were manual integration checks using the real MySQL database and Laravel services.

They do not replace automated PHPUnit tests, HTTP end-to-end testing against MySQL, live GitHub API testing, or GitHub Actions verification.

Existing PHPUnit tests use SQLite in-memory by design.

No fixture data was retained after the import integration tests. The original four legacy issues remain in the isolated testing database.

## 7. Conclusion

Feature 11 migrations, database constraints, CSV import services, and GitHub import services passed the documented MySQL 8.0.46 checks.

Final merge readiness remains dependent on GitHub Actions verification, resolution of the conflict with `main`, and any remaining review requirements.
