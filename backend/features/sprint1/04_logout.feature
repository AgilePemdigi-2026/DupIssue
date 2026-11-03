@sprint1 @logout @req07_2 @behat-mink
Feature: Logout dan Pengakhiran Sesi
  In order to mengakhiri akses akun pada perangkat yang digunakan
  As a Reporter or Maintainer
  I want to keluar dengan aman dan tidak dapat membuka halaman terlindungi tanpa login ulang

  Scenario Outline: Pengguna berhasil logout dan sesi tidak dapat digunakan kembali
    Given I am logged in as "<email>" with role "<role>"
    And I am on "/projects"
    When I press "Logout"
    Then I should be on "/login"
    And I should have no authenticated user
    When I am on "/projects"
    Then I should be on "/login"
    And I should have no authenticated user

    Examples:
      | email                    | role       |
      | reporter@dupissue.test   | Reporter   |
      | maintainer@dupissue.test | Maintainer |
