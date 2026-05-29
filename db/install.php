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
 * Install-time bootstrap for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bootstrap the HMAC session_secret on plugin install. Bare-name callback
 * per Moodle activity-module convention (rule M1).
 *
 * @return void
 */
function xmldb_fastpix_install() {
    if (empty(get_config('mod_fastpix', 'session_secret'))) {
        set_config('session_secret', bin2hex(random_bytes(32)), 'mod_fastpix');
    }
}
