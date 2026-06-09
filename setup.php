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

use Glpi\Plugin\Hooks;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_ETLGLPITFSWORKINTENS_VERSION', '1.0.0');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_ETLGLPITFSWORKINTENS_MIN_GLPI_VERSION", "11.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_ETLGLPITFSWORKINTENS_MAX_GLPI_VERSION", "11.0.99");

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_etlglpitfsworkintens(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['etlglpitfsworkintens'] = true;

    if (Plugin::isPluginActive('etlglpitfsworkintens')) {
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['etlglpitfsworkintens'][] = 'public/js/reservation-ticket-dropdown.js';
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['etlglpitfsworkintens'][Reservation::class] = 'plugin_etlglpitfsworkintens_item_add_reservation';
        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['etlglpitfsworkintens'][Reservation::class] = 'plugin_etlglpitfsworkintens_item_update_reservation';

        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['etlglpitfsworkintens'] = 'front/import.php';
        }
    }
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_etlglpitfsworkintens(): array
{
    return [
        'name'           => 'ETL GLPI TFS Work Intens',
        'version'        => PLUGIN_ETLGLPITFSWORKINTENS_VERSION,
        'author'         => 'alexhctp',
        'license'        => 'MIT license',
        'homepage'       => 'https://github.com/alexhctp/etl_glpi_tfs_work_itens.git',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_ETLGLPITFSWORKINTENS_MIN_GLPI_VERSION,
                'max' => PLUGIN_ETLGLPITFSWORKINTENS_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_etlglpitfsworkintens_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_etlglpitfsworkintens_check_config(bool $verbose = false): bool
{
    // Your configuration check
    return true;

    // Example:
    // if ($verbose) {
    //    echo __('Installed / not configured', 'etlglpitfsworkintens');
    // }
    // return false;
}
