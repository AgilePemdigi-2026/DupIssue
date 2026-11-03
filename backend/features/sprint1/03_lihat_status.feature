@sprint1 @req02 @behat-mink
Feature: Lihat Status Issue
  In order to memantau laporan pada project yang saya ikuti
  As a Reporter
  I want to melihat daftar dan detail issue tanpa dapat mengubah statusnya

  Background:
    Given I am logged in as "reporter@dupissue.test" with role "Reporter"
    And I am an active member of project "Toko Online Baju" with id "1"

  @req02_1 @req02_5 @req02_10
  Scenario: Reporter dapat melihat detail issue secara read-only
    Given I have an existing issue "Checkout gagal dibayar" in my project
    When I am on "/projects/1/issues"
    Then I should see "Checkout gagal dibayar"
    When I follow "Lihat Detail"
    Then I should see "Checkout gagal dibayar"
    And I should see "OPEN"
    And I should not see "Simpan Status"

  @req02_1 @req07_3
  Scenario: Keanggotaan nonaktif memblokir akses daftar issue
    Given I am not an active member of project "Toko Online Baju"
    When I am on "/projects/1/issues"
    Then I should be denied project access
