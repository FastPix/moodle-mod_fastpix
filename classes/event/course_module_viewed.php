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
 * The course_module_viewed event for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Standard Moodle "course module viewed" event, fired on every view.php load.
 *
 * Extends \core\event\course_module_viewed so the activity participates in
 * Moodle's standard logging, reports, and completion-by-view infrastructure
 * (rule M2 FEATURE_COMPLETION_TRACKS_VIEWS). Pattern follows mod_url.
 */
class course_module_viewed extends \core\event\course_module_viewed {
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
     * Map the objectid for backup/restore.
     *
     * @return array The object id mapping.
     */
    public static function get_objectid_mapping() {
        return ['db' => 'fastpix', 'restore' => 'fastpix'];
    }
}
