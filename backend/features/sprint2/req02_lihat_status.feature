@req02 @sprint2 @behat-mink
Feature: Lihat Status Issue (read-only)
  In order to memantau perkembangan issue dari project yang saya ikuti
  As a Reporter
  I want to mencari, memfilter, dan membaca status issue tanpa mengubah data

  Background:
    Given I am logged in as "reporter.req02@dupissue.test" with role "REPORTER"
    And I am an active member of project "REQ02 Testing" with id "1"

  @req02_2 @req02_3 @req02_5
  Scenario: Reporter mencari issue berdasarkan judul dan melihat detail
    Given I have an existing issue "Bayar gagal pada checkout" in my project
    When I am on "/projects/1/issues"
    Then I should see "Daftar Issue"
    When I fill in "cari" with "Bayar"
    And I select "Open" from "status"
    And I press "Cari"
    Then I should see "Bayar gagal pada checkout"
    When I follow "Lihat Detail"
    Then I should see "Riwayat perubahan status"
    And I should see "Muat Ulang"
    And I should see "Jumlah vote"

  @req02_4
  Scenario: Pencarian tanpa hasil menampilkan reset filter
    Given I have an existing issue "Bayar gagal pada checkout" in my project
    When I am on "/projects/1/issues"
    And I fill in "cari" with "judul-tidak-ada"
    And I press "Cari"
    Then I should see "Issue tidak tersedia"
    And I should see "Reset Filter"
    When I follow "Reset Filter"
    Then I should see "Bayar gagal pada checkout"

  @req02_6
  Scenario: Reporter menelusuri issue induk dari issue duplikat
    Given I have a duplicate issue and its original issue in my project
    When I open the duplicate issue detail
    Then I should see "Issue Induk"
    When I follow "Issue Induk"
    Then I should see "Issue induk untuk REQ-02"

  @req02_7 @req02_10
  Scenario: Refresh membaca status terbaru tanpa memodifikasi issue
    Given I have an existing issue "Status yang dipantau" in my project
    When I request current issue status from the read-only API
    Then I should receive issue status "OPEN"
    And the status refresh should not mutate the issue

  @req02_9
  Scenario: Issue tidak ditemukan mengembalikan halaman aman
    When I am on "/projects/1/issues/999999"
    Then I should see "Issue tidak tersedia"
    And I should see "Kembali ke Daftar"
    When I follow "Kembali ke Daftar"
    Then I should see "Daftar Issue"

  @req02_1
  Scenario: Reporter yang keanggotaannya nonaktif tidak dapat membaca issue
    Given I am not an active member of project "REQ02 Testing"
    When I am on "/projects/1/issues"
    Then I should be denied project access
