@feature13 @sprint3 @req07 @behat-mink
Feature: Maintainer melihat Dashboard Statistik project

  Sebagai Maintainer pemilik project
  Saya ingin melihat statistik issue dan sumber data
  Agar dapat memantau kondisi dan performa penanganan issue

  @req07_6
  Scenario: Empty dashboard provides access to issue sources
    Given I am logged in as "maintainer.f13@dupissue.test" with role "MAINTAINER"
    And an empty Sprint3 Feature13 project exists
    When I open the Sprint3 Feature13 project page
    And I follow the Sprint3 Feature13 dashboard link
    Then Sprint3 Feature13 returns HTTP status 200
    And Sprint3 Feature13 shows "Belum ada issue pada project ini"
    And Sprint3 Feature13 shows "Belum ada data"
    And Sprint3 Feature13 shows "Impor CSV atau Hubungkan GitHub"
    When I follow the Sprint3 Feature13 source link
    Then Sprint3 Feature13 returns HTTP status 200
    And Sprint3 Feature13 shows "Sumber Issue"

  @req07_5
  Scenario: Dashboard displays project statistics without exposing foreign issues
    Given I am logged in as "maintainer.f13@dupissue.test" with role "MAINTAINER"
    And a populated Sprint3 Feature13 project exists
    When I open the Sprint3 Feature13 dashboard directly
    Then Sprint3 Feature13 returns HTTP status 200
    And Sprint3 Feature13 shows "Dashboard Statistik"
    And Sprint3 Feature13 shows "Distribusi Status"
    And Sprint3 Feature13 shows "Issue dengan Vote Terbanyak"
    And Sprint3 Feature13 shows "Dashboard local parent"
    And Sprint3 Feature13 does not show "Dashboard foreign secret"
    And Sprint3 Feature13 status "IN PROGRESS" has count 1
    And Sprint3 Feature13 status "DUPLICATE" has count 1
    And Sprint3 Feature13 shows "Belum ada data"

  @req07_5 @req06_5 @tbd04
  Scenario: Dashboard reflects actual status change and duplicate auto-close
    Given I am logged in as "maintainer.f13@dupissue.test" with role "MAINTAINER"
    And a populated Sprint3 Feature13 project exists
    When I open the Sprint3 Feature13 dashboard directly
    Then Sprint3 Feature13 status "IN PROGRESS" has count 1
    And Sprint3 Feature13 status "DUPLICATE" has count 1
    When I resolve the Sprint3 Feature13 parent through the status form
    And I open the Sprint3 Feature13 dashboard directly
    Then Sprint3 Feature13 returns HTTP status 200
    And Sprint3 Feature13 status "RESOLVED" has count 1
    And Sprint3 Feature13 status "CLOSED" has count 1
    And Sprint3 Feature13 status "DUPLICATE" has count 0
    And Sprint3 Feature13 has recorded both status audit events
    And Sprint3 Feature13 does not show "Belum ada data"

  @authorization
  Scenario: Reporter cannot access the maintainer dashboard
    Given I am logged in as "reporter.f13@dupissue.test" with role "REPORTER"
    And an empty Sprint3 Feature13 project exists
    When I open the Sprint3 Feature13 project page
    Then Sprint3 Feature13 returns HTTP status 200
    And Sprint3 Feature13 does not offer a dashboard link
    When I open the Sprint3 Feature13 dashboard directly
    Then Sprint3 Feature13 returns HTTP status 403

  @project-isolation
  Scenario: Maintainer cannot access a dashboard owned by another maintainer
    Given I am logged in as "maintainer.f13@dupissue.test" with role "MAINTAINER"
    And a foreign Sprint3 Feature13 project exists
    When I open the Sprint3 Feature13 dashboard directly
    Then Sprint3 Feature13 returns HTTP status 403
