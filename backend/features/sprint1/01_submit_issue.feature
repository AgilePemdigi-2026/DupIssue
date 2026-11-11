@sprint1 @req01 @behat-mink
Feature: Submit Issue
  In order to menyimpan laporan bug yang valid pada project saya
  As a Reporter
  I want to mengirim issue tanpa menetapkan status atau keputusan duplikat secara otomatis

  Background:
    Given I am logged in as "reporter@dupissue.test" with role "Reporter"
    And I am an active member of project "Toko Online Baju" with id "1"

  @req01_1 @req01_2 @req01_11 @req01_12
  Scenario: Reporter berhasil mengirim issue yang valid
    Given I am on "/projects/1/submit"
    When I fill in "title" with "Tombol Bayar tidak merespons"
    And I fill in "description" with "Tombol bayar tidak merespons setelah diklik di halaman checkout."
    And I press "Kirim Issue"
    Then I should see "Issue berhasil dibuat"
    And I should see "OPEN"
    And I should see "PENDING"
    And I should find one new issue with status "OPEN" and detection "PENDING"
    And I should find one NLP job in the "nlp" queue

  @req01_2 @req01_10
  Scenario Outline: Validasi panjang judul dan deskripsi
    Given I am on "/projects/1/submit"
    When I fill in "title" with "<title>"
    And I fill in "description" with "<description>"
    And I press "Kirim Issue"
    Then I should see validation error for "<field>"
    And I should find no new issues in the database

    Examples:
      | title         | description                                    | field       |
      | ab            | Deskripsi panjang yang sudah memenuhi batas.    | title       |
      | Judul Valid   | pendek                                         | description |
      |               | Deskripsi panjang yang sudah memenuhi batas.    | title       |
      | Judul Valid   |                                                | description |

  @req01_1 @req07_3
  Scenario: Reporter tanpa keanggotaan aktif tidak dapat mengirim issue
    Given I am not an active member of project "Toko Online Baju"
    When I am on "/projects/1/submit"
    Then I should be denied project access
    And I should find no new issues in the database
