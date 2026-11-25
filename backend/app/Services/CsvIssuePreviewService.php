<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CsvIssuePreviewService
{
    public function preview(Project $project, array $parsed): array
    {
        // Kumpulkan source_id untuk diperiksa terhadap database.
        $sourceIds = [];

        foreach ($parsed['rows'] as $row) {
            $id = $row['data']['source_id'] ?? null;

            if (is_string($id) && trim($id) !== '') {
                $sourceIds[] = mb_strtolower(trim($id));
            }
        }

        // Batasi jumlah parameter per query.
        // Pencarian hanya pada CSV dalam project yang sama.
        $existingIds = [];

        foreach (array_chunk(array_unique($sourceIds), 400) as $chunk) {
            $found = Issue::query()
                ->where('project_id', $project->getKey())
                ->where('source_type', 'CSV')
                ->whereIn(DB::raw('LOWER(source_id)'), $chunk)
                ->pluck('source_id');

            foreach ($found as $id) {
                $existingIds[mb_strtolower((string) $id)] = true;
            }
        }

        $seenInFile = [];
        $previewRows = [];

        $validCount = 0;
        $invalidCount = 0;
        $importedCount = 0;

        foreach ($parsed['rows'] as $row) {
            $result = [
                'record_number' => $row['record_number'],
                'status' => 'INVALID',
                'reason' => null,
                'data' => $row['data'],
            ];

            // Baris dengan jumlah kolom tidak sesuai.
            if ($row['format_error'] !== null || !is_array($row['data'])) {
                $result['reason'] =
                    $row['format_error'] ?? 'Format baris tidak valid.';

                $invalidCount++;
                $previewRows[] = $result;
                continue;
            }

            // Normalisasi spasi pada field wajib.
            $data = [
                'source_id' => trim((string) ($row['data']['source_id'] ?? '')),
                'title' => trim((string) ($row['data']['title'] ?? '')),
                'description' => trim((string) ($row['data']['description'] ?? '')),
            ];

            $result['data'] = $data;

            // Batas panjang mengikuti validasi issue aplikasi.
            $validator = Validator::make($data, [
                'source_id' => ['required', 'string', 'max:100'],
                'title' => ['required', 'string', 'min:5', 'max:200'],
                'description' => ['required', 'string', 'min:10', 'max:10000'],
            ], [
                'required' => ':attribute wajib diisi.',
                'min' => ':attribute terlalu pendek (minimal :min karakter).',
                'max' => ':attribute terlalu panjang (maksimal :max karakter).',
            ]);

            if ($validator->fails()) {
                $result['reason'] = $validator->errors()->first();
                $invalidCount++;
                $previewRows[] = $result;
                continue;
            }

            $key = mb_strtolower($data['source_id']);

            if (isset($existingIds[$key])) {
                $result['status'] = 'ALREADY_IMPORTED';
                $result['reason'] = 'source_id sudah pernah diimpor.';
                $importedCount++;
            } elseif (isset($seenInFile[$key])) {
                $result['reason'] = 'source_id berulang dalam file CSV.';
                $invalidCount++;
            } else {
                $seenInFile[$key] = true;
                $result['status'] = 'VALID';
                $validCount++;
            }

            $previewRows[] = $result;
        }

        return [
            'total_rows' => $parsed['total_rows'],
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'already_imported_count' => $importedCount,
            'rows' => $previewRows,
        ];
    }
}
