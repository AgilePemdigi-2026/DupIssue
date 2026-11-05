Feature: Rekomendasi Issue Serupa sebelum Submit Issue
  Sebagai Reporter yang menjadi anggota aktif project
  Saya ingin melihat rekomendasi issue serupa sebelum mengirim laporan
  Agar dapat memeriksa kemungkinan issue yang sudah pernah dilaporkan

  Scenario: Reporter dapat membuka formulir dengan panel rekomendasi
    Given I am logged in as "reporter.sprint2@example.test" with role "REPORTER"
    And I am an active member of project "DupIssue Testing" with id "1"
    When I am on "/projects/1/submit"
    Then I should see "Kirim Issue"
    And I should see "Isi judul dan deskripsi untuk melihat rekomendasi."


  Scenario: Reporter mendapatkan Top 3 rekomendasi dari project yang sama
    Given I am logged in as "reporter.sprint2@example.test" with role "REPORTER"
    And I am an active member of project "DupIssue Testing" with id "1"
    And I have four comparison issues in my project and one in another project
    And I have deterministic NLP rankings for Sprint 2
    When I request pre-submit recommendations with title "Tombol bayar tidak merespons" and description "Saat checkout tombol bayar gagal memproses transaksi."
    Then I should receive exactly 3 recommendations ordered by similarity
    And I should receive no recommendations from another project
    And I should find no new draft issue in the database


  Scenario: Layanan NLP tidak tersedia saat Reporter meminta rekomendasi
    Given I am logged in as "reporter.sprint2@example.test" with role "REPORTER"
    And I am an active member of project "DupIssue Testing" with id "1"
    And I have an existing issue "Checkout gagal diproses" in my project
    And I have an NLP service failure with HTTP status 503
    When I request pre-submit recommendations while NLP is unavailable
    Then I should receive HTTP 503 with code "NLP_UNAVAILABLE"
    And I should find no new draft issue in the database


  Scenario: Reporter mengirim judul terlalu pendek saat meminta rekomendasi
    Given I am logged in as "reporter.sprint2@example.test" with role "REPORTER"
    And I am an active member of project "DupIssue Testing" with id "1"
    When I request pre-submit recommendations with invalid "title" "abc"
    Then I should receive HTTP 422 with validation error for "title"
    And I should find no new draft issue in the database

  Scenario: Reporter mengirim deskripsi terlalu pendek saat meminta rekomendasi
    Given I am logged in as "reporter.sprint2@example.test" with role "REPORTER"
    And I am an active member of project "DupIssue Testing" with id "1"
    When I request pre-submit recommendations with invalid "description" "abc"
    Then I should receive HTTP 422 with validation error for "description"
    And I should find no new draft issue in the database

  Scenario: Reporter dengan keanggotaan tidak aktif ditolak
    Given I am logged in as "reporter.sprint2@example.test" with role "REPORTER"
    And I am an active member of project "DupIssue Testing" with id "1"
    And I am not an active member of project "DupIssue Testing"
    When I am on "/projects/1/submit"
    Then I should be denied project access

  Scenario: Maintainer tidak dapat mengakses formulir Submit Issue Reporter
    Given I am logged in as "maintainer.sprint2@example.test" with role "MAINTAINER"
    And I am an active member of project "DupIssue Testing" with id "1"
    When I am on "/projects/1/submit"
    Then I should be denied project access

  Scenario: Rekomendasi issue duplikat diarahkan ke issue induk
    Given I am logged in as "reporter.sprint2@example.test" with role "REPORTER"
    And I am an active member of project "DupIssue Testing" with id "1"
    And I have a duplicate issue linked to its parent in my project
    And I have an NLP candidate pointing to the duplicate issue
    When I request pre-submit recommendations with title "Tombol bayar tidak merespons" and description "Saat checkout tombol bayar gagal memproses transaksi."
    Then I should receive a recommendation linking to the original issue
    And I should find no new draft issue in the database
