@auth @login @rbac @sprint1
Feature: Login and Role-Based Access Control
  As a DupIssue user
  I want to sign in and access only pages allowed by my role
  So that Reporter and Maintainer privileges remain separated

  Scenario: Reporter logs in successfully
    Given I have an auth test account with role "REPORTER"
    When I log in with the correct auth test password
    Then the auth test user is authenticated
    And I land on the "REPORTER" home page

  Scenario: Maintainer logs in successfully
    Given I have an auth test account with role "MAINTAINER"
    When I log in with the correct auth test password
    Then the auth test user is authenticated
    And I land on the "MAINTAINER" home page

  Scenario: Login fails with an incorrect password
    Given I have an auth test account with role "REPORTER"
    When I log in with an incorrect auth test password
    Then the auth test login is rejected with a validation message

  Scenario: Guest cannot access a protected page
    Given I am an anonymous auth test visitor
    When I open the "MAINTAINER" restricted page
    Then the auth test visitor is sent to login

  Scenario: Reporter cannot access the Maintainer dashboard
    Given I have an auth test account with role "REPORTER"
    And I log in with the correct auth test password
    When I open the "MAINTAINER" restricted page
    Then the auth test request is forbidden

  Scenario: Maintainer cannot access the Reporter project page
    Given I have an auth test account with role "MAINTAINER"
    And I log in with the correct auth test password
    When I open the "REPORTER" restricted page
    Then the auth test request is forbidden
