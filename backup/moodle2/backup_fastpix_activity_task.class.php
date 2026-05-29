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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/fastpix/backup/moodle2/backup_fastpix_stepslib.php');

/**
 * Activity-level backup task for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Activity-level backup task for mod_fastpix. Wires the structure step
 * defined in stepslib + handles URL encoding for intro/content.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_fastpix_activity_task extends backup_activity_task {
    /**
     * Define task-specific settings.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Define the backup steps for this task.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_fastpix_activity_structure_step(
            'fastpix_structure',
            'fastpix.xml'
        ));
    }

    /**
     * Encode any course-module-internal URLs inside the activity intro so
     * `restore_decode_processor` can rewrite them on restore. Pattern matches
     * mod_url / mod_page; we only own /mod/fastpix/view.php URLs.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $content = preg_replace(
            '/(' . $base . '\/mod\/fastpix\/index\.php\?id\=)([0-9]+)/',
            '$@FASTPIXINDEX*$2@$',
            $content
        );
        $content = preg_replace(
            '/(' . $base . '\/mod\/fastpix\/view\.php\?id\=)([0-9]+)/',
            '$@FASTPIXVIEWBYID*$2@$',
            $content
        );

        return $content;
    }
}
