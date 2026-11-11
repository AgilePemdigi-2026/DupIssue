# Skenario Register disusun berdasarkan Sprint Planning Sprint 1.
# Skenario telah diimplementasikan sebagai BDD menggunakan Behat.
# Step definitions: backend/features/bootstrap/FeatureContext.php
# Bukti hasil pengujian: docs/testing/sprint-1/
# Hasil pengujian: 7 scenarios passed, 39 steps passed.

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

  @backend
  Scenario: Backend menolak password yang kurang dari delapan karakter
    When I submit a registration request with a password shorter than 8 characters
    Then I should receive a password validation error
    And I should not have a new user account created

  @backend
  Scenario: Backend menolak field registrasi yang wajib tetapi kosong
    When I submit a registration request without required fields
    Then I should receive validation errors for "name", "email" and "password"
    And I should not have a new user account created

  @backend
  Scenario: Pengunjung tidak dapat menentukan role MAINTAINER sendiri
    When I submit valid registration data with role "MAINTAINER"
    Then I should have a new account with role "REPORTER"
    And I should not have role "MAINTAINER"
