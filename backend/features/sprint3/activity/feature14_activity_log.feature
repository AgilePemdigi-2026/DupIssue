@feature14 @sprint3 @req07 @behat-mink
Feature: Maintainer melihat Riwayat & Log Aktivitas project

  Sebagai Maintainer pemilik project
  Saya ingin melihat audit trail read-only peristiwa dan perubahan pada project
  Agar dapat memantau riwayat aktivitas dan integritas data secara transparan

  @req07_7 @empty_state
  Scenario: Maintainer accesses empty activity log page
    Given I am logged in as "maintainer.f14@dupissue.test" with role "MAINTAINER"
    And an empty Sprint3 Feature14 project exists
    When I open the Sprint3 Feature14 project page
    And I follow the Sprint3 Feature14 activity log link
    Then Sprint3 Feature14 returns HTTP status 200
    And Sprint3 Feature14 shows "Riwayat & Log Aktivitas"
    And Sprint3 Feature14 shows "Belum ada catatan aktivitas"
    And Sprint3 Feature14 shows "Aktif (Read-Only)"

  @req07_7 @filtering
  Scenario: Maintainer filters activity log by category and keyword
    Given I am logged in as "maintainer.f14@dupissue.test" with role "MAINTAINER"
    And a populated Sprint3 Feature14 project exists with mixed activities
    When I open the Sprint3 Feature14 activity log directly
    Then Sprint3 Feature14 returns HTTP status 200
    And Sprint3 Feature14 shows "CREATE_PROJECT"
    And Sprint3 Feature14 shows "INVITE_REPORTER"
    And Sprint3 Feature14 does not show "Secret Foreign Log"
    When I filter Sprint3 Feature14 by category "MEMBERSHIP"
    Then Sprint3 Feature14 returns HTTP status 200
    And Sprint3 Feature14 shows "INVITE_REPORTER"
    And Sprint3 Feature14 does not show "CREATE_PROJECT"
    When I search Sprint3 Feature14 with keyword "Undangan"
    Then Sprint3 Feature14 returns HTTP status 200
    And Sprint3 Feature14 shows "INVITE_REPORTER"

  @req07_7 @export_csv
  Scenario: Maintainer exports activity log as CSV
    Given I am logged in as "maintainer.f14@dupissue.test" with role "MAINTAINER"
    And a populated Sprint3 Feature14 project exists with mixed activities
    When I export the Sprint3 Feature14 activity log to CSV
    Then Sprint3 Feature14 returns HTTP status 200
    And Sprint3 Feature14 CSV contains "Waktu (WIB)"
    And Sprint3 Feature14 CSV contains "CREATE_PROJECT"
    And Sprint3 Feature14 CSV contains "INVITE_REPORTER"
    And Sprint3 Feature14 CSV does not contain "Secret Foreign Log"

  @authorization
  Scenario: Reporter cannot access the maintainer activity log
    Given I am logged in as "reporter.f14@dupissue.test" with role "REPORTER"
    And an empty Sprint3 Feature14 project exists
    When I open the Sprint3 Feature14 project page
    Then Sprint3 Feature14 returns HTTP status 200
    And Sprint3 Feature14 does not offer an activity log link
    When I open the Sprint3 Feature14 activity log directly
    Then Sprint3 Feature14 returns HTTP status 403

  @authorization
  Scenario: Maintainer of another project cannot access the activity log
    Given I am logged in as "maintainer.f14@dupissue.test" with role "MAINTAINER"
    And a foreign Sprint3 Feature14 project exists
    When I open the foreign Sprint3 Feature14 activity log directly
    Then Sprint3 Feature14 returns HTTP status 403
