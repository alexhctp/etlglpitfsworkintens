<?php

namespace GlpiPlugin\Etlglpitfsworkintens;

use Reservation;
use ReservationItem;
use Ticket;

final class ReservationTicketService
{
    private const MAP_TABLE = 'glpi_plugin_etlglpitfsworkintens_reservationtickets';

    public static function syncFromReservationRequest(Reservation $reservation): void
    {
        if (!self::ensureMapTableExists()) {
            return;
        }

        global $DB;

        $reservation_id = (int) ($reservation->fields['id'] ?? 0);
        if ($reservation_id <= 0) {
            return;
        }

        $ticket_id = self::extractTicketIdFromRequest();
        if ($ticket_id <= 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $exists = countElementsInTable(self::MAP_TABLE, ['reservations_id' => $reservation_id]) > 0;

        if ($exists) {
            $DB->update(
                self::MAP_TABLE,
                [
                    'tickets_id' => $ticket_id,
                    'date_mod'   => $now,
                ],
                ['reservations_id' => $reservation_id]
            );
        } else {
            $DB->insert(self::MAP_TABLE, [
                'reservations_id' => $reservation_id,
                'tickets_id'      => $ticket_id,
                'date_creation'   => $now,
                'date_mod'        => $now,
            ]);
        }

        self::ensureTicketHasAssignee($ticket_id, (int) ($reservation->fields['users_id'] ?? 0));
        self::linkTicketToReservedItem($ticket_id, (int) ($reservation->fields['reservationitems_id'] ?? 0));
    }

    public static function getMappedTicketForReservation(int $reservation_id): int
    {
        if (!self::ensureMapTableExists()) {
            return 0;
        }

        global $DB;

        if ($reservation_id <= 0) {
            return 0;
        }

        $row = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => self::MAP_TABLE,
            'WHERE'  => ['reservations_id' => $reservation_id],
            'LIMIT'  => 1,
        ])->current();

        if (!is_array($row)) {
            return 0;
        }

        return (int) ($row['tickets_id'] ?? 0);
    }

    private static function ensureMapTableExists(): bool
    {
        global $DB;

        if (!is_object($DB) || !method_exists($DB, 'tableExists') || !method_exists($DB, 'doQuery')) {
            return false;
        }

        if ($DB->tableExists(self::MAP_TABLE)) {
            return true;
        }

        $default_charset   = \DBConnection::getDefaultCharset();
        $default_collation = \DBConnection::getDefaultCollation();
        $default_key_sign  = \DBConnection::getDefaultPrimaryKeySignOption();

        $DB->doQuery("\n            CREATE TABLE `" . self::MAP_TABLE . "` (\n                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,\n                `reservations_id` int {$default_key_sign} NOT NULL DEFAULT '0',\n                `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',\n                `date_creation` datetime DEFAULT NULL,\n                `date_mod` datetime DEFAULT NULL,\n                PRIMARY KEY (`id`),\n                UNIQUE KEY `uniq_reservation` (`reservations_id`),\n                KEY `tickets_id` (`tickets_id`)\n            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset}\n              COLLATE={$default_collation} ROW_FORMAT=DYNAMIC\n        ");

        return $DB->tableExists(self::MAP_TABLE);
    }

    private static function extractTicketIdFromRequest(): int
    {
        $raw = $_POST['etlglpitfsworkintens_tickets_id'] ?? $_REQUEST['etlglpitfsworkintens_tickets_id'] ?? 0;
        if (is_array($raw)) {
            return 0;
        }

        $ticket_id = (int) $raw;
        if ($ticket_id <= 0) {
            return 0;
        }

        if (countElementsInTable('glpi_tickets', ['id' => $ticket_id]) <= 0) {
            return 0;
        }

        return $ticket_id;
    }

    private static function ensureTicketHasAssignee(int $ticket_id, int $users_id): void
    {
        if ($ticket_id <= 0 || $users_id <= 0) {
            return;
        }

        $has_assignee = countElementsInTable('glpi_tickets_users', [
            'tickets_id' => $ticket_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]) > 0;

        if ($has_assignee) {
            return;
        }

        $ticket = new Ticket();
        if ($ticket->getFromDB($ticket_id)) {
            $ticket->update([
                'id'               => $ticket_id,
                '_users_id_assign' => $users_id,
            ]);
        }
    }

    private static function linkTicketToReservedItem(int $ticket_id, int $reservationitems_id): void
    {
        global $DB;

        if ($ticket_id <= 0 || $reservationitems_id <= 0) {
            return;
        }

        $reservation_item = new ReservationItem();
        if (!$reservation_item->getFromDB($reservationitems_id)) {
            return;
        }

        $itemtype = (string) ($reservation_item->fields['itemtype'] ?? '');
        $items_id = (int) ($reservation_item->fields['items_id'] ?? 0);
        if ($itemtype === '' || $items_id <= 0) {
            return;
        }

        $already_linked = countElementsInTable('glpi_items_tickets', [
            'tickets_id' => $ticket_id,
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
        ]) > 0;

        if ($already_linked) {
            return;
        }

        $DB->insert('glpi_items_tickets', [
            'tickets_id' => $ticket_id,
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
        ]);
    }
}
