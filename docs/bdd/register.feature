# DupIssue / Sprint 1 / Feature Register
# Addendum skenario terhadap SRS: Register adalah Feature 1 pada Sprint Planning,
# tetapi belum mempunyai User Story Scenario tersendiri pada SRS awal.
# Format mengikuti Gherkin/Behat (Given / When / Then) yang dipakai SRS.
# Skenario ini merupakan spesifikasi; keberhasilan eksekusi yang sudah ada
# dibuktikan oleh tests/Feature/Auth/RegisterTest.php (Laravel Feature Test).
# Skenario bertag @needs-steps memerlukan step definitions bila ingin dijalankan dengan Behat.

@register @sprint1
Feature: Register Akun DupIssue
  In order to dapat melaporkan issue menggunakan akun sendiri
  As a calon pengguna DupIssue
  I want to mendaftarkan akun dengan data valid dan aman

  Background:
    Given I am on "/register"

  @ui
  Scenario: Pengunjung dapat membuka halaman Register
    Then I should see "DupIssue"
    And I should see "Daftar Akun"

  @ui
  Scenario: Pengunjung berhasil mendaftarkan akun dengan data valid
    When I fill in "name" with "Test Reporter"
    And I fill in "email" with "reporter.bdd@example.com"
    And I fill in "password" with "Password123!"
    And I fill in "password_confirmation" with "Password123!"
    And I press "Daftar Akun"
    Then I should see "Registrasi berhasil! Akun Anda telah dibuat."

  @needs-steps
  Scenario: Pendaftaran ditolak jika email sudah digunakan
    Given I have an existing account with email "reporter.test@example.com"
    When I fill in "name" with "Test Reporter Kedua"
    And I fill in "email" with "reporter.test@example.com"
    And I fill in "password" with "Password123!"
    And I fill in "password_confirmation" with "Password123!"
    And I press "Daftar Akun"
    Then I should see "The email has already been taken."
    And I should not see "Registrasi berhasil! Akun Anda telah dibuat."

  @ui
  Scenario: Pendaftaran ditolak jika konfirmasi password tidak sesuai
    When I fill in "name" with "Test Reporter"
    And I fill in "email" with "confirm.bdd@example.com"
    And I fill in "password" with "Password123!"
    And I fill in "password_confirmation" with "Different123!"
    And I press "Daftar Akun"
    Then I should see "The password field confirmation does not match."
    And I should not see "Registrasi berhasil! Akun Anda telah dibuat."

  @needs-steps @backend
  Scenario: Backend menolak password yang kurang dari delapan karakter
    When I submit a registration request with a password shorter than 8 characters
    Then I should receive a password validation error
    And I should not have a new user account created

  @needs-steps @backend
  Scenario: Backend menolak field registrasi yang wajib tetapi kosong
    When I submit a registration request without required fields
    Then I should receive validation errors for "name", "email" and "password"
    And I should not have a new user account created

  @needs-steps @backend
  Scenario: Pengunjung tidak dapat menentukan role MAINTAINER sendiri
    When I submit valid registration data with role "MAINTAINER"
    Then I should have a new account with role "REPORTER"
    And I should not have role "MAINTAINER"
