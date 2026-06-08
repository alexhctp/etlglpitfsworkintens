<?php

use GlpiPlugin\Etlglpitfsworkintens\TicketSynchronizer;

require_once __DIR__ . '/../../../inc/includes.php';
require_once __DIR__ . '/../src/TicketSynchronizer.php';

Session::checkLoginUser();
Session::checkRight('ticket', CREATE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect('import.php');
    exit;
}

$feedback_key = 'plugin_etlglpitfsworkintens_sync_feedback';

try {
    $stats = TicketSynchronizer::syncAll();
    $first_error = '';
    if (!empty($stats['error_samples']) && is_array($stats['error_samples'])) {
        $first_error = ' First error: ' . (string) reset($stats['error_samples']);
    }

    $_SESSION[$feedback_key] = [
        'type'    => $stats['errors'] > 0 ? 'warning' : 'success',
        'message' => sprintf(
            'Ticket sync completed. Created: %d. Updated: %d. Skipped (no change): %d. Errors: %d.%s',
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
            $stats['errors'],
            $first_error
        ),
    ];
} catch (Throwable $e) {
    $_SESSION[$feedback_key] = [
        'type'    => 'danger',
        'message' => 'Sync failed. Reason: ' . $e->getMessage(),
    ];
}

Html::redirect('import.php');
