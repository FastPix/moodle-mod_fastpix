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
 * Upgrade steps for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run the FastPix activity module upgrade steps.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success.
 */
function xmldb_fastpix_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026050801) {
        // ADR-012: register mod/fastpix:uploadmedia. The capability declaration
        // lives in db/access.php; Moodle's access scanner picks it up on upgrade.
        // No schema change required.
        upgrade_mod_savepoint(true, 2026050801, 'fastpix');
    }

    if ($oldversion < 2026050802) {
        // Phase C: bootstrap session_secret if missing on existing installs.
        // No schema change. db/install.php handles fresh installs.
        if (empty(get_config('mod_fastpix', 'session_secret'))) {
            set_config('session_secret', bin2hex(random_bytes(32)), 'mod_fastpix');
        }
        upgrade_mod_savepoint(true, 2026050802, 'fastpix');
    }

    if ($oldversion < 2026051300) {
        // Phase D Slice A Step 1 — switch fastpix_attempt from scalar
        // watched_seconds to interval-set + resume position + sticky
        // completion flag.
        $table = new xmldb_table('fastpix_attempt');

        $fieldold = new xmldb_field('watched_seconds');
        if ($dbman->field_exists($table, $fieldold)) {
            $dbman->drop_field($table, $fieldold);
        }

        // The watched_intervals column — Moodle DDL forbids defaults on TEXT columns, so
        // adding it as NOT NULL to a non-empty table fails. Standard 3-step:
        // add nullable, backfill, promote to NOT NULL.
        $fintervalsnullable = new xmldb_field('watched_intervals', XMLDB_TYPE_TEXT, null, null, null, null, null, 'seek_count');
        if (!$dbman->field_exists($table, $fintervalsnullable)) {
            $dbman->add_field($table, $fintervalsnullable);
            $DB->execute("UPDATE {fastpix_attempt} SET watched_intervals = '' WHERE watched_intervals IS NULL");
            $fintervalsnotnull = new xmldb_field(
                'watched_intervals',
                XMLDB_TYPE_TEXT,
                null,
                null,
                XMLDB_NOTNULL,
                null,
                null,
                'seek_count'
            );
            $dbman->change_field_notnull($table, $fintervalsnotnull);
        }

        // Numeric columns: DEFAULT in-schema is supported, no 3-step needed.
        $numericfields = [
            new xmldb_field('current_position', XMLDB_TYPE_NUMBER, '10,3', null, XMLDB_NOTNULL, null, '0', 'watched_intervals'),
            new xmldb_field('has_completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'current_position'),
        ];
        foreach ($numericfields as $f) {
            if (!$dbman->field_exists($table, $f)) {
                $dbman->add_field($table, $f);
            }
        }

        upgrade_mod_savepoint(true, 2026051300, 'fastpix');
    }

    if ($oldversion < 2026051301) {
        // Phase D Slice A Step 3 — registers the mod_fastpix_record_view_progress
        // web service. Service registration is picked up from db/services.php by
        // Moodle's upgrade machinery; no schema change required here.
        upgrade_mod_savepoint(true, 2026051301, 'fastpix');
    }

    if ($oldversion < 2026051302) {
        // Phase D Slice A Step 4 — custom_completion class + grade_item_update /
        // update_grades callback bodies. No schema change; the savepoint is
        // here so Moodle picks up cm_info / customdata cache refreshes that
        // the new fastpix_get_coursemodule_info() callback now populates.
        upgrade_mod_savepoint(true, 2026051302, 'fastpix');
    }

    if ($oldversion < 2026060801) {
        // Media settings — access policy + caption source columns on mdl_fastpix.
        // Numeric/char DEFAULTs are supported in-schema, so a single add_field
        // each (guarded by field_exists for idempotency) is enough.
        $table = new xmldb_table('fastpix');

        $fields = [
            new xmldb_field('access_policy', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'private', 'default_show_captions'),
            new xmldb_field('captions_mode', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, 'none', 'access_policy'),
            new xmldb_field('language_code', XMLDB_TYPE_CHAR, '8', null, null, null, null, 'captions_mode'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026060801, 'fastpix');
    }

    return true;
}
