<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Version metadata for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'mod_fastpix';
$plugin->version      = 2026061010;
$plugin->requires     = 2024100100;
$plugin->maturity     = MATURITY_STABLE;
$plugin->release      = '1.1.0';
$plugin->dependencies = [
    // Pin to the local_fastpix 1.1.0 build (>= 2026061010). The new code uses
    // surfaces introduced there: asset_service::add_reference / release_reference
    // (asset reference counting) and create_upload_session's course-context +
    // title / accesspolicy / captionsmode / languagecode parameter contract
    // (with the uploadurl / uploadid return shape). An older local_fastpix lacks
    // these, so it must not be installed underneath.
    'local_fastpix' => 2026061010,
];
