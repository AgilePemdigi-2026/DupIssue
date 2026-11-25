<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CsvIssueReader
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_ROWS = 5000;

    private const REQUIRED_COLUMNS = [
        'source_id',
        'title',
        'description',
    ];

    public function read(UploadedFile $file): array
    {
        $size = $file->getSize();

        if ($size === false || $size === 0) {
            $this->reject('File CSV kosong atau tidak dapat dibaca.');
        }

        if ($size > self::MAX_BYTES) {
            $this->reject('Ukuran CSV maksimal 5 MB.');
        }

        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            $this->reject('File harus berformat CSV.');
        }

        $path = $file->getRealPath();
        $content = $path ? file_get_contents($path) : false;

        if ($content === false || trim($content) === '') {
            $this->reject('File CSV kosong atau rusak.');
        }

        if (preg_match('//u', $content) !== 1 || str_contains($content, "\0")) {
            $this->reject('File harus menggunakan encoding UTF-8.');
        }

        // Terima UTF-8 dengan BOM maupun tanpa BOM.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            $this->reject('File CSV tidak dapat diproses.');
        }

        try {
            fwrite($stream, $content);
            rewind($stream);

            $headers = fgetcsv($stream, 0, ',', '"', '');

            if (!is_array($headers)) {
                $this->reject('Header CSV tidak ditemukan.');
            }

            $headers = array_map('trim', $headers);

            if (count($headers) !== count(array_unique($headers))) {
                $this->reject('Header CSV mengandung nama kolom berulang.');
            }

            $missing = array_diff(self::REQUIRED_COLUMNS, $headers);

            if ($missing !== []) {
                $this->reject(
                    'Kolom wajib tidak ditemukan: '.implode(', ', $missing)
                );
            }

            $rows = [];
            $recordNumber = 1;

            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $recordNumber++;

                if (count($rows) >= self::MAX_ROWS) {
                    $this->reject('CSV maksimal 5.000 baris data.');
                }

                $matchesHeader = count($values) === count($headers);

                $rows[] = [
                    'record_number' => $recordNumber,
                    'data' => $matchesHeader
                        ? array_combine($headers, $values)
                        : null,
                    'format_error' => $matchesHeader
                        ? null
                        : 'Jumlah kolom tidak sesuai dengan header.',
                ];
            }

            if ($rows === []) {
                $this->reject('CSV tidak memiliki baris data.');
            }

            return [
                'headers' => $headers,
                'rows' => $rows,
                'total_rows' => count($rows),
            ];
        } finally {
            fclose($stream);
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages([
            'file' => $message,
        ]);
    }
}
