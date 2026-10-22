Feature: Login and Role-Based Access Control
  As a DupIssue user
  I want to log in securely
  So that I can access features according to my role

  Scenario: Reporter logs in successfully
    Given a registered Reporter account exists
    When the Reporter enters valid login credentials
    Then the Reporter should be authenticated
    And redirected to the Reporter project page

  Scenario: Maintainer logs in successfully
    Given a registered Maintainer account exists
    When the Maintainer enters valid login credentials
    Then the Maintainer should be authenticated
    And redirected to the Maintainer dashboard

  Scenario: Login fails with incorrect password
    Given a registered user account exists
    When the user enters an incorrect password
    Then the login should be rejected
    And an error message should be displayed

  Scenario: Guest accesses a protected page
    Given the user is not authenticated
    When the user accesses a protected page
    Then the user should be redirected to Login

  Scenario: Reporter accesses Maintainer dashboard
    Given the user is authenticated as Reporter
    When the user accesses the Maintainer dashboard
    Then access should be denied with HTTP 403

  Scenario: Maintainer accesses Reporter-only page
    Given the user is authenticated as Maintainer
    When the user accesses the Reporter-only page
    Then access should be denied with HTTP 403
