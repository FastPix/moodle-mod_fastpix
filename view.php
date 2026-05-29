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
 * Player view page for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);

$cm       = get_coursemodule_from_id('fastpix', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('fastpix', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/fastpix:view', $context);

$cminfo = cm_info::create($cm);

$state = \mod_fastpix\service\playback_service::instance()->resolve_for_view(
    $activity,
    (int)$USER->id,
    $cminfo
);

$PAGE->set_url('/mod/fastpix/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Description goes BELOW the player. Suppress the auto-rendered header
// description (keeping the title) and render the intro manually after the player
// state, below. Direct call (no isset guard): $PAGE->activityheader is a lazy
// magic getter with no __isset, so isset() returns false and would skip
// suppression — leaving two descriptions. Core mods call this directly too.
$PAGE->activityheader->set_description('');

// Event fires AFTER set_context so observers see populated $PAGE state.
\mod_fastpix\event\activity_viewed::create_from_activity($activity, $context)->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Processing-state UX: the AMD poller (mod_fastpix/processing_state_poller)
// now swaps the player in place when the asset goes ready — no meta-refresh,
// no full-page reload. The no-store cache headers still prevent the browser
// from showing a stale "preparing" HTML response on back/forward navigation
// (the original "have to hard-refresh" symptom).
if ($state instanceof \mod_fastpix\dto\view_state_processing) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// The FastPix Web Player web component (<fastpix-player>) is mounted by the
// mod_fastpix/player AMD module. It loads hls.js + @fastpix/fp-player as native
// ES modules via dynamic import() (sidestepping the RequireJS/UMD window.Hls
// conflict), creates the <fastpix-player> element, and wires the coverage
// tracker — all from the to_player_payload() array. The same payload + module
// drives the in-place processing→player swap (mod_fastpix_get_player_state).
// Skipped on processing/error states (no element to mount).
if ($state instanceof \mod_fastpix\dto\view_state_player) {
    $PAGE->requires->js_call_amd('mod_fastpix/player', 'init', [$state->to_player_payload()]);
}

/** @var \mod_fastpix\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_fastpix');

echo $OUTPUT->header();

// Title renders via the activity header (above). The header description was
// suppressed above (set_description('')), so the auto-header shows no intro.
// The player state renders here. For the player (ready) state, the description
// is rendered BELOW the player inside the player_wrapper partial (raw intro_html,
// only when non-empty) — so it appears with the player in both the server render
// and the in-place processing→player poller swap, and is absent on
// processing/error. No separate intro block is emitted here any more.
echo $renderer->render_state($state);

echo $OUTPUT->footer();
