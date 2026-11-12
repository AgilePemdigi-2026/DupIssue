<?php

namespace Tests\Feature;

use App\Services\CsvIssueReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CsvIssueReaderTest extends TestCase
{
    private function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'dataset.csv',
            $content
        );
    }

    private function assertRejected(
        UploadedFile $file,
        string $expectedMessage
    ): void {
        try {
            app(CsvIssueReader::class)->read($file);

            $this->fail('CSV seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['file'][0] ?? '';

            $this->assertStringContainsString(
                $expectedMessage,
                $message
            );
        }
    }

    public function test_valid_csv_can_be_read(): void
    {
        $file = $this->csvFile(
            "source_id,title,description\n".
            "BUG-001,Login gagal,Tidak bisa login\n".
            "BUG-002,Error dashboard,Halaman tidak muncul\n"
        );

        $result = app(CsvIssueReader::class)->read($file);

        $this->assertSame(2, $result['total_rows']);
        $this->assertSame('BUG-001', $result['rows'][0]['data']['source_id']);
        $this->assertSame('Login gagal', $result['rows'][0]['data']['title']);
        $this->assertNull($result['rows'][0]['format_error']);
    }

    public function test_utf8_bom_is_accepted(): void
    {
        $file = $this->csvFile(
            "\xEF\xBB\xBFsource_id,title,description\n".
            "BUG-001,Login gagal,Tidak bisa login\n"
        );

        $result = app(CsvIssueReader::class)->read($file);

        $this->assertSame(1, $result['total_rows']);
        $this->assertSame(
            'BUG-001',
            $result['rows'][0]['data']['source_id']
        );
    }

    public function test_empty_csv_is_rejected(): void
    {
        $this->assertRejected(
            $this->csvFile(''),
            'kosong'
        );
    }

    public function test_missing_required_column_is_rejected(): void
    {
        $this->assertRejected(
            $this->csvFile(
                "source_id,title\nBUG-001,Login gagal\n"
            ),
            'description'
        );
    }

    public function test_non_utf8_csv_is_rejected(): void
    {
        $this->assertRejected(
            $this->csvFile(
                "source_id,title,description\n".
                "BUG-001,Error,\xFF\n"
            ),
            'UTF-8'
        );
    }

    public function test_file_larger_than_5mb_is_rejected(): void
    {
        $content = "source_id,title,description\n".
            "BUG-001,Judul,".
            str_repeat('x', 5 * 1024 * 1024);

        $this->assertRejected(
            $this->csvFile($content),
            '5 MB'
        );
    }

    public function test_more_than_5000_rows_is_rejected(): void
    {
        $content = "source_id,title,description\n";

        for ($i = 1; $i <= 5001; $i++) {
            $content .= "BUG-$i,Judul $i,Deskripsi $i\n";
        }

        $this->assertRejected(
            $this->csvFile($content),
            '5.000'
        );
    }

    public function test_malformed_row_is_marked_invalid(): void
    {
        $file = $this->csvFile(
            "source_id,title,description\n".
            "BUG-001,Login gagal\n"
        );

        $result = app(CsvIssueReader::class)->read($file);

        $this->assertSame(1, $result['total_rows']);
        $this->assertNull($result['rows'][0]['data']);
        $this->assertSame(
            'Jumlah kolom tidak sesuai dengan header.',
            $result['rows'][0]['format_error']
        );
    }

    public function test_duplicate_headers_are_rejected(): void
    {
        $this->assertRejected(
            $this->csvFile(
                "source_id,title,description,title\n".
                "BUG-001,Login gagal,Deskripsi,Duplikat\n"
            ),
            'berulang'
        );
    }
}
