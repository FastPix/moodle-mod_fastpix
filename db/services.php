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
 * External web service function definitions for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The db/services.php file loads at install/upgrade time, before lang files are
// available — descriptions stay as literal English here. For translators:
// see lang/en/fastpix.php (web-service description strings live there if
// future tooling supports lookup).
$functions = [
    'mod_fastpix_refresh_playback_token' => [
        'classname'    => '\mod_fastpix\external\refresh_playback_token',
        'methodname'   => 'execute',
        'description'  => 'Mint a fresh playback JWT before the current one expires.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:view',
    ],
    'mod_fastpix_get_player_state' => [
        'classname'    => '\mod_fastpix\external\get_player_state',
        'methodname'   => 'execute',
        // Declared 'write': resolving a ready asset creates the per-user attempt
        // row (and mints its session token) via get_or_create_attempt, so this
        // mutates state and must route to the primary DB on clustered installs.
        'description'  => 'Resolve the current player/processing/error state for an activity.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:view',
    ],
    'mod_fastpix_record_view_progress' => [
        'classname'    => '\mod_fastpix\external\record_view_progress',
        'methodname'   => 'execute',
        'description'  => 'Persist client-reported watch progress with server-side fraud checks.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:view',
    ],
];

// The mod_fastpix plugin does not define its own service group; functions hook into Moodle's mobile + REST services.
$services = [];
