# language: en
# DRAFT - Acceptance scenarios for Sprint 1 NLP detection AFTER Submit Issue.
# SRS basis: REQ-01.1, REQ-01.2, REQ-01.11, REQ-01.12, REQ-01.18,
# REQ-05.4, REQ-NF-03, REQ-NF-09, BR-03, BR-05, BR-06, BR-08, BR-15.
# The main SRS User Story Scenario for Submit Issue (Table 4.7) also covers
# pre-submit recommendations, cancelling submission, and voting. This file
# supplements that scenario; it does NOT replace or modify it.
# The minimum candidate similarity threshold remains TBD-06 in the SRS.
# Do not assume a numerical threshold or that all NLP scores are persisted.
# Step definitions for Laravel/MySQL/Database Queue do not exist yet.
# This file is NOT executable with Behat/Mink until those steps are implemented.

@draft @sprint1 @nlp @integration @not_executable
Feature: Deteksi kandidat duplikat setelah Submit Issue
  In order to preserve reports and support human review of similar issues
  As a Reporter
  I want my valid issue to be saved before asynchronous similarity detection
  so that a Maintainer can review candidates without automatic duplicate decisions.

  Background:
    Given I am logged in as "valentino@dupissue.test" with role "Reporter"
    And I am an active member of project "Toko Online Baju" with id "1"
    And I am on "/projects/1/submit"
    And I fill in "judul" with "Tombol Bayar tidak merespon"
    And I fill in "deskripsi" with "Setelah klik Bayar Sekarang halaman tetap berada di checkout"

  # REQ-01.1, REQ-01.2, REQ-01.11, REQ-01.12, REQ-01.18, REQ-05.4, BR-08.
  Scenario: Mendeteksi issue serupa dalam project yang sama
    Given I have an existing issue "10" in project "1" with a similar payment button problem
    And I have an NLP test fixture with a same-project candidate that meets the agreed similarity threshold
    When I press "Kirim Issue"
    Then I should see "Issue berhasil dibuat"
    And I should see "Open" in the "status" element
    And I should see "Pending" in the "detection_status" element
    And I verify that my active membership in project "1" is checked again before saving
    And I verify that the new issue belongs to project "1" and has source_type "FORM"
    And I verify that the new issue has status "OPEN" and detection_status "PENDING"
    And I verify that the new issue has vote_count 0 and author_id set to my Reporter account
    And I verify that the issue creation is recorded in the project activity log
    And I verify that similarity detection for the saved issue is scheduled through Laravel Database Queue
    When I wait for the queued detection job to complete successfully
    Then I verify that detection transitions through "PROCESSING" to "COMPLETED"
    And I verify that the candidate search is limited to issues in project "1" other than the new issue
    And I verify that any saved candidate satisfies the agreed similarity threshold and retains its similarity score
    And I verify that the new issue is not automatically assigned status "DUPLICATE"
    And I verify that duplicate confirmation remains a Maintainer decision

  # REQ-01.11, REQ-01.12, REQ-05.4, BR-05, BR-08.
  Scenario: Mengabaikan issue dari project berbeda
    Given I have an existing issue "20" in project "2" with the same problem as my new issue
    And I have no existing comparison issue in project "1"
    When I press "Kirim Issue"
    Then I should see "Issue berhasil dibuat"
    And I verify that the issue is saved in project "1" with status "OPEN" and detection_status "PENDING"
    And I verify that similarity detection for the saved issue is scheduled through Laravel Database Queue
    When I wait for the queued detection job to complete successfully
    Then I verify that issue "20" from project "2" is never included in the candidate results
    And I verify that no duplicate candidate record is created for the saved issue
    And I verify that detection_status becomes "COMPLETED" after successful processing without candidates
    And I verify that the saved issue remains available and is not automatically marked "DUPLICATE"

  # REQ-01.12, REQ-NF-09, BR-15; technical failure is simulated after saving.
  Scenario: Menangani kegagalan deteksi NLP setelah issue disimpan
    Given I have an existing comparison issue "10" in project "1"
    And I have a simulated NLP processing failure after the new issue has been saved
    When I press "Kirim Issue"
    Then I should see "Issue berhasil dibuat"
    And I should see "Open" in the "status" element
    And I verify that the issue is saved in MySQL with source_type "FORM" and detection_status "PENDING"
    And I verify that the submitted issue remains saved even if the detection service fails
    And I verify that a detection job is scheduled through Laravel Database Queue
    When I wait for the detection job to finish unsuccessfully according to the agreed failure handling policy
    Then I verify that detection_status is "FAILED"
    And I verify that the stored issue and its original report data remain unchanged
    And I verify that no fabricated duplicate candidate record is created
    And I verify that the application displays an understandable detection failure message without internal technical details
    And I verify that the issue is not automatically marked "DUPLICATE"
