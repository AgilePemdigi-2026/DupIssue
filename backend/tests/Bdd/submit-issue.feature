# Specification only: Behat/Mink, runner configuration and step definitions
# are not installed. See docs/bdd/SUBMIT_ISSUE_BDD_NOTES.md at repository root.
@needs-steps @project-integration
Feature: Submit Issue Sprint 1
  As a Reporter who is an active member of a project
  I want to submit a bug report
  So that the report is stored and processed asynchronously for duplicate candidates

  @nlp-integration
  Scenario: Reporter anggota aktif berhasil submit issue
    Given the real DetectIssueCandidatesJob is integrated
    And the NLP worker is paused while the initial response is inspected
    And I am logged in as a Reporter
    And I am an active member of project "Toko Online Baju"
    When I submit a valid title and description in that project
    Then I should see "Issue berhasil dibuat"
    And I should see "OPEN" in the issue status
    And I should see "PENDING" in the detection status
    And NLP detection should be queued after the issue is committed

  Scenario: Reporter yang bukan anggota ditolak
    Given I am logged in as a Reporter
    And I am not an active member of the target project
    When I try to submit an issue to that project
    Then I should see "Akses tidak tersedia"
    And no issue should be stored

  Scenario: Panjang judul atau deskripsi tidak valid
    Given I am logged in as an active Reporter in a project
    When I submit an invalid issue title or description
    Then I should see field validation errors
    And no issue should be stored

  Scenario Outline: Membership nonaktif tidak boleh submit
    Given I am logged in as a Reporter
    And my membership in the target project is inactive
    When I send a <method> request to the issue form in that project
    Then I should receive a forbidden response
    And no issue should be stored

    Examples:
      | method |
      | GET    |
      | POST   |

  Scenario Outline: Maintainer tidak boleh submit meskipun memiliki akses project
    Given I am logged in as the Maintainer who owns the target project
    When I send a <method> request to the issue form in that project
    Then I should receive a forbidden response
    And no issue should be stored

    Examples:
      | method |
      | GET    |
      | POST   |

  Scenario Outline: Guest harus login
    Given I am not logged in
    When I send a <method> request to the issue form in a project
    Then I should be redirected to the login page
    And no issue should be stored

    Examples:
      | method |
      | GET    |
      | POST   |

  Scenario Outline: Batas panjang input
    Given I am logged in as an active Reporter in a project
    When I submit a title with <title_length> characters and a description with <description_length> characters
    Then the submission should be <result>

    Examples:
      | title_length | description_length | result   |
      | 5            | 10                 | accepted |
      | 200          | 10000              | accepted |
      | 4            | 10                 | rejected |
      | 201          | 10                 | rejected |
      | 5            | 9                  | rejected |
      | 5            | 10001              | rejected |

  @nlp-integration
  Scenario: Field sistem dari request diabaikan
    Given the real DetectIssueCandidatesJob is integrated
    And I am logged in as an active Reporter in a project
    When I submit valid issue content with forged author_id, project_id, status, source_type, vote_count, detection_status and duplicate_of
    Then the issue author should be the authenticated user
    And the issue project should be the authorized route project
    And the issue should have FORM source, OPEN status, PENDING detection, zero votes and no duplicate parent

  Scenario: Issue project lain tidak bisa dibaca melalui manipulasi URL
    Given I am logged in as an active Reporter in a project
    And an issue belongs to another project
    When I request that issue under my project URL
    Then I should receive a not found response
    When I request that issue under its actual project URL without membership
    Then I should receive a forbidden response

  @nlp-integration
  Scenario: Job hanya masuk Database Queue setelah commit
    Given the real DetectIssueCandidatesJob is integrated
    And I am logged in as an active Reporter in a project
    When I submit a valid issue inside an open transaction
    Then no NLP job should be queued before the outer transaction commits
    When the outer transaction commits
    Then one NLP job should be stored in the database queue named "nlp"
    And the submit request should not call FastAPI

  @nlp-integration
  Scenario: Rollback tidak menyisakan issue atau Job NLP
    Given the real DetectIssueCandidatesJob is integrated
    And I submit a valid issue inside an open transaction
    When the outer transaction rolls back
    Then no issue should be stored
    And no NLP job should be queued
    When I submit another valid issue and commit it
    Then only the new issue and one NLP job should be stored

  @nlp-integration
  Scenario: Kegagalan antrean tidak menghapus issue
    Given the real DetectIssueCandidatesJob is integrated
    And I am logged in as an active Reporter in a project
    And the database queue cannot accept jobs
    When I submit a valid title and description in that project
    Then the committed issue should remain stored with OPEN status
    And its detection status should be FAILED

  @nlp-integration
  Scenario: Kegagalan NLP di worker tidak menghapus issue
    Given the real DetectIssueCandidatesJob and NLP Client are integrated
    And a submitted issue is committed and queued for detection
    When the worker exhausts its attempts because FastAPI fails
    Then the issue should remain stored with OPEN status
    And its detection status should be FAILED

  Scenario: Job NLP belum terintegrasi
    Given the real DetectIssueCandidatesJob is not integrated
    And I am logged in as an active Reporter in a project
    When I submit a valid title and description in that project
    Then the committed issue should remain stored with OPEN status
    And its detection status should be FAILED
    And the submit request should not call FastAPI

  Scenario: Akses detail dicabut setelah membership dinonaktifkan
    Given I authored an issue as an active Reporter in a project
    And my membership in that project is now inactive
    When I request my issue detail
    Then I should receive a forbidden response

  @nlp-integration
  Scenario: Retry sukses dan pengiriman ulang Job tidak menggandakan kandidat
    Given the real DetectIssueCandidatesJob and DuplicateCandidate are integrated
    And a submitted issue has a comparison issue in the same project
    When FastAPI returns 503 on the first worker attempt
    Then the issue should remain OPEN with detection status PROCESSING
    When FastAPI succeeds on the next attempt after backoff
    Then its detection status should be COMPLETED
    When the same completed issue is processed again
    Then candidate rows should not be duplicated
    And no additional FastAPI request should be sent
