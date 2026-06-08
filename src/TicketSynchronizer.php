<?php

/**
 * -------------------------------------------------------------------------
 * ETL GLPI TFS Work Intens plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the ETL GLPI TFS Work Intens plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/alexhctp/etl_glpi_tfs_work_itens.git
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Etlglpitfsworkintens;

use CommonITILObject;
use RuntimeException;
use Throwable;
use Ticket;

final class TicketSynchronizer
{
    private const TABLE = 'glpi_tfs_work_itens';

    /**
     * Maps TFS state (lowercase) to GLPI ticket status constant.
     *
     * INCOMING = 1  (New)
     * ASSIGNED  = 2  (Processing assigned)
     * PLANNED   = 3  (Processing planned)
     * WAITING   = 4  (Pending)
     * SOLVED    = 5
     * CLOSED    = 6
     */
    private const STATUS_MAP = [
        'new'                     => CommonITILObject::INCOMING,
        'ready for classification' => CommonITILObject::INCOMING,
        'approved'                => CommonITILObject::PLANNED,
        'design'                  => CommonITILObject::PLANNED,
        'committed'               => CommonITILObject::ASSIGNED,
        'to do'                   => CommonITILObject::WAITING,
        'done'                    => CommonITILObject::SOLVED,
        'finished'                => CommonITILObject::SOLVED,
        'fixed'                   => CommonITILObject::SOLVED,
        'closed'                  => CommonITILObject::CLOSED,
        'removed'                 => CommonITILObject::CLOSED,
    ];

    /**
     * Synchronize all work items with GLPI tickets.
     *
     * @return array{created:int, updated:int, skipped:int, errors:int, error_samples:array<int,string>}
     */
    public static function syncAll(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            throw new RuntimeException('Table glpi_tfs_work_itens does not exist. Install or reactivate the plugin.');
        }

        self::ensureSyncColumnsExist();

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'error_samples' => []];

        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'ORDER' => ['work_item_id ASC'],
        ]);

        foreach ($iterator as $row) {
            try {
                $result = self::syncOne($row);
                $stats[$result]++;
            } catch (Throwable $e) {
                $stats['errors']++;
                if (count($stats['error_samples']) < 3) {
                    $work_item = (int) ($row['work_item_id'] ?? 0);
                    $stats['error_samples'][] = sprintf('Work item %d: %s', $work_item, $e->getMessage());
                }
            }
        }

        return $stats;
    }

    /**
     * Synchronize a single work item row with a GLPI ticket.
     *
     * @param array<string,mixed> $row  Full row from glpi_tfs_work_itens
     * @return 'created'|'updated'|'skipped'
     */
    public static function syncOne(array $row): string
    {
        global $DB;

        $glpi_status  = self::mapStatus((string) ($row['state'] ?? ''));
        $work_item_id = (int) $row['work_item_id'];
        $db_row_id    = (int) $row['id'];
        $created_date = self::normalizeDatetimeValue($row['created_date'] ?? null);
        $closed_date  = self::normalizeDatetimeValue($row['closed_date'] ?? null);

        // If a link exists and ticket still exists in DB, never create a new one.
        $linked_ticket_id = (int) ($row['tickets_id'] ?? 0);
        if ($linked_ticket_id > 0 && self::ticketExistsInDb($linked_ticket_id)) {
            $ticket = new Ticket();
            if ($ticket->getFromDB($linked_ticket_id)) {
                $update_data = ['id' => $linked_ticket_id];
                $has_changes = false;

                if ((int) $ticket->fields['status'] !== $glpi_status) {
                    $update_data['status'] = $glpi_status;
                    $has_changes = true;
                }

                if (
                    $closed_date !== null
                    && in_array($glpi_status, [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)
                ) {
                    if ((string) ($ticket->fields['solvedate'] ?? '') !== $closed_date) {
                        $update_data['solvedate'] = $closed_date;
                        $has_changes = true;
                    }

                    if ($glpi_status === CommonITILObject::CLOSED && (string) ($ticket->fields['closedate'] ?? '') !== $closed_date) {
                        $update_data['closedate'] = $closed_date;
                        $has_changes = true;
                    }
                }

                if ($has_changes) {
                    $ticket->update($update_data);

                    return 'updated';
                }

                return 'skipped';
            }

            // Ticket exists but is not readable in current context; avoid duplicate creation.
            return 'skipped';
        }

        // Recover link if there is already a ticket for this work item.
        $recovered_ticket_id = self::findExistingTicketIdByWorkItem($work_item_id);
        if ($recovered_ticket_id > 0) {
            $DB->update(self::TABLE, ['tickets_id' => $recovered_ticket_id], ['id' => $db_row_id]);
            return 'updated';
        }

        // Build ticket title and description from work item fields.
        $ticket_name = sprintf('[TFS #%d] %s', $work_item_id, trim((string) ($row['title'] ?? '')));
        $ticket_name = mb_substr($ticket_name, 0, 255, 'UTF-8');

        $content_parts = [
            'TFS Work Item ID : ' . $work_item_id,
            'Type             : ' . (string) ($row['work_item_type'] ?? ''),
            'Assigned To      : ' . (string) ($row['assigned_to'] ?? ''),
            'State            : ' . (string) ($row['state'] ?? ''),
        ];
        if (!empty($row['tags'])) {
            $content_parts[] = 'Tags             : ' . (string) $row['tags'];
        }
        if ($created_date !== null) {
            $content_parts[] = 'Created Date     : ' . $created_date;
        }
        if ($closed_date !== null) {
            $content_parts[] = 'Closed Date      : ' . $closed_date;
        }

        $ticket_data = [
            'name'        => $ticket_name,
            'content'     => implode("\n", $content_parts),
            'status'      => $glpi_status,
            'type'        => Ticket::DEMAND_TYPE,
            'entities_id' => (int) \Session::getActiveEntity(),
        ];

        $requester_id = (int) \Session::getLoginUserID();
        if ($requester_id > 0) {
            $ticket_data['_users_id_requester'] = $requester_id;
        }

        if ($created_date !== null) {
            $ticket_data['date'] = $created_date;
        }

        if ($closed_date !== null && in_array($glpi_status, [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)) {
            $ticket_data['solvedate'] = $closed_date;

            if ($glpi_status === CommonITILObject::CLOSED) {
                $ticket_data['closedate'] = $closed_date;
            }
        }

        $ticket  = new Ticket();
        $new_id  = $ticket->add($ticket_data);

        if (!$new_id) {
            throw new RuntimeException(
                sprintf('Failed to create ticket for TFS work item %d.', $work_item_id)
            );
        }

        // Store the link back in glpi_tfs_work_itens.
        $DB->update(self::TABLE, ['tickets_id' => (int) $new_id], ['id' => $db_row_id]);

        return 'created';
    }

    /**
     * Translate a TFS state string to a GLPI ticket status constant.
     */
    private static function mapStatus(string $tfs_state): int
    {
        $key = strtolower(trim($tfs_state));
        return self::STATUS_MAP[$key] ?? CommonITILObject::INCOMING;
    }

    private static function normalizeDatetimeValue($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
    }

    private static function ensureSyncColumnsExist(): void
    {
        global $DB;

        if (!$DB->fieldExists(self::TABLE, 'tickets_id')) {
            $default_key_sign = \DBConnection::getDefaultPrimaryKeySignOption();
            $DB->doQuery(
                "ALTER TABLE `" . self::TABLE . "`"
                . " ADD COLUMN `tickets_id` int " . $default_key_sign . " NOT NULL DEFAULT '0' AFTER `state`,"
                . " ADD KEY `tickets_id` (`tickets_id`)"
            );
        }
    }

    private static function ticketExistsInDb(int $ticket_id): bool
    {
        return $ticket_id > 0 && countElementsInTable('glpi_tickets', ['id' => $ticket_id]) > 0;
    }

    private static function findExistingTicketIdByWorkItem(int $work_item_id): int
    {
        global $DB;

        if ($work_item_id <= 0) {
            return 0;
        }

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => [
                'name' => ['LIKE', sprintf('[TFS #%d] %%', $work_item_id)],
            ],
            'ORDER'  => ['id ASC'],
            'LIMIT'  => 1,
        ]);

        $row = $iterator->current();
        if (!is_array($row)) {
            return 0;
        }

        return (int) ($row['id'] ?? 0);
    }
}
