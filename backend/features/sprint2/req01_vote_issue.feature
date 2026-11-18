@vote @sprint2 @req01
Feature: Reporter memberi dan membatalkan Vote pada issue
  Sebagai Reporter yang menjadi anggota aktif suatu project
  Saya ingin Vote dan Unvote tanpa menggandakan jumlah suara
  Agar dukungan atas issue tercatat dan dapat diaudit

  Background:
    Given I am logged in as "reporter.vote@dupissue.test" with role "REPORTER"
    And I am an active member of project "Sprint 2 Voting" with id "1"

  @vote_ui @vote_audit
  Scenario: Reporter melakukan Vote dari detail issue dan aktivitas dicatat
    Given I have an open issue for voting
    When I open the voting issue detail
    Then the Issue Status refresh controls should remain available
    When I submit Vote using the issue form
    Then the voting target should have 1 vote
    And the voting audit should contain exactly one "VOTE_ADDED" event

  @unvote_ui @vote_audit
  Scenario: Reporter membatalkan Vote melalui form dan jumlah suara kembali nol
    Given I have an open issue for voting
    When I open the voting issue detail
    And I submit Vote using the issue form
    And I submit Unvote using the issue form
    Then the voting target should have 0 votes
    And the voting audit should contain exactly one "VOTE_ADDED" event
    And the voting audit should contain exactly one "VOTE_REMOVED" event

  @closed_duplicate
  Scenario: Vote pada child CLOSED tersimpan pada induk sah
    Given I have a CLOSED duplicate child and its original voting issue
    When I open the voting issue detail
    And I submit Vote using the issue form
    Then I should be redirected to the original voting issue
    And the voting target should have 1 vote
    And the duplicate child should have zero votes
    And the voting audit should contain exactly one "VOTE_ADDED" event

  @project_isolation
  Scenario: Child dengan induk lintas project tidak membuka akses Voting
    Given I have a duplicate issue linked to a foreign-project issue for voting
    When I open the voting issue detail
    Then voting controls must be hidden
    And the foreign issue detail must not be disclosed

  @inactive_membership
  Scenario: Reporter yang keanggotaannya tidak aktif tidak dapat mengakses Voting
    Given I have an open issue for voting
    And I am not an active member of project "Sprint 2 Voting"
    When I am on "/projects/1/issues"
    Then I should be denied project access
