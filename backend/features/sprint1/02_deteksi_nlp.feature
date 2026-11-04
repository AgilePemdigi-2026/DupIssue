@sprint1 @nlp @req01 @behat-mink
Feature: Deteksi Kemiripan NLP setelah Submit Issue
  In order to membantu Maintainer meninjau kandidat duplikat dalam project yang sama
  As a Reporter
  I want to memastikan issue tetap tersimpan dan diproses secara asynchronous

  Background:
    Given I am logged in as "reporter@dupissue.test" with role "Reporter"
    And I am an active member of project "Toko Online Baju" with id "1"

  @req01_11 @req01_12 @req_nf_09
  Scenario: Antrean NLP memproses kandidat dari project yang sama
    Given I have an existing issue "Checkout gagal dibayar" in my project
    And I have an existing issue "Masalah project lain" in another project
    And I have an NLP response with a same-project similarity of 0.90 and a foreign-project similarity of 0.99
    And I am on "/projects/1/submit"
    When I fill in "title" with "Pembayaran gagal saat checkout"
    And I fill in "description" with "Pembayaran gagal diproses ketika menekan tombol checkout."
    And I press "Kirim Issue"
    Then I should find one new issue with status "OPEN" and detection "PENDING"
    And I should find one NLP job in the "nlp" queue
    When I process one queued NLP job
    Then I should find the new issue with detection "COMPLETED"
    And I should find a duplicate candidate from the same project with similarity 0.90
    And I should find no duplicate candidates from another project
    And I should find the new issue with status "OPEN" and no duplicate parent

  @req01_12 @req_nf_09
  Scenario: Kegagalan NLP permanen tidak menghapus issue
    Given I have an existing issue "Checkout gagal dibayar" in my project
    And I have an NLP service failure with HTTP status 422
    And I am on "/projects/1/submit"
    When I fill in "title" with "Checkout tidak bisa diproses"
    And I fill in "description" with "Sistem menolak pembayaran ketika pengguna menekan tombol checkout."
    And I press "Kirim Issue"
    And I process one queued NLP job
    Then I should find the new issue with detection "FAILED"
    And I should find the new issue with status "OPEN" and no duplicate parent
