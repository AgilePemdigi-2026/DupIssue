@auth @otp @password-reset @sprint1
Feature: Reset password using email OTP
  As a registered Reporter or Maintainer
  I want to verify an email OTP before setting a new password
  So that I can recover my account securely

  Scenario: Registered Reporter receives a six-digit OTP
    Given I have an OTP-test Reporter account
    When I request an OTP through the forgot-password form
    Then the OTP-test user has received a six-digit notification

  Scenario: Valid OTP allows a Reporter to reset a password without changing role
    Given I have an OTP-test Reporter account
    And I request an OTP through the forgot-password form
    When I verify the OTP and set a new password
    Then the OTP-test password is changed and the role is preserved

  Scenario: Incorrect OTP is rejected
    Given I have an OTP-test Reporter account
    And I request an OTP through the forgot-password form
    When I verify an incorrect OTP
    Then the OTP-test verification is denied

  Scenario: A used OTP cannot initiate another password reset
    Given I have an OTP-test Reporter account
    And I request an OTP through the forgot-password form
    When I verify the OTP and set a new password
    Then the used OTP cannot start another reset

  Scenario: An unverified visitor cannot open the new-password page
    Given I am an anonymous auth test visitor
    When I open the password-reset page without OTP verification
    Then the password-reset page redirects to forgot-password

  Scenario: An OTP older than five minutes is rejected
    Given I have an OTP-test Reporter account
    And I request an OTP through the forgot-password form
    When I move the OTP test clock forward six minutes
    And I try to verify the OTP
    Then the OTP-test verification is denied
