@auth @password-reset @otp
Feature: Reset password using email OTP
  As a registered Reporter or Maintainer
  I want to verify an email OTP before changing my password
  So that I can recover my account without opening a localhost link on another device

  Scenario: Registered user receives OTP
    Given I am a registered Reporter
    When I request a password reset OTP
    Then a six-digit OTP notification should be sent to my email

  Scenario: Valid OTP lets Reporter reset password
    Given I have a valid OTP sent to my email
    When I verify the correct OTP
    And I enter matching new passwords
    Then my password should be updated
    And my role should remain "REPORTER"

  Scenario: Invalid OTP is rejected
    Given I have an active password reset OTP
    When I enter an incorrect OTP
    Then the system should reject OTP verification

  Scenario: OTP is single-use and expires
    Given I have a valid OTP sent to my email
    When I successfully reset my password
    Then the same OTP must not be usable again

  Scenario: Reporter cannot bypass OTP verification
    Given I have not verified a password reset OTP
    When I try to open the new password form
    Then I should be redirected to the forgot password page
