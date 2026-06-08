<?php

namespace GlpiPlugin\Etlglpitfsworkintens;

use RuntimeException;
use Throwable;

final class WorkItemCsvImporter
{
    private const TABLE = 'glpi_tfs_work_itens';

    /**
     * @return array{
     *     processed:int,
     *     inserted:int,
     *     skipped_existing:int,
     *     skipped_duplicate_in_file:int,
     *     skipped_invalid:int
     * }
     */
    public static function importFromUploadedCsv(string $filePath): array
    {
        global $DB;

        if (!is_readable($filePath)) {
            throw new RuntimeException('The uploaded CSV file cannot be read.');
        }

        if (!$DB->tableExists(self::TABLE)) {
            throw new RuntimeException('Table glpi_tfs_work_itens does not exist. Install or reactivate the plugin.');
        }

        self::ensureDateColumnsExist();

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Failed to open the uploaded CSV file.');
        }

        try {
            $delimiter = self::detectDelimiter($handle);

            $header = fgetcsv($handle, 0, $delimiter);
            if (!is_array($header)) {
                throw new RuntimeException('The CSV file is empty.');
            }

            $header_map = self::buildHeaderMap($header);
            $required_columns = ['id', 'workitemtype', 'title', 'assignedto', 'state', 'tags', 'createddate', 'closeddate'];
            foreach ($required_columns as $column) {
                if (!array_key_exists($column, $header_map)) {
                    throw new RuntimeException('Invalid CSV header. Expected columns: ID, Work Item Type, Title, Assigned To, State, Tags, Created Date, Closed Date.');
                }
            }

            $rows = [];
            $stats = [
                'processed'                 => 0,
                'inserted'                  => 0,
                'skipped_existing'          => 0,
                'skipped_duplicate_in_file' => 0,
                'skipped_invalid'           => 0,
            ];

            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($data === [null] || $data === false) {
                    continue;
                }

                $stats['processed']++;

                $raw_id = trim((string) ($data[$header_map['id']] ?? ''), "\" \t\n\r\0\x0B");
                if ($raw_id === '') {
                    $stats['skipped_invalid']++;
                    continue;
                }

                // Common Excel export format stores integer IDs as floating point text (e.g. 340458.0).
                if (preg_match('/^\d+\.0+$/', $raw_id) === 1) {
                    $raw_id = strstr($raw_id, '.', true) ?: $raw_id;
                }

                if (!ctype_digit($raw_id)) {
                    $stats['skipped_invalid']++;
                    continue;
                }

                $work_item_id = (string) ((int) $raw_id);

                $created_date_raw = self::normalizeField((string) ($data[$header_map['createddate']] ?? ''));
                $closed_date_raw = self::normalizeField((string) ($data[$header_map['closeddate']] ?? ''));

                $created_date = self::parseTfsDate($created_date_raw);
                $closed_date = self::parseTfsDate($closed_date_raw);

                if (($created_date_raw !== '' && $created_date === null) || ($closed_date_raw !== '' && $closed_date === null)) {
                    $stats['skipped_invalid']++;
                    continue;
                }

                if (isset($rows[$work_item_id])) {
                    $stats['skipped_duplicate_in_file']++;
                    continue;
                }

                $rows[$work_item_id] = [
                    'work_item_id'   => (int) $work_item_id,
                    'work_item_type' => self::normalizeField((string) ($data[$header_map['workitemtype']] ?? '')),
                    'title'          => self::truncate(self::normalizeField((string) ($data[$header_map['title']] ?? '')), 255),
                    'assigned_to'    => self::truncate(self::normalizeField((string) ($data[$header_map['assignedto']] ?? '')), 255),
                    'state'          => self::truncate(self::normalizeField((string) ($data[$header_map['state']] ?? '')), 255),
                    'tags'           => self::normalizeField((string) ($data[$header_map['tags']] ?? '')),
                    'created_date'   => $created_date,
                    'closed_date'    => $closed_date,
                ];
            }

            if ($rows === []) {
                return $stats;
            }

            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row_id => $row) {
                try {
                    $inserted = $DB->insert(self::TABLE, $row + [
                        'date_creation' => $now,
                        'date_mod'      => $now,
                    ]);

                    if ($inserted === false) {
                        $already_exists = countElementsInTable(self::TABLE, ['work_item_id' => (int) $row_id]) > 0;
                        if ($already_exists) {
                            $DB->update(
                                self::TABLE,
                                [
                                    'work_item_type' => $row['work_item_type'],
                                    'title'          => $row['title'],
                                    'assigned_to'    => $row['assigned_to'],
                                    'state'          => $row['state'],
                                    'tags'           => $row['tags'],
                                    'created_date'   => $row['created_date'],
                                    'closed_date'    => $row['closed_date'],
                                    'date_mod'       => $now,
                                ],
                                ['work_item_id' => (int) $row_id]
                            );

                            try {
                                $synced_row = $DB->request([
                                    'FROM'  => self::TABLE,
                                    'WHERE' => ['work_item_id' => (int) $row_id],
                                    'LIMIT' => 1,
                                ])->current();

                                if (is_array($synced_row)) {
                                    TicketSynchronizer::syncOne($synced_row);
                                }
                            } catch (Throwable $sync_exception) {
                                // Ticket sync failure must not abort the CSV import.
                            }

                            $stats['skipped_existing']++;
                            continue;
                        }

                        throw new RuntimeException(sprintf('Failed to insert work item %s into table glpi_tfs_work_itens.', $row_id));
                    }

                    $stats['inserted']++;

                    // Create or update ticket for the newly inserted work item.
                    try {
                        $synced_row = $DB->request([
                            'FROM'  => self::TABLE,
                            'WHERE' => ['work_item_id' => (int) $row_id],
                            'LIMIT' => 1,
                        ])->current();

                        if (is_array($synced_row)) {
                            TicketSynchronizer::syncOne($synced_row);
                        }
                    } catch (Throwable $sync_exception) {
                        // Ticket sync failure must not abort the CSV import.
                    }
                } catch (Throwable $exception) {
                    if (self::isDuplicateKeyError($exception)) {
                        $stats['skipped_existing']++;
                        continue;
                    }

                    throw $exception;
                }
            }

            return $stats;
        } finally {
            fclose($handle);
        }
    }

    private static function normalizeField(string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    private static function detectDelimiter($handle): string
    {
        $first_line = fgets($handle);
        if ($first_line === false) {
            return ',';
        }

        $candidates = [',', ';', "\t"];
        $best = ',';
        $best_count = -1;
        foreach ($candidates as $candidate) {
            $count = substr_count($first_line, $candidate);
            if ($count > $best_count) {
                $best_count = $count;
                $best = $candidate;
            }
        }

        rewind($handle);
        return $best;
    }

    /**
     * @param array<int, mixed> $header
     * @return array<string, int>
     */
    private static function buildHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $normalized = self::normalizeHeaderName((string) $name);
            if ($normalized !== '' && !array_key_exists($normalized, $map)) {
                $map[$normalized] = (int) $index;
            }
        }

        return $map;
    }

    private static function normalizeHeaderName(string $name): string
    {
        $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
        $name = strtolower(trim($name));
        return preg_replace('/[^a-z0-9]/', '', $name) ?? '';
    }

    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value, 'UTF-8') <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    private static function parseTfsDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = [
            'n/j/Y g:i:s A',
            'm/d/Y h:i:s A',
            'n/j/Y g:i A',
            'm/d/Y h:i A',
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date instanceof \DateTime) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private static function ensureDateColumnsExist(): void
    {
        global $DB;

        if (!$DB->fieldExists(self::TABLE, 'created_date')) {
            $DB->doQuery(
                "ALTER TABLE `" . self::TABLE . "` ADD COLUMN `created_date` datetime DEFAULT NULL AFTER `tags`"
            );
        }

        if (!$DB->fieldExists(self::TABLE, 'closed_date')) {
            $DB->doQuery(
                "ALTER TABLE `" . self::TABLE . "` ADD COLUMN `closed_date` datetime DEFAULT NULL AFTER `created_date`"
            );
        }
    }

    private static function isDuplicateKeyError(Throwable $exception): bool
    {
        if ((int) $exception->getCode() === 1062) {
            return true;
        }

        $message = (string) $exception->getMessage();
        return stripos($message, 'Duplicate entry') !== false
            && strpos($message, '1062') !== false;
    }
}