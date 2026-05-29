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

require_once($CFG->dirroot . '/mod/fastpix/backup/moodle2/restore_fastpix_stepslib.php');

/**
 * Activity-level restore task for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Activity-level restore task for mod_fastpix. Wires the structure step
 * from stepslib + declares which URLs the restore decoder must rewrite
 * back from `$@FASTPIXVIEWBYID*N@$` placeholders to live URLs.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_fastpix_activity_task extends restore_activity_task {
    /**
     * Define task-specific settings.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Define the restore steps for this task.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_fastpix_activity_structure_step(
            'fastpix_structure',
            'fastpix.xml'
        ));
    }

    /**
     * Define the contents that the restore decoder must process.
     *
     * @return array The decode-content definitions.
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('fastpix', ['intro'], 'fastpix'),
        ];
    }

    /**
     * Define the URL-rewrite rules used by the restore decoder.
     *
     * @return array The decode-rule definitions.
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('FASTPIXVIEWBYID', '/mod/fastpix/view.php?id=$1', 'course_module'),
            new restore_decode_rule('FASTPIXINDEX', '/mod/fastpix/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Define the activity-level restore log rules.
     *
     * @return array The restore log-rule definitions.
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('fastpix', 'add', 'view.php?id={course_module}', '{fastpix}'),
            new restore_log_rule('fastpix', 'view', 'view.php?id={course_module}', '{fastpix}'),
        ];
    }

    /**
     * Define the course-level restore log rules.
     *
     * @return array The course-level restore log-rule definitions.
     */
    public static function define_restore_log_rules_for_course() {
        return [
            new restore_log_rule('fastpix', 'view all', 'index.php?id={course}', null),
        ];
    }
}
