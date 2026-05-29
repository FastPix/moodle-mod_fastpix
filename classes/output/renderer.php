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

namespace mod_fastpix\output;

use mod_fastpix\dto\view_state_error;
use mod_fastpix\dto\view_state_player;
use mod_fastpix\dto\view_state_processing;

/**
 * Output renderer for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dispatches a view_state DTO to the matching mustache template.
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the given view-state DTO to HTML.
     *
     * @param object $state One of the view_state_* DTOs.
     * @return string The rendered HTML.
     */
    public function render_state(object $state): string {
        if ($state instanceof view_state_player) {
            return $this->render_player($state);
        }
        if ($state instanceof view_state_processing) {
            return $this->render_processing($state);
        }
        if ($state instanceof view_state_error) {
            return $this->render_error($state);
        }
        throw new \coding_exception('Unknown view state: ' . get_class($state));
    }

    /**
     * Render the player view state.
     *
     * @param view_state_player $s The player view-state DTO.
     * @return string The rendered HTML.
     */
    private function render_player(view_state_player $s): string {
        return $this->render_from_template('mod_fastpix/view', array_merge($s->progress_card_context(), [
            'playback_id'              => $s->playbackid,
            'playback_token'           => $s->playbacktoken,
            'expires_at_ts'            => $s->expiresatts,
            'drm_required'             => $s->drmrequired,
            'accent_color'             => $s->accentcolor,
            'default_show_captions'    => $s->defaultshowcaptions,
            'activity_name'            => $s->activityname,
            'activity_id'              => $s->activityid,
            'cm_id'                    => $s->cmid,
            'asset_id'                 => $s->assetid,
            'session_token'            => $s->sessiontoken,
            'no_skip_required'         => $s->noskiprequired,
            // Phase D Slice A Step 1 — progress strip + resume + tracker prereqs.
            'initial_coverage_percent' => $s->initialcoveragepercent,
            'completion_watch_percent' => $s->completionwatchpercent,
            'current_position'         => $s->currentposition,
            'asset_duration_seconds'   => $s->assetdurationseconds,
            // Phase D Slice A Step 2 — tracker JS hydration.
            'initial_intervals_json'   => $s->initialintervalsjson,
            'has_completed'            => $s->hascompleted,
            // Phase 2 DRM — separate license-server JWT.
            'drm_token'                => $s->drmtoken,
            // Activity intro — rendered BELOW the player inside player_wrapper
            // (raw {{{ }}}), only when non-empty. Same field flows through the
            // poller swap via to_player_payload(), so both paths match.
            'intro_html'               => $s->introhtml,
        ]));
    }

    /**
     * Render the processing view state.
     *
     * @param view_state_processing $s The processing view-state DTO.
     * @return string The rendered HTML.
     */
    private function render_processing(view_state_processing $s): string {
        return $this->render_from_template('mod_fastpix/processing', [
            'activity_id'       => $s->activityid,
            'cm_id'             => $s->cmid,
            'upload_session_id' => $s->uploadsessionid,
            'activity_name'     => $s->activityname,
        ]);
    }

    /**
     * Render the error view state.
     *
     * @param view_state_error $s The error view-state DTO.
     * @return string The rendered HTML.
     */
    private function render_error(view_state_error $s): string {
        return $this->render_from_template('mod_fastpix/error', [
            'reason_key'    => $s->reasonkey,
            'activity_name' => $s->activityname,
            'is_videounavailable' => $s->reasonkey === 'videounavailable',
            'is_drm_unsupported'  => $s->reasonkey === 'drm_unsupported',
            'is_capability_lost'  => $s->reasonkey === 'capability_lost',
        ]);
    }
}
