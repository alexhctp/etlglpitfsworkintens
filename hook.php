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
 * @link      https://github.com/pluginsGLPI/etlglpitfsworkintens
 * -------------------------------------------------------------------------
 */

/**
 * Plugin install process
 */
function plugin_etlglpitfsworkintens_install(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_tfs_work_itens')) {
        $DB->doQuery("
            CREATE TABLE `glpi_tfs_work_itens` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `work_item_id` int NOT NULL DEFAULT '0',
                `work_item_type` varchar(255) NOT NULL DEFAULT '',
                `title` varchar(255) NOT NULL DEFAULT '',
                `assigned_to` varchar(255) NOT NULL DEFAULT '',
                `state` varchar(255) NOT NULL DEFAULT '',
                `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `tags` text DEFAULT NULL,
                `created_date` datetime DEFAULT NULL,
                `closed_date` datetime DEFAULT NULL,
                `date_creation` datetime DEFAULT NULL,
                `date_mod` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_work_item_id` (`work_item_id`),
                KEY `state` (`state`),
                KEY `work_item_type` (`work_item_type`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset}
              COLLATE={$default_collation} ROW_FORMAT=DYNAMIC
        ");
    } elseif (!$DB->fieldExists('glpi_tfs_work_itens', 'tickets_id')) {
        $DB->doQuery("
            ALTER TABLE `glpi_tfs_work_itens`
                ADD COLUMN `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0' AFTER `state`,
                ADD KEY `tickets_id` (`tickets_id`)
        ");
    }

    if ($DB->tableExists('glpi_tfs_work_itens') && !$DB->fieldExists('glpi_tfs_work_itens', 'created_date')) {
        $DB->doQuery("
            ALTER TABLE `glpi_tfs_work_itens`
                ADD COLUMN `created_date` datetime DEFAULT NULL AFTER `tags`
        ");
    }

    if ($DB->tableExists('glpi_tfs_work_itens') && !$DB->fieldExists('glpi_tfs_work_itens', 'closed_date')) {
        $DB->doQuery("
            ALTER TABLE `glpi_tfs_work_itens`
                ADD COLUMN `closed_date` datetime DEFAULT NULL AFTER `created_date`
        ");
    }

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_etlglpitfsworkintens_uninstall(): bool
{
    return true;
}
