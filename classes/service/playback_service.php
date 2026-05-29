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

namespace mod_fastpix\service;

use local_fastpix\service\asset_service;
use local_fastpix\service\playback_service as lf_playback_service;
use local_fastpix\exception\asset_not_found;
use local_fastpix\exception\asset_not_ready;
use mod_fastpix\dto\view_state_error;
use mod_fastpix\dto\view_state_player;
use mod_fastpix\dto\view_state_processing;

/**
 * Playback resolution service for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The mod_fastpix wrapper around local_fastpix's playback + asset services.
 * Owns the activity-level reconciliation: upload_session_id → asset row,
 * with idempotent backfill of fastpix_asset_id, and the get-or-create
 * attempt logic that anchors session_token storage (D1).
 */
class playback_service {
    /**
     * ESM build of the FastPix Web Player. Loaded via native `import()` from
     * view.php — bypasses Moodle's RequireJS entirely. The module side-effects
     * `customElements.define('fastpix-player', ...)` on first import; subsequent
     * imports are no-ops thanks to the `customElements.get(...) ||` guard at
     * the bottom of the player IIFE.
     *
     * Why ESM, not the IIFE bundle: the player's own hls.js auto-loader uses
     * a plain `<script>` append, which under Moodle's RequireJS triggers the
     * UMD `define.amd` branch and never sets `window.Hls`. Pre-loading hls.js
     * as ESM (HLS_LIB_URL below) and stashing it on `window.Hls` short-circuits
     * the player's loader. ESM runs outside the AMD context.
     */
    const PLAYER_LIB_URL = 'https://cdn.jsdelivr.net/npm/@fastpix/fp-player@1.0.17/dist/player.esm.js';

    /**
     * HLS.js as ESM. jsdelivr's `+esm` adapter wraps the UMD package as a
     * native ES module; the default export is the `Hls` class. Native
     * `import()` bypasses RequireJS, so the UMD-vs-AMD conflict that plagues
     * the player's built-in hls auto-loader cannot fire here.
     */
    const HLS_LIB_URL = 'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/+esm';

    /** @var self|null Singleton instance. */
    private static $instance = null;

    /**
     * Get the shared service instance.
     *
     * @return self The singleton instance.
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Does this activity have any REAL watch attempts?
     * "Real" excludes teacher previews which leave behind empty-interval rows
     * (see get_or_create_attempt — teachers now get an in-memory stub, but
     * legacy rows from before that fix may still exist).
     *
     * Phase D Slice A: post-schema-migration this checks watched_intervals
     * (was watched_seconds > 0 pre-migration). Preview rows initialise as
     * the empty-string default; any real progress callback writes a JSON
     * array, so the "<> ''" predicate is equivalent.
     *
     * Centralised here so mod_form::validation can call it without doing
     * its own $DB read (A6 — services own business logic).
     */
    public function has_attempts_for(int $activityid): bool {
        global $DB;
        return $DB->record_exists_select(
            'fastpix_attempt',
            "activity_id = :aid AND watched_intervals <> ''",
            ['aid' => $activityid]
        );
    }

    /**
     * Compute coverage percent from a serialised watched-intervals JSON blob.
     * Extracted from resolve_for_view() so PHPUnit can exercise the math
     * without standing up the full local_fastpix resolve path.
     *
     * @param string|null $intervalsjson e.g. '[[0,30],[40,50]]' or '' or null
     * @param int $durationseconds Asset duration; <= 0 collapses to 0%.
     */
    public static function compute_initial_coverage_percent(?string $intervalsjson, int $durationseconds): int {
        if ($durationseconds <= 0) {
            return 0;
        }
        $intervals = json_decode($intervalsjson ?: '[]', true) ?: [];
        $watched = 0.0;
        foreach ($intervals as $interval) {
            if (is_array($interval) && isset($interval[0], $interval[1])) {
                $watched += max(0.0, (float)$interval[1] - (float)$interval[0]);
            }
        }
        return (int) min(100, round(($watched / $durationseconds) * 100));
    }

    /**
     * Reduce the activity row to one of three view-state DTOs. Caller has
     * already performed require_login + require_capability (rule S3).
     *
     * Returns view_state_player when the asset is ready and a JWT minted;
     * view_state_processing when the asset is in flight; view_state_error
     * with reason 'videounavailable' otherwise (ADR-010).
     */
    public function resolve_for_view(\stdClass $activity, int $userid, \cm_info $cm): object {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $asset = null;

        if (!empty($activity->fastpix_asset_id)) {
            $asset = asset_service::get_by_id((int)$activity->fastpix_asset_id);
        } else if (!empty($activity->upload_session_id)) {
            $asset = asset_service::get_by_upload_session_id((int)$activity->upload_session_id);
            if ($asset !== null) {
                // Idempotent backfill: only update when the column is still NULL.
                $DB->set_field(
                    'fastpix',
                    'fastpix_asset_id',
                    $asset->id,
                    ['id' => $activity->id, 'fastpix_asset_id' => null]
                );
                $activity->fastpix_asset_id = $asset->id;
            }
        }

        if ($asset === null) {
            // No fastpix_asset_id and no resolvable upload_session → still processing.
            // No upload_session at all → asset truly unavailable.
            if (!empty($activity->upload_session_id)) {
                return new view_state_processing(
                    activityid:       (int)$activity->id,
                    cmid:             (int)$cm->id,
                    uploadsessionid: (int)$activity->upload_session_id,
                    activityname:     (string)$activity->name,
                );
            }
            return new view_state_error('videounavailable', (string)$activity->name);
        }

        if (!empty($asset->deleted_at)) {
            return new view_state_error('videounavailable', (string)$activity->name);
        }

        if ($asset->status !== 'ready') {
            return new view_state_processing(
                activityid:       (int)$activity->id,
                cmid:             (int)$cm->id,
                uploadsessionid: !empty($activity->upload_session_id) ? (int)$activity->upload_session_id : null,
                activityname:     (string)$activity->name,
            );
        }

        $attempt = $this->get_or_create_attempt($activity, $userid, $asset);

        try {
            $payload = lf_playback_service::resolve((string)$asset->fastpix_id, $userid);
        } catch (asset_not_found $e) {
            return new view_state_error('videounavailable', (string)$activity->name);
        } catch (asset_not_ready $e) {
            return new view_state_processing(
                activityid:       (int)$activity->id,
                cmid:             (int)$cm->id,
                uploadsessionid: !empty($activity->upload_session_id) ? (int)$activity->upload_session_id : null,
                activityname:     (string)$activity->name,
            );
        }

        // Defensive: an asset row can exist with status='ready' but
        // playback_id still null if the media.ready webhook split (asset
        // created event arrived but ready event was lost / delayed).
        // local_fastpix's resolve does not always throw asset_not_ready in
        // that case; treat empty playback_id as still-processing.
        if (empty($payload->playbackid)) {
            return new view_state_processing(
                activityid:       (int)$activity->id,
                cmid:             (int)$cm->id,
                uploadsessionid: !empty($activity->upload_session_id) ? (int)$activity->upload_session_id : null,
                activityname:     (string)$activity->name,
            );
        }

        // Phase 2 DRM — read the optional drm_token (aud="drm:<id>" JWT)
        // off the payload. Supported once local_fastpix's playback_service
        // exposes it; older versions don't have the property and we get
        // an empty string. If the asset requires DRM but no drm_token is
        // available, refuse to render the player with a stale token —
        // show the graceful drm_unsupported error instead, which is less
        // confusing than the FastPix CDN's "Network Error" overlay.
        $drmtoken = '';
        if (isset($payload->drm_token)) {
            $drmtoken = (string)$payload->drm_token;
        } else if (isset($payload->drmtoken)) {
            // Defensive — match local_fastpix's no-underscore property style.
            $drmtoken = (string)$payload->drmtoken;
        }
        if (!empty($payload->drmrequired) && $drmtoken === '') {
            return new view_state_error('drm_unsupported', (string)$activity->name);
        }

        // Phase D Slice A: compute the visible progress strip's first-paint
        // fill server-side so the bar shows correct % before tracker JS runs.
        $duration = (int)($asset->duration ?? 0);
        $initialcoverage = self::compute_initial_coverage_percent(
            $attempt->watched_intervals ?? '',
            $duration
        );

        // Formatted, filtered intro — rendered BELOW the player only. Lives on
        // the player DTO (not processing/error) so the description shows with
        // the player in both the server render and the poller swap. Empty when
        // the teacher left the intro blank → player_wrapper renders nothing.
        $introhtml = !empty($activity->intro)
            ? format_module_intro('fastpix', $activity, $cm->id)
            : '';

        // The "Completed" indicator must only appear when the watched-%
        // completion condition is actually enabled on this activity — the same
        // signal lib.php uses for the rule description. Without it, coverage
        // alone must not claim completion (and no grade is written either).
        $completionrules = $cm->customdata['customcompletionrules'] ?? [];
        $completionenabled = !empty($completionrules['completionwatchedpercent']);

        return new view_state_player(
            playbackid:               $payload->playbackid,
            playbacktoken:            $payload->playbacktoken,
            expiresatts:             $payload->expiresatts,
            drmrequired:              $payload->drmrequired,
            accentcolor:              $payload->accentcolor,
            // Teacher's per-activity checkbox (mdl_fastpix.default_show_captions)
            // is the source of truth — it overrides the tenant default coming
            // back on the playback_payload. Falsy activity column → fall back
            // to the tenant-level default so global "always on" still works.
            defaultshowcaptions:     !empty($activity->default_show_captions)
                                          ? true
                                          : (bool) $payload->defaultshowcaptions,
            activityname:             (string)$activity->name,
            activityid:               (int)$activity->id,
            cmid:                     (int)$cm->id,
            assetid:                  (int)$asset->id,
            sessiontoken:             (string)$attempt->session_token,
            // Drive the player's no-skip UX from the teacher's per-activity
            // setting (mdl_fastpix.no_skip_required) — the SAME source fraud
            // check ⑥ (seek_on_noskip) uses in watch_tracker_service. Previously
            // this read $asset->no_skip_required (a local_fastpix asset-wide flag
            // that the teacher checkbox never sets), so "Disable seeking" saved
            // but the player still showed the seek controls.
            noskiprequired:          !empty($activity->no_skip_required),
            initialcoveragepercent:  $initialcoverage,
            completionwatchpercent:  (int)($activity->completion_watch_percent ?? 90),
            currentposition:          (float)($attempt->current_position ?? 0.0),
            assetdurationseconds:    $duration,
            initialintervalsjson:    !empty($attempt->watched_intervals) ? (string)$attempt->watched_intervals : '[]',
            hascompleted:             !empty($attempt->has_completed),
            drmtoken:                 $drmtoken,
            introhtml:                $introhtml,
            completionenabled:        $completionenabled,
        );
    }

    /**
     * Look up the (userid, activity_id) attempt row. If the existing row's
     * session is within TTL, reuse it. Otherwise mint a new session_token
     * and reset session_start_ts. Phase D mutates watched_intervals,
     * current_position, has_completed, seek_count, and fraud_count on this
     * same row; session reset preserves progress (only session_* is rotated).
     */
    public function get_or_create_attempt(\stdClass $activity, int $userid, \stdClass $asset): \stdClass {
        global $DB;

        $tokens = session_token_service::instance();
        $now = time();

        // Teacher previews are NOT tracked. Without this guard, every time a
        // teacher/admin opens their own activity, a fastpix_attempt row gets
        // created and the asset-swap guard in mod_form::validation (D5) then
        // refuses to let them swap the video. Stub attempt (id=0) lets
        // view.php still render the player; no DB row is written.
        //
        // Phase D contract: record_view_progress MUST treat attempt.id=0 as
        // "preview mode" and short-circuit with a soft-success (no row write,
        // no fraud check). The AMD watch_tracker should no-op when its
        // session_token matches a stub.
        // Pass the activity's course id to scope the lookup. Without this,
        // orphan course_modules rows from previously-deleted activities
        // (e.g. raw TRUNCATE on mdl_fastpix that doesn't cascade through
        // mdl_course_modules) can match the same fastpix.id, throwing
        // dml_multiple_records_exception. Scoping by course makes the
        // (course, module, instance) tuple unique per Moodle invariant.
        $cm = get_coursemodule_from_instance('fastpix', (int)$activity->id, (int)$activity->course, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        if (has_capability('mod/fastpix:addinstance', $context, $userid, false)) {
            return (object)[
                'id'                => 0,
                'userid'            => $userid,
                'activity_id'       => (int)$activity->id,
                'asset_id'          => (int)$asset->id,
                'session_start_ts'  => $now,
                'session_token'     => $tokens->issue($userid, (int)$activity->id, $now),
                'last_callback_ts'  => null,
                'watched_intervals' => '',
                'current_position'  => 0.0,
                'has_completed'     => 0,
                'seek_count'        => 0,
                'fraud_count'       => 0,
                'last_fraud_reason' => null,
                'completion_state'  => 'in_progress',
            ];
        }

        $row = $DB->get_record(
            'fastpix_attempt',
            ['userid' => $userid, 'activity_id' => (int)$activity->id]
        );

        if ($row && $tokens->is_within_ttl((int)$row->session_start_ts)) {
            return $row;
        }

        if ($row) {
            $row->asset_id = (int)$asset->id;
            $row->session_start_ts = $now;
            $row->session_token = $tokens->issue($userid, (int)$activity->id, $now);
            $row->last_callback_ts = null;
            $DB->update_record('fastpix_attempt', $row);
            return $row;
        }

        $new = (object)[
            'userid'            => $userid,
            'activity_id'       => (int)$activity->id,
            'asset_id'          => (int)$asset->id,
            'session_start_ts'  => $now,
            'session_token'     => $tokens->issue($userid, (int)$activity->id, $now),
            'watched_intervals' => '',
            'current_position'  => 0,
            'has_completed'     => 0,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ];
        $new->id = $DB->insert_record('fastpix_attempt', $new);
        return $new;
    }
}
