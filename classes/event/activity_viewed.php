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
 * Activity-viewed event for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Fired on every view.php load (rule M2 FEATURE_COMPLETION_TRACKS_VIEWS).
 */
class activity_viewed extends \core\event\base {
    /**
     * Build the event from an activity record and its module context.
     *
     * @param \stdClass $activity The fastpix activity record.
     * @param \context_module $context The module context.
     * @return self The created event.
     */
    public static function create_from_activity(\stdClass $activity, \context_module $context): self {
        $event = self::create([
            'objectid' => $activity->id,
            'context'  => $context,
        ]);
        $event->add_record_snapshot('fastpix', $activity);
        return $event;
    }

    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'fastpix';
    }

    /**
     * Return the localised event name.
     *
     * @return string The event name.
     */
    public static function get_name() {
        return get_string('eventactivityviewed', 'mod_fastpix');
    }

    /**
     * Return a human-readable description of the event.
     *
     * @return string The event description.
     */
    public function get_description() {
        return "The user with id '$this->userid' viewed the FastPix Video activity " .
            "with course module id '$this->contextinstanceid'.";
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
