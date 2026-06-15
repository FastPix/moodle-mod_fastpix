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

namespace mod_fastpix\dto;

/**
 * DTO describing the "player" view state and its mount payload helpers.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Player render state. Carries everything the view template + AMD modules
 * need to mount the player and the watch tracker (Phase D consumes the
 * data-* attributes set from these fields).
 */
class view_state_player {
    /**
     * Constructor.
     *
     * @param string $playbackid The FastPix playback id.
     * @param string $playbacktoken The signed playback JWT.
     * @param int $expiresatts Unix timestamp at which the playback token expires.
     * @param bool $drmrequired Whether DRM playback is required.
     * @param string|null $accentcolor Optional player accent colour.
     * @param bool $defaultshowcaptions Whether captions default to on.
     * @param string $activityname The activity display name.
     * @param int $activityid The fastpix activity instance id.
     * @param int $cmid The course module id.
     * @param int $assetid The local asset row id.
     * @param string $sessiontoken The HMAC-bound session token.
     * @param bool $noskiprequired Whether seeking is disabled for this activity.
     * @param int $initialcoveragepercent Server-computed coverage percent for first paint.
     * @param int $completionwatchpercent The completion threshold percent.
     * @param float $currentposition The resume position in seconds.
     * @param int $assetdurationseconds The asset duration in seconds.
     * @param string $initialintervalsjson Raw watched-intervals JSON for tracker hydration.
     * @param bool $hascompleted Whether the attempt has already completed.
     * @param string $drmtoken Separate DRM license JWT; empty for non-DRM assets.
     * @param string $introhtml Formatted activity intro HTML.
     */
    public function __construct(
        /** @var string The FastPix playback id. */
        public readonly string $playbackid,
        /** @var string The signed playback JWT. */
        public readonly string $playbacktoken,
        /** @var int Unix timestamp at which the playback token expires. */
        public readonly int $expiresatts,
        /** @var bool Whether DRM playback is required. */
        public readonly bool $drmrequired,
        /** @var string|null Optional player accent colour. */
        public readonly ?string $accentcolor,
        /** @var bool Whether captions default to on. */
        public readonly bool $defaultshowcaptions,
        /** @var string The activity display name. */
        public readonly string $activityname,
        /** @var int The fastpix activity instance id. */
        public readonly int $activityid,
        /** @var int The course module id. */
        public readonly int $cmid,
        /** @var int The local asset row id. */
        public readonly int $assetid,
        /** @var string The HMAC-bound session token. */
        public readonly string $sessiontoken,
        /** @var bool Whether seeking is disabled for this activity. */
        public readonly bool $noskiprequired,
        // Phase D Slice A Step 1 — coverage-based watch tracking.
        // The coverage percent is computed server-side from
        // watched_intervals + asset.duration so the progress strip
        // renders with the correct fill on first paint (no FOUC).
        /** @var int Server-computed coverage percent for first paint. */
        public readonly int $initialcoveragepercent,
        /** @var int The completion threshold percent. */
        public readonly int $completionwatchpercent,
        /** @var float The resume position in seconds. */
        public readonly float $currentposition,
        /** @var int The asset duration in seconds. */
        public readonly int $assetdurationseconds,
        // Phase D Slice A Step 2 — tracker JS hydration.
        // The intervals JSON is the raw JSON literal from
        // fastpix_attempt.watched_intervals; the mustache template emits
        // it via {{{ ... }}} (no escaping) because it is server-generated.
        /** @var string Raw watched-intervals JSON for tracker hydration. */
        public readonly string $initialintervalsjson,
        /** @var bool Whether the attempt has already completed. */
        public readonly bool $hascompleted,
        // Phase 2 DRM — separate JWT for the license server (aud="drm:<id>").
        // Populated by local_fastpix's playback_service::resolve() when
        // drm_required=true. Empty string for non-DRM assets. The player
        // mounts this on its `drm-token` attribute.
        /** @var string Separate DRM license JWT; empty for non-DRM assets. */
        public readonly string $drmtoken = '',
        // Formatted, filtered activity intro HTML (format_module_intro output).
        // Rendered BELOW the player in player_wrapper.mustache via {{{ }}} raw
        // output so the description appears ONLY with the player state (never on
        // processing/error) — in both the server render and the poller swap.
        // Default '' so processing/error paths and existing tests that omit it
        // keep working, and an empty intro renders nothing (no empty card).
        /** @var string Formatted activity intro HTML. */
        public readonly string $introhtml = '',
        // Whether the watched-percentage completion condition is actually
        // enabled on this activity. The "Completed" indicator is gated on this
        // so watch coverage alone never claims "Completed" on an activity that
        // has no completion condition (and therefore writes no grade). Default
        // true preserves behaviour for callers/tests that omit it.
        /** @var bool Whether the watched-% completion condition is enabled. */
        public readonly bool $completionenabled = true,
    ) {
    }

    /** Circle circumference for r=21: 2*PI*21 ≈ 131.95 (matches the mustache ring). */
    const RING_CIRCUMFERENCE = 131.95;

    /**
     * Format a whole-second count as mm:ss (e.g. 95 → "1:35"). Minutes are not
     * zero-padded; seconds always are. Kept in PHP so the card renders the same
     * on first paint and on the client swap (no JS formatting divergence).
     */
    public static function format_mmss(int $seconds): string {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Sum unique watched seconds from the serialised intervals JSON.
     * Mirrors playback_service::compute_initial_coverage_percent's numerator.
     */
    public static function watched_seconds_from_intervals(?string $intervalsjson): int {
        $intervals = json_decode($intervalsjson ?: '[]', true) ?: [];
        $watched = 0.0;
        foreach ($intervals as $interval) {
            if (is_array($interval) && isset($interval[0], $interval[1])) {
                $watched += max(0.0, (float)$interval[1] - (float)$interval[0]);
            }
        }
        return (int) round($watched);
    }

    /**
     * Compute the initial status-pill state string for first paint. The pill is
     * the ONLY watch-status UI (it replaced the below-player progress card). It
     * is overlaid top-right inside the player container; the live tracker in
     * player.js toggles its root class on play/pause/completion. has_completed
     * wins (sticky); else any coverage > 0 means "started but not currently
     * playing" → paused; else not-started.
     *
     * Returns one of: 'complete', 'paused', 'notstarted'. ('watching' is a
     * live-only state set by player.js on the play event — never a first paint,
     * since on load the video has not begun playing.)
     */
    public function pill_state(): string {
        // Only ever report "complete" when the activity actually has the
        // watched-% completion condition; otherwise coverage must not claim it.
        if ($this->completionenabled && (bool)$this->hascompleted) {
            return 'complete';
        }
        $coverage = max(0, min(100, (int)$this->initialcoveragepercent));
        $watched = self::watched_seconds_from_intervals($this->initialintervalsjson);
        if ($coverage <= 0 && $watched <= 0) {
            return 'notstarted';
        }
        // Started in a prior session but not completed — paused, not watching.
        return 'paused';
    }

    /**
     * Build the player-mount context. Carries the status-pill first-paint state
     * + completion/coverage signals. Both the renderer (first paint) and
     * to_player_payload (poller swap) call this so the pill renders identically
     * in either path. has_completed is sticky — a replay never downgrades it.
     */
    public function progress_card_context(): array {
        $coverage = max(0, min(100, (int)$this->initialcoveragepercent));
        $watched = self::watched_seconds_from_intervals($this->initialintervalsjson);

        return [
            'coverage_percent'   => $coverage,
            'watched_seconds'    => $watched,
            'has_completed'      => (bool)$this->hascompleted,
            'completion_enabled' => $this->completionenabled,
            'pill_state'         => $this->pill_state(),
        ];
    }

    /**
     * Canonical snake_case mount payload. Single source of the player mount
     * data — consumed by view.php's js_call_amd init and by the
     * get_player_state web service (the in-place processing→player swap).
     *
     * Field names match the mustache vars in player_wrapper.mustache so the
     * partial can render directly from this array; the AMD player module
     * reads every value off this payload (not off data-* attrs). The progress
     * card vars are merged in so the player_wrapper partial (which embeds the
     * progress_card partial) renders identically on first load and on swap.
     */
    public function to_player_payload(): array {
        return array_merge($this->progress_card_context(), [
            'playback_id'              => $this->playbackid,
            'playback_token'           => $this->playbacktoken,
            'drm_token'                => $this->drmtoken,
            'drm_required'             => $this->drmrequired,
            'accent_color'             => $this->accentcolor,
            'default_show_captions'    => $this->defaultshowcaptions,
            'activity_name'            => $this->activityname,
            'activity_id'              => $this->activityid,
            'cm_id'                    => $this->cmid,
            'asset_id'                 => $this->assetid,
            'session_token'            => $this->sessiontoken,
            'no_skip_required'         => $this->noskiprequired,
            'completion_watch_percent' => $this->completionwatchpercent,
            'current_position'         => $this->currentposition,
            'asset_duration_seconds'   => $this->assetdurationseconds,
            'initial_intervals_json'   => $this->initialintervalsjson,
            'has_completed'            => $this->hascompleted,
            'expires_at_ts'            => $this->expiresatts,
            'initial_coverage_percent' => $this->initialcoveragepercent,
            'intro_html'               => $this->introhtml,
            // Player ESM is served by local_fastpix (ADR-017); consume the documented
            // surface (CC1/CC7) rather than a local CDN literal. Already absolute.
            'player_lib_url'           => \local_fastpix\service\playback_service::player_lib_url(),
            // HLS_LIB_URL is a root-relative path to the vendored lib; resolve to an
            // absolute URL (wwwroot-prefixed) for the native import() in player.js.
            'hls_lib_url'              => (new \moodle_url(\mod_fastpix\service\playback_service::HLS_LIB_URL))->out(false),
        ]);
    }
}
