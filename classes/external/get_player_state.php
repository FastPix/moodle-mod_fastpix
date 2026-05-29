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

namespace mod_fastpix\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_fastpix\dto\view_state_player;
use mod_fastpix\dto\view_state_error;

/**
 * External function to resolve the current player state for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Resolve the current player state for an activity (in-place processing→player
 * swap). The processing_state_poller calls this once the upload status flips
 * away from 'pending'; a 'ready' response carries the full mount payload so the
 * poller can render the player_wrapper partial without a full-page reload.
 *
 * No session_token parameter — during processing the client has none;
 * resolve_for_view mints it via get_or_create_attempt. The auth dance mirrors
 * refresh_playback_token (S3). Adds NO new local_fastpix surface (CC1/CC7).
 */
class get_player_state extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters The parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Describe the return structure of execute().
     *
     * @return external_single_structure The return definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ready'     => new external_value(PARAM_BOOL, 'True when the player payload is present'),
            'error_key' => new external_value(PARAM_ALPHANUMEXT, 'Terminal error reason key', VALUE_OPTIONAL),
            // Player payload — every field VALUE_OPTIONAL so not-ready
            // responses omit them entirely.
            'playback_id'              => new external_value(PARAM_RAW, 'Playback id', VALUE_OPTIONAL),
            'playback_token'           => new external_value(PARAM_RAW, 'HLS manifest JWT', VALUE_OPTIONAL),
            'drm_token'                => new external_value(PARAM_RAW, 'DRM license JWT', VALUE_OPTIONAL),
            'drm_required'             => new external_value(PARAM_BOOL, 'DRM required', VALUE_OPTIONAL),
            'accent_color'             => new external_value(PARAM_RAW, 'Player accent colour', VALUE_OPTIONAL),
            'default_show_captions'    => new external_value(PARAM_BOOL, 'Show captions by default', VALUE_OPTIONAL),
            'activity_name'            => new external_value(PARAM_RAW, 'Activity name', VALUE_OPTIONAL),
            'activity_id'              => new external_value(PARAM_INT, 'Activity id', VALUE_OPTIONAL),
            'cm_id'                    => new external_value(PARAM_INT, 'Course module id', VALUE_OPTIONAL),
            'asset_id'                 => new external_value(PARAM_INT, 'Asset id', VALUE_OPTIONAL),
            'session_token'            => new external_value(PARAM_RAW, 'Session token', VALUE_OPTIONAL),
            'no_skip_required'         => new external_value(PARAM_BOOL, 'No-skip enforced', VALUE_OPTIONAL),
            'completion_watch_percent' => new external_value(PARAM_INT, 'Completion threshold percent', VALUE_OPTIONAL),
            'current_position'         => new external_value(PARAM_FLOAT, 'Resume position in seconds', VALUE_OPTIONAL),
            'asset_duration_seconds'   => new external_value(PARAM_INT, 'Asset duration in seconds', VALUE_OPTIONAL),
            'initial_intervals_json'   => new external_value(PARAM_RAW, 'Raw JSON watched-intervals literal', VALUE_OPTIONAL),
            'has_completed'            => new external_value(PARAM_BOOL, 'Completion already reached', VALUE_OPTIONAL),
            'expires_at_ts'            => new external_value(PARAM_INT, 'JWT expiry unix ts', VALUE_OPTIONAL),
            'initial_coverage_percent' => new external_value(PARAM_INT, 'Pre-computed coverage percent', VALUE_OPTIONAL),
            'intro_html'               => new external_value(PARAM_RAW, 'Formatted activity intro HTML', VALUE_OPTIONAL),
            'player_lib_url'           => new external_value(PARAM_RAW, 'Player ESM url', VALUE_OPTIONAL),
            'hls_lib_url'              => new external_value(PARAM_RAW, 'hls.js ESM url', VALUE_OPTIONAL),
            // Status-pill vars (merged from view_state_player::progress_card_context)
            // so the poller's player_wrapper swap renders the pill identically.
            'coverage_percent'         => new external_value(PARAM_INT, 'Coverage percent (started signal)', VALUE_OPTIONAL),
            'watched_seconds'          => new external_value(PARAM_INT, 'Unique seconds watched', VALUE_OPTIONAL),
            'pill_state'               => new external_value(
                PARAM_ALPHA,
                'Initial status-pill state (notstarted/paused/complete)',
                VALUE_OPTIONAL
            ),
        ]);
    }

    /**
     * Resolve the current player/processing/error state for an activity.
     *
     * @param int $cmid The course module id.
     * @return array See execute_returns().
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
        ]);

        $cm = get_coursemodule_from_id('fastpix', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/fastpix:view', $context);

        $course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $activity = $DB->get_record('fastpix', ['id' => $cm->instance], '*', MUST_EXIST);
        $cminfo   = \cm_info::create($cm);

        $state = \mod_fastpix\service\playback_service::instance()->resolve_for_view(
            $activity,
            (int)$USER->id,
            $cminfo
        );

        if ($state instanceof view_state_player) {
            return array_merge(['ready' => true], $state->to_player_payload());
        }

        if ($state instanceof view_state_error) {
            // Terminal errors (e.g. drm_unsupported, videounavailable) — the
            // poller renders the error in place and stops. Only the reason key
            // crosses the boundary, never internals (S9).
            return ['ready' => false, 'error_key' => $state->reasonkey];
        }

        // The view_state_processing case — still in flight; poller keeps polling.
        return ['ready' => false];
    }
}
