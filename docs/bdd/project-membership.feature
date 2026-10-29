Feature: Sprint 1 project and membership management
  Maintainers manage their own projects and invite registered Reporters.
  A Reporter gains access only through an active membership.

  Background:
    Given registered Maintainers "Reza" and "Other Maintainer"
    And registered Reporters "Reporter A" and "Reporter B"
    And "Reza" owns project "Alpha"
    And "Other Maintainer" owns project "Beta"

  Scenario: Maintainer creates and updates an owned project
    Given I am logged in as "Reza"
    When I create project "New Project" with description "Sprint 1"
    Then the project is owned by "Reza"
    When I update "New Project" to "Renamed Project"
    Then the project name is "Renamed Project"

  Scenario Outline: Invalid project data is rejected
    Given I am logged in as "Reza"
    When I create a project with name "<name>"
    Then I see a validation error for "name"
    And no project is created

    Examples:
      | name  |
      | ab    |
      | alpha |

  Scenario: Pending invitation does not grant access
    Given I am logged in as "Reza"
    When I invite "Reporter A" to "Alpha" using their registered email
    Then the invitation status is "PENDING"
    When I log in as "Reporter A"
    And I open project "Alpha" directly by URL
    Then access is denied with HTTP 403

  Scenario: Reporter accepts an invitation
    Given "Reporter A" has a pending invitation to "Alpha"
    And I am logged in as "Reporter A"
    When I accept the invitation to "Alpha"
    Then the invitation status is "ACCEPTED"
    And my membership in "Alpha" is active
    And I can open project "Alpha"

  Scenario: Reporter rejects an invitation and can be invited again
    Given "Reporter A" has a pending invitation to "Alpha"
    And I am logged in as "Reporter A"
    When I reject the invitation to "Alpha"
    Then the invitation status is "REJECTED"
    And I have no active membership in "Alpha"
    When I log in as "Reza"
    And I invite "Reporter A" to "Alpha" using their registered email
    Then a new pending invitation exists
    And the rejected invitation remains in history

  Scenario: Maintainer cancels a pending invitation
    Given "Reporter A" has a pending invitation to "Alpha"
    And I am logged in as "Reza"
    When I cancel that invitation
    Then the invitation status is "CANCELED"
    When I log in as "Reporter A"
    And I try to accept that canceled invitation
    Then I see an invitation error without HTTP 500
    And I have no active membership in "Alpha"

  Scenario: Removal preserves history and reinvitation reactivates the same membership
    Given "Reporter A" has accepted an invitation to "Alpha"
    And I am logged in as "Reza"
    When I remove "Reporter A" from "Alpha"
    Then their membership is inactive and has a removal time
    And their account and accepted invitation remain stored
    When I log in as "Reporter A"
    And I open project "Alpha" directly by URL
    Then access is denied with HTTP 403
    When I log in as "Reza"
    And I invite "Reporter A" to "Alpha" using their registered email
    And I log in as "Reporter A"
    And I accept the new invitation to "Alpha"
    Then the original membership row is active with no removal time
    And there is exactly one membership for "Reporter A" in "Alpha"

  Scenario: Reporter cannot create or edit projects
    Given "Reporter A" has an active membership in "Alpha"
    And I am logged in as "Reporter A"
    When I submit a create-project request directly
    Then access is denied with HTTP 403
    When I submit an update request for "Alpha" directly
    Then access is denied with HTTP 403
    And project "Alpha" is unchanged

  Scenario: Invitation and membership IDs cannot be mixed across projects
    Given "Reporter B" has a pending invitation to "Beta"
    And "Reporter B" has an active membership in "Beta"
    And I am logged in as "Reza"
    When I cancel the invitation for "Beta" using the URL for "Alpha"
    Then access is denied with HTTP 403
    When I remove "Reporter B" using the URL for "Alpha"
    Then access is denied with HTTP 403
    And the invitation and membership in "Beta" are unchanged

  Scenario: Another Reporter cannot respond to someone else's invitation
    Given "Reporter A" has a pending invitation to "Alpha"
    And I am logged in as "Reporter B"
    When I submit an accept response for that invitation directly
    Then access is denied with HTTP 403
    And the invitation remains pending

  Scenario: Repeated invitation requests do not create duplicates
    Given I am logged in as "Reza"
    When I invite "Reporter A" to "Alpha" twice
    Then exactly one pending invitation exists for that project and Reporter
    And the second request reports an email validation error

  @concurrency
  Scenario: Concurrent invitation requests do not create duplicates
    Given I am logged in as "Reza"
    When two independent requests invite "Reporter A" to "Alpha" concurrently
    Then exactly one pending invitation exists for that project and Reporter
    And one request succeeds and the other reports an email validation error
