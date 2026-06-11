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

namespace mod_fastpix\completion;

use core_completion\activity_custom_completion;
use local_fastpix\service\asset_service;
use mod_fastpix\service\playback_service;

/**
 * Custom completion rules for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Custom completion for FastPix Video — exactly one rule (CG3 / PR-19):
 * `completionwatchedpercent`. Adding a second rule fails CI.
 *
 * Completion is "sticky" once granted (mdl_fastpix_attempt.has_completed = 1).
 * This guards against the edge case where a teacher edits the activity to
 * raise the threshold AFTER a student already qualified — the student keeps
 * their completion. The threshold-vs-coverage comparison only runs when
 * has_completed is still 0.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Return the list of custom completion rules this module defines.
     *
     * @return array The defined custom rule names.
     */
    public static function get_defined_custom_rules(): array {
        return ['completionwatchedpercent'];
    }

    /**
     * Compute the completion state for the given rule.
     *
     * @param string $rule The completion rule name.
     * @return int One of the COMPLETION_* constants.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $activity = $DB->get_record('fastpix', ['id' => (int)$this->cm->instance], '*', MUST_EXIST);

        $attempt = $DB->get_record('fastpix_attempt', [
            'userid'      => (int)$this->userid,
            'activity_id' => (int)$activity->id,
        ]);

        if (!$attempt) {
            return COMPLETION_INCOMPLETE;
        }

        // Sticky completion (CG4) — once has_completed=1, never re-evaluate.
        // Threshold changes by teachers do not retroactively revoke completion.
        if (!empty($attempt->has_completed)) {
            return COMPLETION_COMPLETE;
        }

        return $this->evaluate_watched_percent($activity, $attempt);
    }

    /**
     * Re-derive completion from the stored watch intervals against the activity
     * threshold. Only reached while has_completed is still 0 (non-sticky path).
     *
     * Asset duration is the only thing we need from local_fastpix; a deleted /
     * unavailable asset is treated as incomplete — never block completion on an
     * integration failure, but never grant it either.
     *
     * @param \stdClass $activity The fastpix activity row.
     * @param \stdClass $attempt The fastpix_attempt row for this user.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    private function evaluate_watched_percent(\stdClass $activity, \stdClass $attempt): int {
        $asset = asset_service::get_by_id((int)$attempt->asset_id);
        if ($asset === null) {
            return COMPLETION_INCOMPLETE;
        }
        $duration = (int)($asset->duration ?? 0);

        $percent = playback_service::compute_initial_coverage_percent(
            (string)($attempt->watched_intervals ?? ''),
            $duration
        );

        $threshold = (int)($activity->completion_watch_percent ?? 90);
        return ($percent >= $threshold) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Return human-readable descriptions for each custom completion rule.
     *
     * @return array Map of rule name to description string.
     */
    public function get_custom_rule_descriptions(): array {
        $threshold = isset($this->cm->customdata['customcompletionrules']['completionwatchedpercent'])
            ? (int)$this->cm->customdata['customcompletionrules']['completionwatchedpercent']
            : 90;
        return [
            'completionwatchedpercent' => get_string('completionwatchedpercent_desc', 'mod_fastpix', $threshold),
        ];
    }

    /**
     * Return the display sort order for the custom completion rules.
     *
     * @return array The ordered rule names.
     */
    public function get_sort_order(): array {
        // Moodle's completion API (cm_completion_details::sort_completion_details)
        // requires get_sort_order() to list EVERY condition that can apply — the
        // standard ones enabled via fastpix_supports() (completionview from
        // FEATURE_COMPLETION_TRACKS_VIEWS; completionusegrade / completionpassgrade
        // from FEATURE_GRADE_HAS_GRADE) plus the custom completionwatchedpercent
        // rule. Omitting a standard condition makes Moodle throw a coding_exception
        // the moment a teacher enables it alongside the watched-% rule. Listing a
        // condition that isn't enabled is harmless (it's simply skipped).
        return [
            'completionview',
            'completionusegrade',
            'completionpassgrade',
            'completionwatchedpercent',
        ];
    }
}
