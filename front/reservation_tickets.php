<?php

use GlpiPlugin\Etlglpitfsworkintens\ReservationTicketService;

require_once __DIR__ . '/../../../inc/includes.php';
require_once __DIR__ . '/../src/ReservationTicketService.php';

Session::checkLoginUser();

global $DB;

header('Content-Type: application/json; charset=UTF-8');

$selected_ticket_id = 0;
$reservation_id = (int) ($_GET['reservation_id'] ?? 0);
if ($reservation_id > 0) {
    $selected_ticket_id = ReservationTicketService::getMappedTicketForReservation($reservation_id);
}

$allowed_statuses = [
    CommonITILObject::INCOMING,
    CommonITILObject::WAITING,
    CommonITILObject::ASSIGNED,
    CommonITILObject::PLANNED,
];

$rows = [];
$iterator = $DB->request([
    'SELECT' => ['id', 'name', 'status'],
    'FROM'   => 'glpi_tickets',
    'WHERE'  => [
        'is_deleted' => 0,
        'status'     => $allowed_statuses,
    ],
    'ORDER'  => ['id DESC'],
    'LIMIT'  => 500,
]);

foreach ($iterator as $row) {
    $rows[] = [
        'id'     => (int) $row['id'],
        'name'   => (string) ($row['name'] ?? ''),
        'status' => (int) ($row['status'] ?? 0),
    ];
}

echo json_encode([
    'success'            => true,
    'selected_ticket_id' => $selected_ticket_id,
    'tickets'            => $rows,
], JSON_UNESCAPED_UNICODE);
