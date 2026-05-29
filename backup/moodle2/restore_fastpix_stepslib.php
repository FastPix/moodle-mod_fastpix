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
 * Restore structure step for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore steps for mod_fastpix (rule M9).
 *
 * Inserts a fresh fastpix activity row + (when userinfo was backed up)
 * the per-user attempt rows. fastpix_asset_id is preserved verbatim;
 * upload_session_id is preserved for activities that were still mid-upload
 * at backup time.
 *
 * Cross-FastPix-account restore: the preserved fastpix_asset_id may point
 * to an asset row that doesn't exist on this Moodle (different
 * local_fastpix tenant). playback_service::resolve_for_view returns a
 * view_state_error('videounavailable') in that case, which is the
 * documented restore contract (ADR-010). Do NOT try to recreate the
 * asset on the target account (PR-22 / BR1).
 */
class restore_fastpix_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the restore structure for the activity.
     *
     * @return array The prepared restore path elements.
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('fastpix', '/activity/fastpix');
        if ($userinfo) {
            $paths[] = new restore_path_element('fastpix_attempt', '/activity/fastpix/attempts/attempt');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore a single fastpix activity row.
     *
     * @param array $data The serialised activity data.
     * @return void
     */
    protected function process_fastpix($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->timecreated = isset($data->timecreated) ? $this->apply_date_offset($data->timecreated) : time();
        $data->timemodified = isset($data->timemodified) ? $this->apply_date_offset($data->timemodified) : time();

        // The fastpix_asset_id / upload_session_id: preserve verbatim. They refer
        // to local_fastpix rows which are course-content metadata, not
        // activity-internal IDs. Mapping them through Moodle's id-mapper
        // would break the lookup; treating them as opaque preserves the
        // "Video unavailable" path documented in ADR-010.

        $newitemid = $DB->insert_record('fastpix', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restore a single fastpix attempt row.
     *
     * @param array $data The serialised attempt data.
     * @return void
     */
    protected function process_fastpix_attempt($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);

        $data->activity_id = $this->get_new_parentid('fastpix');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (empty($data->userid)) {
            // User wasn't in the backup mapping (deleted source user / partial
            // restore). Skip the row rather than inserting an orphan.
            return;
        }

        // The asset_id is a local_fastpix reference — preserve verbatim, same as
        // fastpix_asset_id on the activity row. If the asset doesn't exist
        // on this Moodle, the attempt row is harmless: view.php's resolve
        // path returns "Video unavailable" before the attempt is ever read.
        if (isset($data->session_start_ts)) {
            $data->session_start_ts = $this->apply_date_offset($data->session_start_ts);
        }
        if (!empty($data->last_callback_ts)) {
            $data->last_callback_ts = $this->apply_date_offset($data->last_callback_ts);
        }
        foreach (['milestone_25_at', 'milestone_50_at', 'milestone_75_at', 'milestone_100_at'] as $col) {
            if (!empty($data->{$col})) {
                $data->{$col} = $this->apply_date_offset($data->{$col});
            }
        }

        // Mint a fresh session_token. The backup omits the original token
        // (S6); this value gets rotated on first view.php hit anyway via
        // playback_service::get_or_create_attempt, but we need a non-null
        // placeholder to satisfy the NOT NULL column constraint.
        $data->session_token = sha1((string) random_int(PHP_INT_MIN, PHP_INT_MAX) . microtime(true));

        // Ensure NOT NULL columns have sane defaults if the backup omitted
        // them (older Moodle backups predating the Phase D schema bump).
        $data->watched_intervals = $data->watched_intervals ?? '';
        $data->current_position  = $data->current_position ?? 0;
        $data->has_completed     = $data->has_completed ?? 0;
        $data->seek_count        = $data->seek_count ?? 0;
        $data->fraud_count       = $data->fraud_count ?? 0;
        $data->completion_state  = $data->completion_state ?? 'in_progress';

        $newid = $DB->insert_record('fastpix_attempt', $data);
        $this->set_mapping('fastpix_attempt', $oldid, $newid);
    }

    /**
     * Post-restore file handling.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_fastpix', 'intro', null);
    }
}
