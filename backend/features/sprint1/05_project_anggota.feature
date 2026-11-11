@sprint1 @project-anggota @req03 @behat-mink
Feature: Mengelola Project & Anggota
  In order to mengelola workspace project sesuai hak akses
  As a Maintainer
  I want to membuat project, mengundang Reporter, dan mengelola keanggotaan

  Background:
    Given I am logged in as "elsa@dupissue.test" with role "Maintainer"

  @req03_1 @req03_2 @req03_3 @req03_5 @req03_6
  Scenario: Maintainer membuat project dan mengundang Reporter
    Given I have a registered Reporter "valentino@dupissue.test"
    And I am on "/projects"
    When I follow "Buat Project"
    And I fill in "Nama Project" with "Toko Online Baju"
    And I fill in "Deskripsi" with "Aplikasi toko baju online"
    And I press "Simpan"
    Then I should see "Project berhasil dibuat"
    And I should find project "Toko Online Baju" owned by the current Maintainer
    When I am on "/projects/1/members"
    And I fill in "Email Anggota" with "valentino@dupissue.test"
    And I press "Kirim Undangan"
    Then I should see "Undangan berhasil dikirim"
    And I should see "Pending"
    And I should find invitation for "valentino@dupissue.test" with status "PENDING"
    And I should find no active membership for "valentino@dupissue.test"

  @req03_4
  Scenario: Maintainer memperbarui informasi project miliknya
    Given I own a project named "Toko Online Baju"
    And I am on "/projects/1/settings"
    When I fill in "Nama Project" with "Toko Online Baju V2"
    And I press "Simpan Perubahan"
    Then I should see "Perubahan berhasil disimpan"
    And I should see "Toko Online Baju V2"
    And I should find project "Toko Online Baju V2" owned by the current Maintainer

  @req03_7
  Scenario: Maintainer membatalkan undangan Pending
    Given I own a project named "Project Undangan"
    And I have a pending invitation for Reporter "valentino@dupissue.test"
    And I am on "/projects/1/members"
    Then I should see "Konfirmasi pembatalan undangan"
    When I confirm the pending invitation cancellation
    Then I should see "Undangan berhasil dibatalkan"
    And I should find invitation for "valentino@dupissue.test" with status "CANCELED"
    And I should find no active membership for "valentino@dupissue.test"

  @req03_8 @req07_3
  Scenario: Maintainer mencabut akses anggota tanpa menghapus issue lama
    Given I own a project named "Project Laporan"
    And I have an active Reporter "valentino@dupissue.test" with an existing issue
    And I am on "/projects/1/members"
    Then I should see "dampak pencabutan akses"
    When I confirm removal of Reporter "valentino@dupissue.test"
    Then I should see "Keanggotaan dinonaktifkan"
    And I should find inactive membership for "valentino@dupissue.test"
    And I should find the existing issue preserved
    When I sign in as registered Reporter "valentino@dupissue.test"
    And I am on "/projects/1"
    Then I should be denied project access

  @req03_9
  Scenario: Maintainer dapat mengundang ulang Reporter setelah pembatalan
    Given I own a project named "Project Undang Ulang"
    And I have a pending invitation for Reporter "valentino@dupissue.test"
    And I am on "/projects/1/members"
    When I confirm the pending invitation cancellation
    And I fill in "Email Anggota" with "valentino@dupissue.test"
    And I press "Kirim Undangan"
    Then I should see "Undangan berhasil dikirim"
    And I should find 2 invitation records for "valentino@dupissue.test"
    And I should find invitation for "valentino@dupissue.test" with status "PENDING"

  @req03_6 @req03_10 @req07_3
  Scenario: Undangan Pending tidak memberikan akses sampai diterima Reporter
    Given I own a project named "Project Undangan Masuk"
    And I have a pending invitation for Reporter "valentino@dupissue.test"
    When I sign in as registered Reporter "valentino@dupissue.test"
    And I am on "/projects/1"
    Then I should be denied project access
    When I am on "/invitations"
    Then I should see "Project Undangan Masuk"
    When I press "Terima"
    Then I should see "Undangan berhasil diterima"
    And I should find invitation for "valentino@dupissue.test" with status "ACCEPTED"
    And I should find active membership for "valentino@dupissue.test"
    When I am on "/projects/1"
    Then I should see "Project Undangan Masuk"

  @req03_10
  Scenario: Penolakan undangan Reporter tidak memberikan akses
    Given I own a project named "Project Penolakan"
    And I have a pending invitation for Reporter "valentino@dupissue.test"
    When I sign in as registered Reporter "valentino@dupissue.test"
    And I am on "/invitations"
    And I press "Tolak"
    Then I should see "Undangan berhasil ditolak"
    And I should find invitation for "valentino@dupissue.test" with status "REJECTED"
    And I should find no active membership for "valentino@dupissue.test"
    When I am on "/projects/1"
    Then I should be denied project access

  @req03_2
  Scenario Outline: Nama project tidak memenuhi panjang yang diwajibkan SRS
    Given I am on "/projects/new"
    When I fill in "Nama Project" with "<nama>"
    And I fill in "Deskripsi" with "Deskripsi valid"
    And I press "Simpan"
    Then I should see "Nama wajib 3-100 karakter"
    And I should find no projects created by the current Maintainer

    Examples:
      | nama |
      |      |
      | ab   |

  @req03_2 @req03_11
  Scenario: Nama project duplikat ditolak
    Given I own a project named "Toko Online Baju"
    And I am on "/projects/new"
    When I fill in "Nama Project" with "Toko Online Baju"
    And I press "Simpan"
    Then I should see "Nama sudah digunakan"
    And I should find exactly one project named "Toko Online Baju"

  @req03_5
  Scenario: Format email undangan tidak valid ditolak
    Given I own a project named "Project Email"
    And I am on "/projects/1/members"
    When I fill in "Email Anggota" with "bukan-email"
    And I press "Kirim Undangan"
    Then I should see "Format email tidak valid"
    And I should find no project invitations

  @req03_5
  Scenario: Maintainer bukan penerima undangan Reporter
    Given I own a project named "Project Role"
    And I have a registered Maintainer "joshua@dupissue.test"
    And I am on "/projects/1/members"
    When I fill in "Email Anggota" with "joshua@dupissue.test"
    And I press "Kirim Undangan"
    Then I should see "Akun bukan Reporter"
    And I should find no project invitations

  @req03_5 @req03_11
  Scenario: Undangan Pending ganda ditolak
    Given I own a project named "Project Pending"
    And I have a pending invitation for Reporter "valentino@dupissue.test"
    And I am on "/projects/1/members"
    When I fill in "Email Anggota" with "valentino@dupissue.test"
    And I press "Kirim Undangan"
    Then I should see "Sudah menjadi anggota atau memiliki undangan Pending"
    And I should find 1 invitation records for "valentino@dupissue.test"

  @req03_1 @req07_3
  Scenario: Reporter tidak boleh membuat project
    Given I have a registered Reporter "valentino@dupissue.test"
    When I sign in as registered Reporter "valentino@dupissue.test"
    And I am on "/projects/new"
    Then I should be denied project access
    And I should find no projects created by the current Maintainer

  @req03_4 @req07_3
  Scenario: Maintainer lain tidak boleh mengubah project pemilik lain
    Given I own a project named "Project Privat"
    And I have a registered Maintainer "joshua@dupissue.test"
    When I sign in as registered Maintainer "joshua@dupissue.test"
    And I am on "/projects/1/settings"
    Then I should be denied project access
    And I should find exactly one project named "Project Privat"
