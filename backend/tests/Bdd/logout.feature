Feature: Logout
  As a logged-in Reporter or Maintainer
  I want to log out securely
  So that my authenticated session is terminated

  Scenario: Pengguna berhasil logout
    Given I am logged in as a registered user
    When I submit the logout form
    Then I should be redirected to the login page
    And I should no longer be authenticated

  Scenario: Halaman terlindungi tidak bisa diakses setelah logout
    Given I am logged in as a registered user
    When I submit the logout form
    And I try to open a protected page
    Then I should be redirected to the login page

  Scenario: Logout tidak menerima metode GET
    Given I am logged in as a registered user
    When I request the logout URL with GET
    Then I should receive a method not allowed response
