@feature12 @sprint3 @req06 @behat-mink
Feature: Maintainer mengelola status issue (REQ-06.1 sampai REQ-06.11)
  Sebagai Maintainer pemilik project
  Saya ingin mengelola status issue dengan konfirmasi dan audit
  Agar duplikat tertaut ditutup konsisten dan perubahan lama tidak menimpa data terbaru

  @req06_2 @req06_5 @req06_7 @req06_11
  Scenario: Resolving parent auto-closes linked duplicate and records two changes
    Given I am logged in as "maintainer.f12@dupissue.test" with role "MAINTAINER"
    And a Sprint3 Feature12 project and linked duplicate exist
    When I view Sprint3 Feature12 parent detail
    Then the Sprint3 Feature12 form offers "RESOLVED"
    When I preview Sprint3 Feature12 status "RESOLVED"
    Then the Sprint3 Feature12 confirmation shows 1 duplicate
    When I confirm Sprint3 Feature12 status
    Then the Sprint3 Feature12 parent status is "RESOLVED"
    And the Sprint3 Feature12 duplicate status is "CLOSED"
    And Sprint3 Feature12 created 2 status audit entries

  @req06_4
  Scenario: Choosing the current status is a no-op without audit
    Given I am logged in as "maintainer.f12@dupissue.test" with role "MAINTAINER"
    And a Sprint3 Feature12 project and linked duplicate exist
    When I view Sprint3 Feature12 parent detail
    And I preview Sprint3 Feature12 status "IN_PROGRESS"
    Then Sprint3 Feature12 shows "Tidak ada perubahan"
    And the Sprint3 Feature12 parent status is "IN_PROGRESS"
    And Sprint3 Feature12 created 0 status audit entries

  @req06_8
  Scenario: Reopening a resolved parent never reopens a closed duplicate
    Given I am logged in as "maintainer.f12@dupissue.test" with role "MAINTAINER"
    And a Sprint3 Feature12 project and linked duplicate exist
    And the Sprint3 Feature12 parent is resolved and its duplicate is closed
    When I view Sprint3 Feature12 parent detail
    Then the Sprint3 Feature12 form offers "IN_PROGRESS"
    When I preview Sprint3 Feature12 status "IN_PROGRESS"
    Then Sprint3 Feature12 shows "Duplikat lama tetap CLOSED"
    When I confirm Sprint3 Feature12 status
    Then the Sprint3 Feature12 parent status is "IN_PROGRESS"
    And the Sprint3 Feature12 duplicate status is "CLOSED"
    And Sprint3 Feature12 created 1 status audit entries

  @req06_6
  Scenario: Stale confirmation is rejected server-side
    Given I am logged in as "maintainer.f12@dupissue.test" with role "MAINTAINER"
    And a Sprint3 Feature12 project and linked duplicate exist
    When I view Sprint3 Feature12 parent detail
    And I preview Sprint3 Feature12 status "RESOLVED"
    And an external Sprint3 Feature12 status change occurs
    And I confirm Sprint3 Feature12 status
    Then Sprint3 Feature12 returns HTTP status 409
    And the Sprint3 Feature12 parent status is "OPEN"
    And the Sprint3 Feature12 duplicate status is "DUPLICATE"
    And Sprint3 Feature12 created 0 status audit entries

  @req06_1 @req06_2
  Scenario: Reporter can view issue but cannot see a status editing form
    Given I am logged in as "reporter.f12@dupissue.test" with role "REPORTER"
    And a Sprint3 Feature12 project and linked duplicate exist
    When I view Sprint3 Feature12 parent detail
    Then Sprint3 Feature12 does not offer a status form
    And the Sprint3 Feature12 parent status is "IN_PROGRESS"
