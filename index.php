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
 * Lists all FastPix Video activities in a course.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT); // Course id.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$coursecontext = context_course::instance($course->id);

$PAGE->set_url('/mod/fastpix/index.php', ['id' => $id]);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('modulenameplural', 'mod_fastpix'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_fastpix'));

$instances = get_all_instances_in_course('fastpix', $course);
if (empty($instances)) {
    echo $OUTPUT->notification(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_fastpix')),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [get_string('name'), get_string('description')];
$table->align = ['left', 'left'];

foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/fastpix/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name),
        $instance->visible ? [] : ['class' => 'dimmed']
    );
    $intro = format_module_intro('fastpix', $instance, $instance->coursemodule);
    $table->data[] = [$link, $intro];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
