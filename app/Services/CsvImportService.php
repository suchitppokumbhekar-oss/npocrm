<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadImportBatch;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CsvImportService
{
    /**
     * Common header aliases → canonical field keys.
     */
    private const HEADER_ALIASES = [
        'name'   => [
            'name', 'customer', 'customer_name', 'full_name', 'fullname',
            'client', 'client_name', 'lead_name', 'contact_name', 'person_name',
            'first_name', 'prospect_name',
        ],
        'phone'  => [
            'phone', 'mobile', 'contact', 'phone_number', 'mobile_number',
            'contact_no', 'phone_no', 'mobile_no', 'contact_number', 'telephone',
            'telephone_number', 'phone_number_1', 'mobile_number_1',
        ],
        'email'  => [
            'email', 'e_mail', 'email_address', 'email_id', 'mail', 'email_id_1',
        ],
        'source' => [
            'source', 'lead_source', 'channel', 'origin', 'lead_origin',
            'campaign', 'campaign_name', 'lead_source_name',
        ],
    ];

    public function __construct(
        private SettingsService $settings,
        private LeadAssignmentService $assignment,
    ) {}

    /* ============================================================
       PARSE — read the CSV into a 2D array
       ============================================================ */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'], true)) {
            throw new \RuntimeException('Excel files are converted in the browser before upload. Please keep JavaScript enabled and try the file again.');
        }

        $path = $file->getRealPath();
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        // Detect and tolerate UTF-8 BOM.
        $first = fread($handle, 3);
        if ($first !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new \RuntimeException('The file is empty.');
        }
        rewind($handle);
        if ($first === "\xEF\xBB\xBF") {
            fread($handle, 3);
        }

        // Facebook/Excel exports are commonly comma, semicolon or tab delimited.
        // Pick the delimiter with the strongest header-line signal.
        $delimiter = $this->detectDelimiter($firstLine);
        $headers = fgetcsv($handle, 0, $delimiter);
        if (! $headers) {
            fclose($handle);
            throw new \RuntimeException('The file is empty or has no header row.');
        }

        $headers = $this->normalizeHeaders($headers);
        if (count(array_filter($headers, fn ($h) => $h !== '')) === 0) {
            fclose($handle);
            throw new \RuntimeException('No usable column headers were found in the first row.');
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') continue;

            // Keep the row width aligned with the header width. This prevents a
            // malformed trailing field from shifting later mapped columns.
            $row = array_pad($row, count($headers), '');
            if (count($row) > count($headers)) {
                $row = array_slice($row, 0, count($headers));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return [
            'headers'   => $headers,
            'rows'      => $rows,
            'delimiter' => $delimiter,
        ];
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [',', ';', "\t", '|'];
        $best = ',';
        $bestCount = -1;

        foreach ($candidates as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $value = trim((string) $header);
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
            $value = preg_replace('/[^a-z0-9_]+/', '_', strtolower($value));
            return trim($value, '_');
        }, $headers);
    }

    /**
     * Try to auto-detect which CSV column maps to which canonical field.
     * Exact aliases win; common Facebook/Excel variations are included.
     */
    public function detectMapping(array $headers, array $rows = []): array
    {
        $mapping = [];
        $used = [];

        // 1) Exact/common header aliases are the strongest signal. Source is
        // deliberately handled separately because arbitrary exports often contain
        // a column named Source whose values are actually long lead-detail payloads.
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            if ($field === 'source') continue;
            foreach ($headers as $idx => $header) {
                if (isset($used[$idx])) continue;
                if (in_array($header, $aliases, true)) {
                    $mapping[$field] = $idx;
                    $used[$idx] = true;
                    break;
                }
            }
        }

        // 2) Inspect actual values. This both fills generic Name/Phone/Email fields
        // and identifies a genuinely source-like column instead of blindly mapping
        // campaign/ad/detail text into the 50-character CRM source field.
        if ($rows) {
            $sample = array_slice($rows, 0, 25);
            $candidateScores = [];
            foreach ($headers as $idx => $_header) {
                if (isset($used[$idx])) continue;
                $values = [];
                foreach ($sample as $row) {
                    $value = trim((string) ($row[$idx] ?? ''));
                    if ($value !== '') $values[] = $value;
                }
                if (! $values) continue;

                $emailScore = 0;
                $phoneScore = 0;
                $nameScore = 0;
                $sourceScore = 0;
                foreach ($values as $value) {
                    if (filter_var($value, FILTER_VALIDATE_EMAIL)) $emailScore++;
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) >= 10 && strlen($digits) <= 13 && preg_match('/^[+()\d\s.\-]+$/', $value)) $phoneScore++;
                    if (! preg_match('/^\+?\d[\d\s().-]*$/', $value) && preg_match('/[A-Za-zÀ-ÿ]{2,}/', $value)) $nameScore++;
                    if ($this->looksLikeSourceValue($value)) $sourceScore++;
                }
                $count = max(1, count($values));
                $headerSourceScore = $this->sourceHeaderScore((string) ($headers[$idx] ?? ''));
                $candidateScores[$idx] = [
                    'email'  => $emailScore / $count,
                    'phone'  => $phoneScore / $count,
                    'name'   => $nameScore / $count,
                    'source' => min(1, ($sourceScore / $count) * 0.75 + $headerSourceScore * 0.25),
                ];
            }

            foreach (['phone', 'email', 'name'] as $field) {
                if (isset($mapping[$field])) continue;
                $bestIdx = null;
                $bestScore = 0;
                foreach ($candidateScores as $idx => $scores) {
                    if (isset($used[$idx])) continue;
                    if (($scores[$field] ?? 0) > $bestScore) {
                        $bestScore = $scores[$field];
                        $bestIdx = $idx;
                    }
                }
                if ($bestIdx !== null && $bestScore >= 0.70) {
                    $mapping[$field] = $bestIdx;
                    $used[$bestIdx] = true;
                }
            }

            // Source is optional. Only auto-map it when the column contains
            // predominantly short, categorical values. Long campaign/ad/form
            // payloads are intentionally left unmapped and fall back to 'import'.
            if (! isset($mapping['source'])) {
                $bestIdx = null;
                $bestScore = 0;
                foreach ($candidateScores as $idx => $scores) {
                    if (isset($used[$idx])) continue;
                    if (($scores['source'] ?? 0) > $bestScore) {
                        $bestScore = $scores['source'];
                        $bestIdx = $idx;
                    }
                }
                if ($bestIdx !== null && $bestScore >= 0.70) {
                    $mapping['source'] = $bestIdx;
                    $used[$bestIdx] = true;
                }
            }
        }

        return $mapping;
    }


    private function looksLikeSourceValue(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 50) return false;

        $configured = [];
        try {
            $configured = $this->settings->sources()->pluck('key')->map(fn ($key) => strtolower(trim((string) $key)))->all();
        } catch (\Throwable) {
            // Source settings are advisory for auto-detection; never make import
            // mapping fail merely because settings are unavailable.
        }

        $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $value)));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        foreach ($configured as $key) {
            if ($key !== '' && $normalized === strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $key)))) return true;
        }

        // Generic categorical source/channel values commonly found in exports.
        $tokens = ['facebook', 'instagram', 'google', 'website', 'web', 'reference', 'referral', 'walk in', 'walkin', 'whatsapp', 'portal', 'housing', 'magicbricks', '99acres', 'linkedin', 'youtube', 'offline', 'direct', 'manual'];
        return in_array($normalized, $tokens, true);
    }

    private function sourceHeaderScore(string $header): float
    {
        $header = strtolower(trim($header));
        if ($header === '') return 0;
        $header = preg_replace('/[^a-z0-9]+/', '_', $header);
        $strong = ['source', 'lead_source', 'channel', 'origin', 'lead_origin', 'lead_source_name', 'source_name', 'platform', 'medium', 'acquisition_source', 'enquiry_source', 'inquiry_source'];
        return in_array(trim($header, '_'), $strong, true) ? 1.0 : 0.0;
    }

    /* ============================================================
       VALIDATE — split rows into valid / skipped / failed
       ============================================================ */
    public function validateRows(array $rows, array $mapping, bool $skipDuplicates = true): array
    {
        $valid   = [];
        $skipped = [];
        $failed  = [];

        // Preload existing phones for fast duplicate detection
        $existingPhones = $skipDuplicates
            ? Lead::pluck('phone')->map(fn ($p) => preg_replace('/\D/', '', (string) $p))->flip()->all()
            : [];

        // Preload projects by lowercase name
        $projectsByName = Project::pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [strtolower(trim($name)) => $id])
            ->all();

        // Get valid project ids
        $validProjectIds = Project::pluck('id')->all();
        $defaultSource   = $this->settings->sources()->first()?->key ?? 'manual';

        foreach ($rows as $i => $row) {
            $lineNumber = $i + 2; // header is line 1

            $name  = trim((string) ($this->col($row, $mapping, 'name') ?? ''));
            $phone = trim((string) ($this->col($row, $mapping, 'phone') ?? ''));

            if ($name === '') {
                $failed[] = ['row' => $lineNumber, 'reason' => 'Missing customer name', 'data' => $row];
                continue;
            }
            if ($phone === '') {
                $failed[] = ['row' => $lineNumber, 'reason' => 'Missing phone', 'data' => $row];
                continue;
            }

            // Duplicate check
            $phoneClean = preg_replace('/\D/', '', $phone);
            if ($skipDuplicates && isset($existingPhones[$phoneClean])) {
                $skipped[] = ['row' => $lineNumber, 'reason' => 'Duplicate phone', 'data' => $row];
                continue;
            }

            // Project resolution
            $projectNameRaw = trim((string) ($this->col($row, $mapping, 'project') ?? ''));
            $projectId = null;
            if ($projectNameRaw !== '') {
                $projectId = $projectsByName[strtolower($projectNameRaw)] ?? null;
                if (! $projectId && is_numeric($projectNameRaw) && in_array((int) $projectNameRaw, $validProjectIds, true)) {
                    $projectId = (int) $projectNameRaw;
                }
            }
            // If no project mapped or detected, use the first active project (required field)
            if (! $projectId) {
                $projectId = Project::active()->orderBy('id')->value('id');
            }
            if (! $projectId) {
                $failed[] = ['row' => $lineNumber, 'reason' => 'No project found — create one first', 'data' => $row];
                continue;
            }

            // Source resolution (default if missing/unknown)
            $sourceRaw = strtolower(trim((string) ($this->col($row, $mapping, 'source') ?? '')));
            $sourceKey = $this->resolveSourceKey($sourceRaw, $defaultSource);

            // Budget (numeric only)
            $budget = $this->col($row, $mapping, 'budget');
            $budget = is_numeric($budget) ? (float) $budget : null;

            $valid[] = [
                'row'          => $lineNumber,
                'customer_name'=> mb_substr($name, 0, 255),
                'phone'        => mb_substr($phone, 0, 20),
                'phone_clean'  => $phoneClean,
                'email'        => mb_substr(trim((string) ($this->col($row, $mapping, 'email') ?? '')), 0, 255) ?: null,
                'source'       => $sourceKey,
                'budget'       => $budget,
                'project_id'   => $projectId,
                'notes'        => trim((string) ($this->col($row, $mapping, 'notes') ?? '')) ?: null,
            ];
        }

        return compact('valid', 'skipped', 'failed');
    }

    /* ============================================================
       IMPORT — persist the valid rows
       ============================================================ */
    public function import(
        array $valid,
        string $filename,
        int $userId,
        int $totalRows,
        int $skippedCount,
        int $failedCount,
        array $errorLog = []
    ): LeadImportBatch {
        $batch = LeadImportBatch::create([
            'user_id'       => $userId,
            'filename'      => $filename,
            'total_rows'    => $totalRows,
            'skipped_rows'  => $skippedCount,
            'failed_rows'   => $failedCount,
            'imported_rows' => 0,
            'error_log'     => ! empty($errorLog) ? json_encode($errorLog, JSON_PRETTY_PRINT) : null,
        ]);

        $imported = 0;

        DB::transaction(function () use ($valid, $batch, &$imported) {
            foreach ($valid as $row) {
                $lead = Lead::create([
                    'customer_name' => $row['customer_name'],
                    'phone'         => $row['phone'],
                    'email'         => $row['email'],
                    'source'        => $row['source'],
                    'budget'        => $row['budget'],
                    'project_id'    => $row['project_id'],
                    'status'        => 'new',
                    'imported_from_batch_id' => $batch->id,
                ]);

                // Auto-assign using the same rules as the Add Lead form
                $this->assignment->assignLeastLoaded($lead);

                // Optional: append the CSV notes to the first activity
                if (! empty($row['notes'])) {
                    \App\Models\Activity::create([
                    'action_source' => 'automated',
                        'lead_id'     => $lead->id,
                        'agent_id'    => $lead->resolveLoggingAgentId(),
                        'type'        => 'note',
                        'outcome'     => 'Imported',
                        'notes'       => $row['notes'],
                        'logged_at'   => now(),
                    ]);
                }

                $imported++;
            }
        });

        $batch->update(['imported_rows' => $imported]);

        return $batch;
    }

    /* ============================================================
       HELPERS
       ============================================================ */
    private function col(array $row, array $mapping, string $field)
    {
        if (! isset($mapping[$field])) return null;
        $idx = $mapping[$field];
        return $row[$idx] ?? null;
    }

    private function resolveSourceKey(string $raw, string $default): string
    {
        if ($raw === '') return $default;

        $sources = $this->settings->sources(false);

        // Match by key
        if ($sources->firstWhere('key', $raw)) return $raw;

        // Match by label (case-insensitive)
        foreach ($sources as $src) {
            if (strtolower($src->label) === $raw) return $src->key;
        }

        return $default;
    }
}