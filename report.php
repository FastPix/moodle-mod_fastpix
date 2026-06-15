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
 * Teacher watch reports (per-video + per-user) for the FastPix activity module.
 *
 * Display-only surface over mdl_fastpix_attempt; no tracking, no FastPix calls.
 * Gated by mod/fastpix:viewallattempts.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id       = required_param('id', PARAM_INT);            // Course module id.
$mode     = optional_param('mode', 'video', PARAM_ALPHA); // Report mode: video or user.
$userid   = optional_param('userid', 0, PARAM_INT);     // Target student (user mode).
$download = optional_param('download', '', PARAM_ALPHA); // Export format: empty, or csv.

$cm       = get_coursemodule_from_id('fastpix', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('fastpix', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/fastpix:viewallattempts', $context);

if (!in_array($mode, ['video', 'user'], true)) {
    $mode = 'video';
}

$service = \mod_fastpix\report\watch_report::instance();

// User mode targets a single student. Restrict the target to users enrolled in THIS
// course — not arbitrary site users resolved by guessing ids (which would leak a
// fullname via the per-user report header). Validated before any user record is
// loaded so both the HTML and CSV paths are covered.
if ($mode === 'user' && !$service->is_user_reportable($context, $userid)) {
    throw new \moodle_exception('error_user_not_in_course', 'mod_fastpix');
}

$baseurl = new moodle_url('/mod/fastpix/report.php', ['id' => $cm->id, 'mode' => $mode]);
if ($mode === 'user') {
    $baseurl->param('userid', $userid);
}

// CSV export — same capability gate, streamed via \core\dataformat.
if ($download === 'csv') {
    require_once($CFG->libdir . '/filelib.php');
    $stamp = userdate(time(), '%Y%m%d-%H%M', 99, false);
    $shortname = clean_filename(format_string($course->shortname, true, ['context' => $context]));

    if ($mode === 'user') {
        $report = $service->get_user_report((int)$course->id, $userid);
        $columns = [
            'activity'      => get_string('report_col_activity', 'mod_fastpix'),
            'watchpercent'  => get_string('report_col_watchpercent', 'mod_fastpix'),
            'watchtime'     => get_string('report_col_watchtime', 'mod_fastpix'),
            'milestones'    => get_string('report_col_milestones', 'mod_fastpix'),
            'completed'     => get_string('report_col_completed', 'mod_fastpix'),
            'lastposition'  => get_string('report_col_lastposition', 'mod_fastpix'),
            'seeks'         => get_string('report_col_seeks', 'mod_fastpix'),
            'fraud'         => get_string('report_col_fraud', 'mod_fastpix'),
        ];
        $rows = [];
        foreach ($report->rows as $r) {
            $rows[] = [
                'activity'     => $r->activityname,
                'watchpercent' => $r->coveragepercent,
                'watchtime'    => $service->format_clock($r->watchedseconds),
                'milestones'   => $service->milestones_text($r->milestones),
                'completed'    => $r->completed ? get_string('yes') : get_string('no'),
                'lastposition' => $service->format_clock($r->currentposition),
                'seeks'        => $r->seekcount,
                'fraud'        => $r->fraudcount > 0 ? ($r->fraudcount . ' (' . $r->fraudreason . ')') : '0',
            ];
        }
        $filename = "fastpix-{$shortname}-user{$userid}-{$stamp}";
    } else {
        $report = $service->get_video_report($activity);
        $columns = [
            'student'       => get_string('report_col_student', 'mod_fastpix'),
            'watchpercent'  => get_string('report_col_watchpercent', 'mod_fastpix'),
            'watchtime'     => get_string('report_col_watchtime', 'mod_fastpix'),
            'milestones'    => get_string('report_col_milestones', 'mod_fastpix'),
            'completed'     => get_string('report_col_completed', 'mod_fastpix'),
            'lastposition'  => get_string('report_col_lastposition', 'mod_fastpix'),
            'seeks'         => get_string('report_col_seeks', 'mod_fastpix'),
            'fraud'         => get_string('report_col_fraud', 'mod_fastpix'),
        ];
        $rows = [];
        foreach ($report->rows as $r) {
            $rows[] = [
                'student'      => fullname($r->user),
                'watchpercent' => $r->coveragepercent,
                'watchtime'    => $service->format_clock($r->watchedseconds),
                'milestones'   => $service->milestones_text($r->milestones),
                'completed'    => $r->completed ? get_string('yes') : get_string('no'),
                'lastposition' => $service->format_clock($r->currentposition),
                'seeks'        => $r->seekcount,
                'fraud'        => $r->fraudcount > 0 ? ($r->fraudcount . ' (' . $r->fraudreason . ')') : '0',
            ];
        }
        $filename = "fastpix-{$shortname}-{$cm->instance}-{$stamp}";
    }

    \core\dataformat::download_data($filename, 'csv', $columns, $rows);
    exit;
}

$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($activity->name) . ': ' . get_string('watchreport', 'mod_fastpix'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_secondary_active_tab('watchreport');

/** @var \mod_fastpix\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_fastpix');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($activity->name) . ' — ' . get_string('watchreport', 'mod_fastpix'));

if ($mode === 'user') {
    $student = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    echo $renderer->render_user_report($service->get_user_report((int)$course->id, $userid), $student, $cm);
} else {
    echo $renderer->render_video_report($service->get_video_report($activity), $cm);
}

echo $OUTPUT->footer();
