<?php

use GlpiPlugin\Etlglpitfsworkintens\WorkItemCsvImporter;

require_once __DIR__ . '/../../../inc/includes.php';
require_once __DIR__ . '/../src/WorkItemCsvImporter.php';

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$title = 'TFS Work Items Import';
$feedback_session_key = 'plugin_etlglpitfsworkintens_import_feedback';

$toBytes = static function (string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
};

$getUploadErrorMessage = static function (int $errorCode): string {
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE => sprintf(
            'The uploaded file exceeds upload_max_filesize (%s).',
            (string) ini_get('upload_max_filesize')
        ),
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum size allowed by the form.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No file was selected for upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        default => 'Unknown upload error.',
    };
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMaxSize = (string) ini_get('post_max_size');
        $postMaxBytes = $toBytes($postMaxSize);
        if ($postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_FILES)) {
            throw new RuntimeException(sprintf(
                'Request body exceeds post_max_size (%s). Reduce file size or increase server limits.',
                $postMaxSize
            ));
        }

        if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
            throw new RuntimeException('No CSV file was uploaded.');
        }

        $upload = $_FILES['csv_file'];
        $error_code = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error_code !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf(
                '%s (error code: %d)',
                $getUploadErrorMessage($error_code),
                $error_code
            ));
        }

        $original_name = (string) ($upload['name'] ?? '');
        if (strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('The uploaded file must be in CSV format.');
        }

        $tmp_name = (string) ($upload['tmp_name'] ?? '');
        if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
            throw new RuntimeException('Upload failed: temporary file not found. Please try again.');
        }

        $stats = WorkItemCsvImporter::importFromUploadedCsv($tmp_name);

        $summary = sprintf(
            'Processed: %d. Inserted: %d. Already existing: %d. Duplicates in file: %d. Invalid: %d.',
            $stats['processed'],
            $stats['inserted'],
            $stats['skipped_existing'],
            $stats['skipped_duplicate_in_file'],
            $stats['skipped_invalid']
        );

        $highlights = sprintf(
            '%d new records inserted. %d duplicates skipped (already in database).',
            (int) $stats['inserted'],
            (int) $stats['skipped_existing']
        );

        if ((int) $stats['inserted'] > 0) {
            $feedback = [
                'type'    => 'success',
                'message' => 'Upload completed successfully. ' . $highlights . ' ' . $summary,
            ];
        } else {
            $feedback = [
                'type'    => 'warning',
                'message' => 'Upload completed successfully. ' . $highlights . ' ' . $summary,
            ];
        }

        $_SESSION[$feedback_session_key] = $feedback;

        Session::addMessageAfterRedirect(
            $feedback['message'],
            false,
            INFO
        );
    } catch (Throwable $exception) {
        $reason = trim((string) $exception->getMessage());

        if ($reason === '') {
            $reason = sprintf('%s (code %d)', get_class($exception), (int) $exception->getCode());
        }

        $_SESSION[$feedback_session_key] = [
            'type'    => 'danger',
            'message' => 'Upload failed. Reason: ' . $reason,
        ];

        Session::addMessageAfterRedirect('Upload failed. Reason: ' . $reason, false, ERROR);
    }

    Html::redirect($_SERVER['REQUEST_URI']);
}

Html::header($title, '', 'config', 'plugins');

$feedback = $_SESSION[$feedback_session_key] ?? null;
if (is_array($feedback) && isset($feedback['type'], $feedback['message'])) {
    unset($_SESSION[$feedback_session_key]);
    echo "<div class='alert alert-" . htmlescape((string) $feedback['type']) . " mb-3'>" . htmlescape((string) $feedback['message']) . "</div>";
}

$sync_feedback_key = 'plugin_etlglpitfsworkintens_sync_feedback';
$sync_feedback = $_SESSION[$sync_feedback_key] ?? null;
if (is_array($sync_feedback) && isset($sync_feedback['type'], $sync_feedback['message'])) {
    unset($_SESSION[$sync_feedback_key]);
    echo "<div class='alert alert-" . htmlescape((string) $sync_feedback['type']) . " mb-3'>" . htmlescape((string) $sync_feedback['message']) . "</div>";
}

echo "<div class='card'>";
echo "  <div class='card-header'><h3 class='card-title mb-0'>" . htmlescape($title) . "</h3></div>";
echo "  <div class='card-body'>";
echo "    <p class='mb-3'>Upload a new CSV file version to compare against table glpi_tfs_work_itens and insert only work items that do not exist yet.</p>";
echo "    <form method='post' action='' enctype='multipart/form-data' class='row g-3 align-items-end'>";
echo "      <input type='hidden' name='_glpi_csrf_token' value='" . htmlescape(Session::getNewCSRFToken()) . "'>";
echo "      <div class='col-12 col-md-8'>";
echo "        <label class='form-label' for='csv_file'>CSV File</label>";
echo "        <input class='form-control' type='file' id='csv_file' name='csv_file' accept='.csv,text/csv' required>";
echo "      </div>";
echo "      <div class='col-12 col-md-4'>";
echo "        <button class='btn btn-primary' type='submit'><i class='ti ti-upload me-1'></i>Import CSV</button>";
echo "      </div>";
echo "    </form>";
echo "    <div class='alert alert-secondary mt-3 mb-0'>";
echo "      <strong>Expected format:</strong> ID, Work Item Type, Title, Assigned To, State, Tags, Created Date, Closed Date";
echo "      <br><strong>Date format:</strong> mm/dd/yyyy HH:MM:SS AM/PM (example: 5/19/2026 10:06:48 AM)";
echo "      <br><strong>Server upload limits:</strong> upload_max_filesize=" . htmlescape((string) ini_get('upload_max_filesize')) . ", post_max_size=" . htmlescape((string) ini_get('post_max_size'));
echo "    </div>";
echo "  </div>";
echo "</div>";

echo "<div class='card mt-3'>";
echo "  <div class='card-header'><h3 class='card-title mb-0'>Ticket Synchronization</h3></div>";
echo "  <div class='card-body'>";
echo "    <p class='mb-3'>Scan all work items and create or update linked tickets based on their current TFS state. New items get a new ticket; existing tickets have their status updated when the TFS state has changed.</p>";
echo "    <form method='post' action='sync_tickets.php'>";
echo "      <input type='hidden' name='_glpi_csrf_token' value='" . htmlescape(Session::getNewCSRFToken()) . "'>";
echo "      <button class='btn btn-secondary' type='submit'><i class='ti ti-refresh me-1'></i>Sync all tickets now</button>";
echo "    </form>";
echo "  </div>";
echo "</div>";

Html::footer();