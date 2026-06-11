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

namespace mod_fastpix\event;

/**
 * Watch-milestone event for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Watch milestone reached (25/50/75/100 percent of asset duration).
 *
 * Idempotency contract (CG5): fires exactly once per (user, activity,
 * milestone) tuple. watch_tracker_service guards re-fire by checking
 * mdl_fastpix_attempt.milestone_<n>_at inside a delegated transaction.
 * The reached percent is carried in $other['milestone'] for observers.
 */
class watch_milestone extends \core\event\base {
    /**
     * Build the milestone event from an attempt id and the milestone reached.
     *
     * @param int $attemptid The mdl_fastpix_attempt.id (used as objectid).
     * @param int $milestone One of 25, 50, 75, 100.
     * @return self The created event.
     */
    public static function create_from_attempt(int $attemptid, int $milestone): self {
        global $DB;
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        // Load the activity row so we can scope the cm lookup by course id.
        // Bare instance-only lookup is ambiguous when orphan course_modules
        // rows exist (raw-SQL resets that didn't cascade through cm cleanup).
        $activity = $DB->get_record('fastpix', ['id' => (int)$attempt->activity_id], 'id, course', MUST_EXIST);
        $cm = get_coursemodule_from_instance('fastpix', (int)$activity->id, (int)$activity->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return self::create([
            'objectid' => $attemptid,
            'context'  => $context,
            'userid'   => (int)$attempt->userid,
            'other'    => [
                'milestone' => $milestone,
            ],
        ]);
    }

    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'fastpix_attempt';
    }

    /**
     * Return the localised event name.
     *
     * @return string The event name.
     */
    public static function get_name() {
        return get_string('event_watch_milestone', 'mod_fastpix');
    }

    /**
     * Return a human-readable description of the event.
     *
     * @return string The event description.
     */
    public function get_description() {
        $milestone = isset($this->other['milestone']) ? (int)$this->other['milestone'] : 0;
        return "The user with id '$this->userid' reached the {$milestone}% watch milestone " .
            "for FastPix Video attempt id '$this->objectid'.";
    }

    /**
     * Return the URL relevant to the event.
     *
     * @return \moodle_url The event URL.
     */
    public function get_url() {
        return new \moodle_url('/mod/fastpix/view.php', ['id' => $this->contextinstanceid]);
    }
}
