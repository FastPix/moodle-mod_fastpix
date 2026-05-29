// Player AMD entry point.
//
// Owns mounting the <fastpix-player> web component and the self-contained
// coverage tracker. Two public entry points:
//
//   init(payload)            — locate [data-region="fastpix-player-wrapper"]
//                              and mount(). Called from view.php (server-side
//                              render path) via js_call_amd.
//   mount(wrapperEl, payload)— mount into an explicit wrapper element. Called
//                              by processing_state_poller after it renders the
//                              player_wrapper partial in place (no full reload).
//
// The mount reads ALL values from the `payload` argument (NOT from data-*
// attributes), so it works identically whether the wrapper was server-rendered
// or template-rendered client-side. The single watch-status UI is the
// "Completed" indicator overlaid top-right inside the wrapper; it is hidden
// until completion. The tracker reveals it by adding the .is-complete class
// once the coverage threshold is crossed (sticky) — the node is already in the
// DOM, so nothing is rebuilt and no string is fetched at runtime. There is no
// "watching"/"paused"/"not started" state any more.
//
// hls.js + the player are loaded as native ES modules via dynamic import() —
// ESM runs outside Moodle's RequireJS/UMD context, sidestepping the
// window.Hls assignment conflict that makes the player render as an inert
// black box with no console error.

// Native runtime ESM loader for CDN deps (hls.js + the player web component).
// These MUST stay native dynamic import() — routing them through RequireJS
// (AMD) makes RequireJS try to AMD-load an ES-module URL, which fails silently
// as an inert player box with no console error (CC9). The catch: inside an AMD
// SOURCE module, @babel/plugin-transform-modules-amd rewrites any literal
// import() into a require([...]) promise — so `grunt amd` would break this.
// The Function-constructor indirection hides the import() from Babel (it sees
// only a string literal), and the body runs in global scope where dynamic
// import is permitted. Keep CDN/ESM loads going through esmImport, never a
// bare literal import().
const esmImport = (url) => Function('u', 'return import(u);')(url);

/**
 * Mount the player + coverage tracker into wrapperEl using payload data.
 *
 * @param {HTMLElement} wrapperEl the [data-region="fastpix-player-wrapper"] node
 * @param {Object} payload canonical snake_case payload (view_state_player::to_player_payload)
 */
export const mount = async(wrapperEl, payload) => {
    if (!wrapperEl || !payload) {
        return;
    }
    // Idempotency — never mount twice into the same wrapper.
    if (wrapperEl.querySelector('fastpix-player')) {
        return;
    }

    try {
        if (!window.Hls) {
            const hlsMod = await esmImport(payload.hls_lib_url);
            window.Hls = hlsMod.default || hlsMod.Hls || hlsMod;
        }
        if (!window.customElements.get('fastpix-player')) {
            await esmImport(payload.player_lib_url);
        }
    } catch (err) {
        if (window.console) {
            window.console.error('[mod_fastpix] player load failed', err);
        }
        return;
    }
    // Re-check after async deps load — a concurrent mount may have won.
    if (wrapperEl.querySelector('fastpix-player')) {
        return;
    }

    const el = document.createElement('fastpix-player');
    el.setAttribute('playback-id', payload.playback_id);
    el.setAttribute('token', payload.playback_token);
    if (payload.drm_required) {
        // Phase 2 DRM — drm-token is a SEPARATE JWT minted by local_fastpix
        // with aud='drm:<playback_id>', not the manifest token. resolve_for_view
        // refuses to render the player at all if drm_required but drm_token is
        // empty, so by the time we get here drm_token is always set.
        el.setAttribute('drm-token', payload.drm_token);
    }
    el.setAttribute('stream-type', 'on-demand');
    if (payload.accent_color) {
        el.setAttribute('accent-color', payload.accent_color);
    }
    if (payload.activity_name) {
        el.setAttribute('metadata-video-title', payload.activity_name);
    }
    // Resume from last known position. current_position comes from
    // mdl_fastpix_attempt.current_position. We set start-time (honoured by the
    // player at first metadata) AND, as a fallback, seek directly once metadata
    // is ready — some player builds ignore start-time, which left the video
    // restarting from 0 even though the server returned the right position.
    const startTime = parseFloat(payload.current_position);
    if (startTime && startTime > 0) {
        el.setAttribute('start-time', String(startTime));
        const resumeSeek = function() {
            try {
                // Only seek if the player is still at the start (start-time
                // didn't take) and the position is within the loaded duration.
                if (Number(el.currentTime || 0) < startTime - 1
                    && (!isFinite(el.duration) || el.duration > startTime)) {
                    el.currentTime = startTime;
                }
            } catch (e) {
                // Non-fatal — playback just begins at 0.
            }
        };
        el.addEventListener('loadedmetadata', resumeSeek, {once: true});
        el.addEventListener('loadeddata', resumeSeek, {once: true});
    }
    el.style.cssText = 'display:block;width:100%;aspect-ratio:16/9;';
    wrapperEl.appendChild(el);

    // No-skip wiring. When the teacher ticks Disable seeking on the activity
    // (mdl_fastpix.no_skip_required = 1), the player needs to:
    //   1) Hide skip-backward / skip-forward buttons (shadow DOM).
    //   2) Disable the time-range scrubber (so dragging doesn't seek).
    //   3) Disable keyboard hotkeys that would seek.
    // Server-side fraud check #6 (seek_on_noskip) catches anything that slips
    // through; this is the UX prevention layer.
    if (payload.no_skip_required) {
        // Mux Player / Media Chrome convention — kills built-in hotkeys.
        el.setAttribute('nohotkeys', '');

        // Set CSS custom props the player MIGHT honour (defence in depth).
        el.style.setProperty('--backward-skip-button', 'none');
        el.style.setProperty('--forward-skip-button', 'none');
        el.style.setProperty('--seek-backward-button', 'none');
        el.style.setProperty('--seek-forward-button', 'none');

        // Pierce shadow DOM. The player wraps Media Chrome web components;
        // the skip buttons + time range are inside the shadow root and can't
        // be hidden by external CSS, so query + hide directly.
        const hideSkipControls = function() {
            const roots = [];
            if (el.shadowRoot) {
                roots.push(el.shadowRoot);
            }
            // Some embeds nest another shadow root one level down.
            if (el.shadowRoot) {
                el.shadowRoot.querySelectorAll('*').forEach(function(n) {
                    if (n.shadowRoot) {
                        roots.push(n.shadowRoot);
                    }
                });
            }
            const selectors = [
                'media-seek-forward-button',
                'media-seek-backward-button',
                'button[aria-label*="forward" i]',
                'button[aria-label*="backward" i]',
                'button[aria-label*="seek" i]',
                '[role="button"][aria-label*="forward" i]',
                '[role="button"][aria-label*="backward" i]',
                '[part*="seek-forward"]',
                '[part*="seek-backward"]'
            ];
            let hit = 0;
            roots.forEach(function(root) {
                selectors.forEach(function(sel) {
                    try {
                        root.querySelectorAll(sel).forEach(function(b) {
                            b.style.display = 'none';
                            hit++;
                        });
                    } catch (e) {
                        // Ignore selector errors.
                    }
                });
                // Disable scrubbing on the time-range slider so dragging the
                // playhead does nothing.
                try {
                    root.querySelectorAll('media-time-range, [part*="time-range"]').forEach(function(tr) {
                        tr.setAttribute('disabled', '');
                        tr.style.pointerEvents = 'none';
                    });
                } catch (e) {
                    // Ignore.
                }
            });
            return hit > 0;
        };

        // Shadow DOM may not exist until after the player mounts and upgrades
        // its custom elements. Run on every meaningful event + a few timeouts
        // as a safety net.
        el.addEventListener('loadedmetadata', hideSkipControls);
        el.addEventListener('loadeddata', hideSkipControls);
        el.addEventListener('canplay', hideSkipControls);
        window.setTimeout(hideSkipControls, 200);
        window.setTimeout(hideSkipControls, 800);
        window.setTimeout(hideSkipControls, 2000);
        // Watch for the player to inject controls late.
        if (typeof MutationObserver !== 'undefined' && el.shadowRoot) {
            new MutationObserver(hideSkipControls).observe(
                el.shadowRoot,
                {childList: true, subtree: true}
            );
        }

        // Keyboard guard — blocks every seek-related key in capture phase.
        const seekKeys = {
            'ArrowLeft': 1, 'ArrowRight': 1,
            'KeyJ': 1, 'KeyL': 1,
            'Home': 1, 'End': 1,
            'Comma': 1, 'Period': 1,
            'Digit0': 1, 'Digit1': 1, 'Digit2': 1, 'Digit3': 1, 'Digit4': 1,
            'Digit5': 1, 'Digit6': 1, 'Digit7': 1, 'Digit8': 1, 'Digit9': 1,
            'Numpad0': 1, 'Numpad1': 1, 'Numpad2': 1, 'Numpad3': 1, 'Numpad4': 1,
            'Numpad5': 1, 'Numpad6': 1, 'Numpad7': 1, 'Numpad8': 1, 'Numpad9': 1
        };
        const blockSeekKey = function(e) {
            if (seekKeys[e.code]) {
                e.preventDefault();
                e.stopPropagation();
            }
        };
        el.addEventListener('keydown', blockSeekKey, true);
        wrapperEl.addEventListener('keydown', blockSeekKey, true);
    }

    // Teacher show-captions-by-default wiring. The FastPix Web Player exposes
    // no boolean attribute for this (CC9) — we flip the first
    // subtitles/captions text-track to showing once metadata is loaded. Student
    // keeps full control via the CC button.
    if (payload.default_show_captions) {
        const enableCaptions = function() {
            const media = el.media
                || (el.shadowRoot && (el.shadowRoot.querySelector('video') || el.shadowRoot.querySelector('audio')))
                || el.querySelector('video')
                || el;
            const tracks = media && media.textTracks;
            if (!tracks || tracks.length === 0) {
                return false;
            }
            for (let i = 0; i < tracks.length; i++) {
                const k = tracks[i].kind;
                if (k === 'captions' || k === 'subtitles') {
                    tracks[i].mode = 'showing';
                    return true;
                }
            }
            return false;
        };
        el.addEventListener('loadedmetadata', function() {
            // Track list isn't guaranteed populated on first loadedmetadata for
            // HLS (sidecar tracks load async), so retry briefly. Bounded —
            // gives up after ~3s.
            if (enableCaptions()) {
                return;
            }
            let attempts = 0;
            const poll = window.setInterval(function() {
                if (enableCaptions() || ++attempts > 15) {
                    window.clearInterval(poll);
                }
            }, 200);
        });
    }

    // Inline coverage tracker. Self-contained — does not depend on the
    // mod_fastpix/watch_tracker AMD module. The player element `el` is in
    // scope so there's no race / polling / MutationObserver. Edge cases per
    // the LMS Progress Tracking design doc — #17 backgrounded tab, #23
    // ended-snap, #25 replay sticky, #26 buffering, #27 fast playback,
    // #28 mobile pagehide, #29 loop mode.
    (function trackBars(player) {
        // The wrapper carries the coverage-tracker carrier attrs (data-threshold,
        // data-asset-duration) — they moved onto the wrapper when the old
        // below-player progress card (and its data-region="fastpix-bars" host)
        // was removed in favour of the overlaid status pill.
        const bars = wrapperEl;
        let duration = Number(bars.getAttribute('data-asset-duration')) || Number(payload.asset_duration_seconds) || 0;
        const threshold = Number(bars.getAttribute('data-threshold')) || Number(payload.completion_watch_percent) || 0;
        // The "Completed" indicator is shown only when the activity actually has
        // the watched-% completion condition. Without it, watch coverage must
        // not claim "Completed" (and no grade is written). Defaults to enabled
        // when the flag is absent, so older payloads keep working.
        const completionEnabled = payload.completion_enabled !== false;
        // Completed indicator — the single watch-status UI, overlaid top-right
        // inside the player. Hidden until completion; we reveal it by adding the
        // .is-complete class once the coverage threshold is crossed. Sticky
        // (matches the server-side has_completed-wins rule) — once revealed it is
        // never hidden again. No "watching"/"paused" states exist.
        const indicator = bars.querySelector('[data-region="fastpix-status"]');
        const cmid = Number(payload.cm_id);
        const sessionToken = payload.session_token;
        // Server is the source of truth for the interval set on resume — it
        // preserves gappy geometry (e.g. [[0,30],[60,90]]).
        let watched;
        try {
            watched = JSON.parse(payload.initial_intervals_json || '[]');
        } catch (e) {
            watched = [];
        }
        if (!Array.isArray(watched)) {
            watched = [];
        }
        let lastTime = 0;
        let isSeeking = false;
        let seekCount = 0;
        let hasCompleted = !!payload.has_completed;
        let endedFired = false;
        function addInterval(arr, a, b) {
            if (b <= a) {
                return arr;
            }
            const merged = arr.concat([[a, b]]).sort(function(x, y) {
                return x[0] - y[0];
            });
            const out = [];
            for (let i = 0; i < merged.length; i++) {
                const cur = merged[i];
                if (out.length && cur[0] - out[out.length - 1][1] <= 0.01) {
                    out[out.length - 1][1] = Math.max(out[out.length - 1][1], cur[1]);
                } else {
                    out.push([cur[0], cur[1]]);
                }
            }
            return out;
        }
        function coverageSeconds() {
            let s = 0;
            for (let i = 0; i < watched.length; i++) {
                s += watched[i][1] - watched[i][0];
            }
            return s;
        }
        // Reveal the "Completed" indicator (add .is-complete). Sticky — never
        // removed once set (mirrors the server has_completed-wins rule). The
        // indicator is the only watch-status UI; there is no other state.
        function showCompleted() {
            if (!indicator || !completionEnabled) {
                return;
            }
            indicator.classList.add('is-complete');
            // Browser-local sticky. Teacher/admin preview runs against a stub
            // attempt (id=0, has_completed never persisted, per D5), so the
            // server can't re-render .is-complete on reload. Remember the
            // crossed state per-cmid so the pill survives a refresh. Wrapped for
            // private mode / quota. No server/gradebook/D5 change — purely
            // cosmetic, and a no-op for students (server already renders
            // .is-complete and has_completed is persisted).
            try {
                window.localStorage.setItem('mod_fastpix_completed_' + cmid, '1');
            } catch (e) {
                // Private mode / quota — non-fatal.
            }
        }
        // Coverage is the source of truth for completion (UNCHANGED, S4). When
        // the threshold is crossed (or the server already said complete) we
        // reveal the sticky completed indicator and flush the 0→1 transition so
        // completion + grade fire without waiting for the 10s heartbeat. Below
        // threshold the indicator stays hidden.
        function repaint() {
            const cov = duration > 0 ? Math.min(100, (coverageSeconds() / duration) * 100) : 0;
            const crossed = (cov >= threshold) || hasCompleted;
            if (crossed) {
                const firstTransition = !hasCompleted;
                hasCompleted = true;
                showCompleted();
                if (firstTransition) {
                    persist(false);
                }
            }
        }
        player.addEventListener('loadedmetadata', function() {
            if (player.duration && isFinite(player.duration)) {
                duration = player.duration;
                bars.setAttribute('data-asset-duration', duration);
            }
            repaint();
        });
        player.addEventListener('play', function() {
            isSeeking = false;
            lastTime = Number(player.currentTime || 0);
        });
        player.addEventListener('seeking', function() {
            isSeeking = true;
        });
        player.addEventListener('seeked', function() {
            isSeeking = false;
            seekCount += 1;
            lastTime = Number(player.currentTime || 0);
            repaint();
        });
        player.addEventListener('waiting', function() {
            lastTime = Number(player.currentTime || 0);
        });
        player.addEventListener('pause', function() {
            // repaint() reveals the sticky completed indicator if the threshold
            // was just crossed; otherwise the indicator stays hidden.
            repaint();
            persist();
        });
        player.addEventListener('timeupdate', function() {
            const t = Number(player.currentTime || 0);
            if (isSeeking) {
                lastTime = t;
                repaint();
                return;
            }
            const delta = t - lastTime;
            if (delta < 0) {
                lastTime = t;
                repaint();
                return;
            }
            const rate = Number(player.playbackRate || 1);
            const boost = document.visibilityState === 'hidden' ? 2.0 : 1.0;
            const cap = Math.max(1.5, rate * 1.5) * boost;
            if (delta > 0 && delta < cap) {
                watched = addInterval(watched, lastTime, t);
            }
            lastTime = t;
            repaint();
        });
        player.addEventListener('ended', function() {
            if (isFinite(player.duration) && player.duration > 0) {
                // Snap the final interval up to duration so a natural end at
                // 99.97% (float precision) still rounds to 100% coverage. Does
                // NOT set hasCompleted — completion is coverage-only.
                watched = addInterval(watched, lastTime, player.duration);
                lastTime = player.duration;
            }
            endedFired = true;
            // Completion is decided in repaint() purely on coverage vs
            // threshold. Reaching the player's natural end is not a shortcut
            // to completion.
            repaint();
            persist();
        });
        function buildArgs() {
            return {
                cmid: cmid,
                session_token: sessionToken,
                watched_intervals: JSON.stringify(watched),
                current_position: Number(player.currentTime || 0),
                client_seek_count: seekCount,
                // Only true when the player's 'ended' event has fired — never
                // as a proxy for hasCompleted. Server uses this independently
                // of coverage% for the completion gate.
                ended_fired: endedFired
            };
        }
        function persist(useBeacon) {
            if (typeof M === 'undefined' || !M.cfg) {
                return;
            }
            const args = buildArgs();
            const snapshot = JSON.stringify(args);
            try {
                window.localStorage.setItem('mod_fastpix_attempt_' + cmid, snapshot);
            } catch (e) {
                // Private mode / quota — non-fatal.
            }
            // sendBeacon for unload — guaranteed delivery.
            if (useBeacon && navigator.sendBeacon) {
                const beaconBody = JSON.stringify([{
                    index: 0, methodname: 'mod_fastpix_record_view_progress', args: args
                }]);
                const beaconUrl = M.cfg.wwwroot + '/lib/ajax/service.php?sesskey='
                    + encodeURIComponent(M.cfg.sesskey);
                try {
                    navigator.sendBeacon(beaconUrl, new Blob([beaconBody], {type: 'application/json'}));
                    return;
                } catch (e) {
                    // Fall through to ajax path.
                }
            }
            // Normal heartbeat: use core/ajax — handles sesskey + format + retries.
            if (!window.require) {
                return;
            }
            window.require(['core/ajax'], function(Ajax) {
                Ajax.call([{
                    methodname: 'mod_fastpix_record_view_progress',
                    args: args
                }])[0].then(function(response) {
                    try {
                        window.localStorage.removeItem('mod_fastpix_attempt_' + cmid);
                    } catch (e) {
                        // Non-fatal.
                    }
                    if (response && response.completion_state === 'complete') {
                        hasCompleted = true;
                        repaint();
                    }
                    return null;
                }).catch(function(err) {
                    if (window.console) {
                        window.console.warn('[mod_fastpix] persist failed', err && err.errorcode, err && err.message);
                    }
                });
            });
        }
        window.setInterval(function() {
            persist(false);
        }, 10000);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                persist(true);
            }
        });
        window.addEventListener('pagehide', function() {
            persist(true);
        });
        // On-load reveal of the browser-local sticky completed flag. For
        // teacher/admin preview the server renders the pill WITHOUT .is-complete
        // (stub attempt, D5), so re-reveal it from localStorage. showCompleted()
        // is idempotent (just adds the sticky class), so for students — whose
        // server already renders .is-complete — this is a harmless no-op. Does
        // NOT touch hasCompleted, so the coverage tracker / record_view_progress
        // / fraud checks (S4) are untouched.
        try {
            if (window.localStorage.getItem('mod_fastpix_completed_' + cmid) === '1') {
                showCompleted();
            }
        } catch (e) {
            // Private mode / quota — non-fatal.
        }
        repaint();
    })(el);
};

/**
 * Locate the server-rendered wrapper and mount into it.
 *
 * @param {Object} payload canonical snake_case player payload
 */
export const init = (payload) => {
    const wrapperEl = document.querySelector('[data-region="fastpix-player-wrapper"]');
    if (!wrapperEl) {
        return;
    }
    // Idempotency guard — mirror the early-return on an already-mounted player.
    if (wrapperEl.querySelector('fastpix-player')) {
        return;
    }
    mount(wrapperEl, payload);
};
