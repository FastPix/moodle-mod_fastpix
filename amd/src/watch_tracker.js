// mod_fastpix/watch_tracker — Phase D Slice A Step 3 (server-validated).
//
// Maintains a non-overlapping interval set of *actually watched* seconds,
// pushes a live coverage % into the on-page progress strip, and POSTs the
// state to mod_fastpix_record_view_progress every ~10s + on pause /
// visibilitychange / pagehide / ended. The 1.5×playbackRate delta-cap
// rejects scrubber jumps client-side; the SAME jump is also rejected
// server-side by fraud check ① (exceeds_duration / ② exceeds_wall_clock).
//
// Server response is authoritative. coverage_percent / completion_state /
// fraud_count from the response overwrite the on-page strip after each
// successful POST. On session/capability error codes the tracker stops
// itself; on transient network failure the local intervals are preserved
// in localStorage and the next persist() retries.
//
// Hard contract (consumed by the build/watch_tracker.min.js + view.mustache):
//   wrapper:  [data-region="fastpix-player-wrapper"]
//             data-cm-id, data-session-token, data-current-position
//   strip:    [data-region="fastpix-watch-progress"]
//             data-threshold, data-asset-duration
//   regions:  [data-region="fastpix-watch-percent"]        — % textContent
//             [data-region="fastpix-watch-bar-fill"]       — style.width
//             [data-region="fastpix-watch-status"]         — status text
//             [data-region="fastpix-watch-threshold-hint"] — hidden on complete

import {get_string as getString} from 'core/str';
import {call as ajaxCall} from 'core/ajax';

/** Throttle for periodic persist() calls (matches the server cadence). */
const PERSIST_THROTTLE_MS = 10000;

/** Floor on per-tick playback gain — keeps the cap usable at 1× rate. */
const MIN_DELTA_CAP_S = 1.5;

/** Two intervals whose gap is <= MERGE_EPS_S collapse to one. */
const MERGE_EPS_S = 0.01;

/** Completion-state colour for the bar fill. */
const COMPLETE_COLOR = '#1A7F37';

/** localStorage key prefix per cm — used as a network-failure replay buffer. */
const STORAGE_KEY_PREFIX = 'mod_fastpix_attempt_';

/** Server errorcodes that mean "stop tracking" (don't bother retrying). */
const STOP_ERRORCODES = new Set([
    'error_session_invalid',
    'error_session_no_attempt',
    'error_session_finalised',
    'nopermissions',                  // Moodle's required_capability_exception
    'requiresignedin',
]);

/** Idempotency guard — init() may be called more than once on the same DOM. */
let initWired = false;

/**
 * Insert a new [start, end] into a sorted-by-start, non-overlapping interval
 * array. Returns a new merged array (input is not mutated).
 *
 * @param {Array<[number,number]>} arr Existing intervals.
 * @param {number} start
 * @param {number} end
 * @returns {Array<[number,number]>}
 */
const addInterval = (arr, start, end) => {
    const combined = arr.concat([[start, end]]).sort((a, b) => a[0] - b[0]);
    const merged = [];
    for (const cur of combined) {
        const last = merged.at(-1);
        if (last && cur[0] - last[1] <= MERGE_EPS_S) {
            last[1] = Math.max(last[1], cur[1]);
        } else {
            merged.push([cur[0], cur[1]]);
        }
    }
    return merged;
};

/**
 * Sum of (end - start) across all intervals.
 *
 * @param {Array<[number,number]>} arr
 * @returns {number}
 */
const sumCoverage = (arr) => {
    let total = 0;
    for (const interval of arr) {
        total += Math.max(0, interval[1] - interval[0]);
    }
    return total;
};

/**
 * Public entry point — called from view.php's js_init_code immediately
 * after `wrap.appendChild(el)` mounts the <fastpix-player>. The element
 * is passed in directly, NOT discovered by the tracker (smoke-fix #5).
 * This is the same pattern used by Mux Player, Video.js, JW Player,
 * Plyr, and Vidstack — pass the player, never search for it.
 *
 * Listeners attached BEFORE loadedmetadata fires are fine; the browser
 * queues them and dispatches whenever the media element gets there.
 *
 * @param {HTMLElement} player Mounted <fastpix-player>.
 * @param {Object} config
 * @param {number} config.cmId
 * @param {string} config.sessionToken
 * @param {Array<[number,number]>} config.initialIntervals
 * @param {number} config.thresholdPercent
 * @param {number} config.assetDurationSeconds
 * @param {boolean} config.hasCompleted
 */
export const init = (player, config) => {
    if (!player || !config) {
        return;
    }
    if (initWired) {
        return;
    }
    initWired = true;

    if (typeof config.cmId !== 'number') {
        return;
    }
    const duration = Number(config.assetDurationSeconds || 0);
    if (duration <= 0) {
        return;
    }

    const wrapper = document.querySelector('[data-region="fastpix-player-wrapper"]');
    const strip   = document.querySelector('[data-region="fastpix-watch-progress"]');
    if (!wrapper || !strip) {
        return;
    }

    // Smoke-fix #6 — <fastpix-player> is a custom element that wraps an
    // inner HTMLMediaElement in shadow DOM. Events (timeupdate, play,
    // seeking, etc.) bubble up to the host so addEventListener on the
    // host fires correctly, but the host does NOT proxy media properties
    // (currentTime, duration, playbackRate, paused) — those read 0 / NaN
    // on the wrapper. Resolve the underlying media element once and use
    // it for every property read.
    //
    // Probe order matches Media Chrome / Mux Player conventions:
    //   1. `player.media`               (Media Chrome / Mux exposed getter)
    //   2. open shadowRoot <video/audio> (most other web components)
    //   3. light-DOM <video> child       (defensive)
    //   4. fall back to the host         (covers the case where the host
    //                                     DOES proxy — code path no-ops)
    let media = null;
    if (player.media) {
        media = player.media;
    } else if (player.shadowRoot) {
        media = player.shadowRoot.querySelector('video')
             || player.shadowRoot.querySelector('audio');
    }
    if (!media) {
        media = player.querySelector('video') || player;
    }

    let watched           = Array.isArray(config.initialIntervals) ? config.initialIntervals.slice() : [];
    let lastTime          = 0;
    let isSeeking         = false;
    let seekCount         = 0;
    let endedFired        = false;
    let stopped           = false;
    let hasCompleted      = Boolean(config.hasCompleted);
    let currentPlaybackId = null;     // populated on first loadedmetadata (E5)
    const threshold       = Number(config.thresholdPercent || 100);

    const storageKey = STORAGE_KEY_PREFIX + config.cmId;

    /** Repaint the strip from local `watched` (used between server callbacks). */
    const updateProgressUI = () => {
        const seconds = sumCoverage(watched);
        const percent = Math.min(100, Math.round((seconds / duration) * 100));
        paintPercent(percent);
        if (percent >= threshold || hasCompleted) {
            markCompleteUI();
        }
    };

    /**
     * React to a successful persist response. Honours the server's completion
     * verdict (sticky once `complete`) and nothing else — see the smoke-fix
     * note below for why coverage_percent is intentionally NOT painted here.
     */
    const repaintFromServer = (response) => {
        if (!response) {
            return;
        }
        // Smoke-fix #2 — do NOT call paintPercent(response.coverage_percent)
        // here. The local `watched` array is the authoritative source for
        // the bar; the server response trails by up to PERSIST_THROTTLE_MS
        // (10s) because that's the throttle. Painting from the server value
        // makes the bar snap back to the older % on every successful POST
        // and then re-creep up on the next timeupdate tick — the user sees
        // a stuttering / pegged bar despite the player playing. The tick
        // path (updateProgressUI → paintPercent) is the only thing that
        // should paint the bar; the server response is for persistence +
        // completion verdict, not UI authority.
        if (response.completion_state === 'complete') {
            hasCompleted = true;
            markCompleteUI();
        }
        // Future hook for fraud telemetry / soft-block badge:
        //   if (response.fraud_count > 20) { ... }
    };

    const paintPercent = (percent) => {
        const pctNode = strip.querySelector('[data-region="fastpix-watch-percent"]');
        if (pctNode) {
            pctNode.textContent = String(percent);
        }
        const fillNode = strip.querySelector('[data-region="fastpix-watch-bar-fill"]');
        if (fillNode) {
            fillNode.style.width = percent + '%';
            if (hasCompleted) {
                fillNode.style.backgroundColor = COMPLETE_COLOR;
            }
        }
    };

    const markCompleteUI = () => {
        hasCompleted = true;
        const fillNode = strip.querySelector('[data-region="fastpix-watch-bar-fill"]');
        if (fillNode) {
            fillNode.style.backgroundColor = COMPLETE_COLOR;
        }
        const statusNode = strip.querySelector('[data-region="fastpix-watch-status"]');
        if (statusNode) {
            getString('watch_status_complete', 'mod_fastpix')
                .then((s) => { statusNode.textContent = s; return null; })
                .catch(() => { /* string lookup failure — keep previous text */ });
        }
        const hintNode = strip.querySelector('[data-region="fastpix-watch-threshold-hint"]');
        if (hintNode) {
            hintNode.style.display = 'none';
        }
    };

    /**
     * Drain the local snapshot to localStorage (network-failure buffer) and
     * POST it to mod_fastpix_record_view_progress. Server response overrides
     * local UI state on success; stop-codes halt the tracker.
     */
    const persist = (player) => {
        if (stopped) {
            return;
        }
        const payload = {
            watched: watched,
            current_position: player ? Number(media.currentTime || 0) : 0,
            has_completed: hasCompleted,
            saved_at: Date.now(),
        };
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(payload));
        } catch (e) {
            // quota / private mode — silently skip; POST is the source of truth.
        }

        ajaxCall([{
            methodname: 'mod_fastpix_record_view_progress',
            args: {
                cmid: config.cmId,
                session_token: config.sessionToken,
                watched_intervals: JSON.stringify(watched),
                current_position: payload.current_position,
                client_seek_count: seekCount,
                ended_fired: endedFired,
            },
        }])[0].then((response) => {
            repaintFromServer(response);
            // Clear the local replay buffer once the server has acked.
            try {
                window.localStorage.removeItem(storageKey);
            } catch (e) {
                // ignore
            }
            return null;
        }).catch((err) => {
            const code = err?.errorcode ? String(err.errorcode) : '';
            if (STOP_ERRORCODES.has(code)) {
                stopped = true;
            }
            // Otherwise: keep tracking. localStorage still holds the snapshot;
            // next persist() retry will push it.
        });
    };

    // Smoke-fix #4 — listeners wire directly onto the resolved player.
    // No Promise, no MutationObserver, no polling: view.php guarantees the
    // player is mounted before this code runs.
    updateProgressUI();

    player.addEventListener('play', () => {
        // tt.md edge case #25 — replay/resume. A play event after pause
        // (or after natural ended) must clear transient session flags so
        // stale state from the prior playback window doesn't freeze the
        // strip or mis-signal the server:
        //   * isSeeking can get stuck `true` if the underlying media
        //     element fired `seeking` without a paired `seeked` during
        //     buffer realignment — the tick handler would then early-
        //     return on every timeupdate and the bar would freeze.
        //   * endedFired sticking `true` would keep telling the server
        //     "user reached natural end" on every subsequent persist,
        //     which is fine for an already-completed attempt but wrong
        //     once we treat this as a new tracking window.
        endedFired = false;
        isSeeking  = false;
        lastTime   = Number(media.currentTime || 0);
    });

    player.addEventListener('seeking', () => {
        isSeeking = true;
    });

    player.addEventListener('seeked', () => {
        isSeeking = false;
        seekCount += 1;
        lastTime = Number(media.currentTime || 0);
    });

    player.addEventListener('timeupdate', () => {
        if (stopped) {
            return;
        }
        const t = Number(media.currentTime || 0);
        if (isSeeking) {
            lastTime = t;
            return;
        }
        const delta = t - lastTime;

        // tt.md edge case #29 — loop mode. <video loop=true> wraps from
        // end → 0 WITHOUT firing a seeking event. Negative delta + no
        // seek means the boundary crossed implicitly; don't credit the
        // jump (we already credited up to lastTime on the prior tick),
        // just resync.
        if (delta < 0 && !isSeeking) {
            lastTime = t;
            return;
        }

        // tt.md edge case #27 — fast playback adjustment. At 2× rate,
        // normal timeupdate delta is ~0.5s; a hardcoded 1.5s cap would
        // reject legitimate 2×-watching as a skip whenever the browser
        // batched two ticks. Scale by playbackRate.
        //
        // tt.md edge case #17 — backgrounded tab throttling. Browsers
        // throttle timeupdate from ~4 Hz to ~1 Hz when the tab is
        // hidden. Apply a 2× boost so legitimate background watching
        // (audio-only catch-up) isn't rejected.
        const rate = Number(media.playbackRate || 1);
        const visibilityBoost = document.visibilityState === 'hidden' ? 2 : 1;
        const maxDelta = Math.max(MIN_DELTA_CAP_S, rate * MIN_DELTA_CAP_S) * visibilityBoost;
        if (delta > 0 && delta < maxDelta) {
            watched = addInterval(watched, lastTime, t);
            updateProgressUI();
        }
        lastTime = t;
    });

    player.addEventListener('ended', () => {
        // tt.md edge case #23 — snap final interval to full duration.
        // Browsers fire `ended` when the playback head reaches duration,
        // but the final `timeupdate` may have been at 99.97; without
        // this snap, the interval set tops out at 99.97 / duration
        // and progressbar shows 99% instead of 100%. The has_completed
        // flag is also set unconditionally below so completion fires
        // regardless of coverage math precision.
        if (Number.isFinite(media.duration) && media.duration > 0) {
            watched = addInterval(watched, lastTime, media.duration);
            lastTime = media.duration;
        }
        endedFired = true;
        hasCompleted = true;
        updateProgressUI();
        persist(player);
    });

    // tt.md edge case #26 — buffering stall. The browser fires `waiting`
    // when the network can't keep up; resync lastTime so the dry stretch
    // doesn't get credited as watched when playback resumes.
    player.addEventListener('waiting', () => {
        lastTime = Number(media.currentTime || 0);
    });

    // tt.md edge case #24 — source changed. Rare in practice (only fires
    // when a teacher swaps the asset on the activity mid-session, which
    // is currently forbidden by mod_form's asset-swap guard for any
    // activity with real attempts — see playback_service::has_attempts_for).
    // Defensive: reset client state if the playback-id attribute on the
    // element ever changes between loadedmetadata events.
    player.addEventListener('loadedmetadata', () => {
        const newId = player.getAttribute('playback-id');
        if (currentPlaybackId === null) {
            currentPlaybackId = newId;
            return;
        }
        if (newId && newId !== currentPlaybackId) {
            watched = [];
            lastTime = 0;
            hasCompleted = false;
            currentPlaybackId = newId;
        }
    });

    player.addEventListener('pause', () => {
        persist(player);
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            persist(player);
        }
    });

    window.addEventListener('pagehide', () => {
        persist(player);
    });

    window.setInterval(() => {
        persist(player);
    }, PERSIST_THROTTLE_MS);
};
