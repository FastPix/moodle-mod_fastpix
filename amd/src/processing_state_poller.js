// Processing-state poller (§13.2). Plain HTTP polling — no WebSocket / SSE /
// long-polling. Intentionally simple.
//
// Polls mod_fastpix_get_player_state directly (our own readiness oracle), then
// swaps the processing region contents IN PLACE — no window.location.reload.
// We do NOT gate on local_fastpix_get_upload_status: its upload-session 'state'
// can stay 'pending' even after the asset is ready, so gating on it leaves the
// player stuck on the processing screen. get_player_state resolves the asset
// (status + playback id) for this activity regardless of the session state:
//   * ready=true  → render mod_fastpix/player_wrapper, mount the player.
//   * ready=false + error_key → render mod_fastpix/error, stop.
//   * else (still transcoding) → keep polling until MAX_POLLS, then fallback.
//
// The poll loop is bounded by MAX_POLLS, and init() is guarded by initWired so
// a partial-swap re-init can't start a second timer. (An earlier sessionStorage
// "cycle cap" was removed: the upload-session state is 'created' from the start,
// never 'pending', so it was consumed on every poll and killed the poller ~50s
// in — long before most videos finish transcoding.)

import {call as fetchMany} from 'core/ajax';
import Templates from 'core/templates';
// Static AMD dep — load the player as a normal Moodle AMD module, NOT via
// native dynamic import('mod_fastpix/player'). Native import() resolves the
// argument as a URL path (→404) instead of an AMD module id, so the player
// never mounts on the processing→ready swap. Eager import is fine: player.js
// only DEFINES mount/init and executes nothing at import time.
import * as Player from 'mod_fastpix/player';

const POLL_INTERVAL_MS = 5000;
const FIRST_POLL_DELAY_MS = 3000;
const MAX_POLLS = 60;             // ~5 minutes of polling — covers transcode + cron latency

let pollsRemaining = MAX_POLLS;
let timer = null;

const revealFallback = () => {
    const node = document.querySelector('[data-region="fastpix-processing-fallback"]');
    if (node) {
        node.removeAttribute('hidden');
    }
};

const stop = () => {
    if (timer !== null) {
        globalThis.clearTimeout(timer);
        timer = null;
    }
};

// Locate the processing region node whose contents we replace with the
// rendered player wrapper (or error). processing.mustache root.
const processingNode = () => document.querySelector('[data-region="fastpix-processing"]');

const swapToPlayer = (response) => {
    const node = processingNode();
    if (!node) {
        return;
    }
    // renderForPromise (NOT render): native-promise API that resolves with a
    // single {html, js} object. Templates.render resolves with html only, so a
    // `.then((html, js) =>` leaves js undefined and skips template JS.
    //
    // Swap-bug fix (approach b): do NOT hand the rendered HTML string to
    // jQuery / Templates.replaceNodeContents. If the render carries any leading
    // text (stray comment leak, whitespace), jQuery parses it as a CSS selector
    // and throws "unrecognized expression". Instead, parse via a <template>
    // element (inert, robust to leading whitespace/comments) and replace the
    // processing region's children with the resulting node directly.
    Templates.renderForPromise('mod_fastpix/player_wrapper', response).then(({html, js}) => {
        const tmp = document.createElement('template');
        tmp.innerHTML = html.trim();
        // player_wrapper emits multiple top-level siblings (the player mount div
        // + the progress card host). Move ALL parsed element nodes in.
        if (!tmp.content.firstElementChild) {
            globalThis.console.error('[mod_fastpix] swap: empty render');
            return null;
        }
        node.replaceChildren(tmp.content);
        // Run any template-declared JS now that the nodes are in the DOM.
        if (js) {
            Templates.runTemplateJS(js);
        }
        const wrapperEl = node.querySelector('[data-region="fastpix-player-wrapper"]');
        if (!wrapperEl) {
            globalThis.console.error('[mod_fastpix] swap: player wrapper not found; leaving placeholder');
            return null;
        }
        return Player.mount(wrapperEl, response);
    }).catch((err) => {
        if (globalThis.console) {
            globalThis.console.error('[mod_fastpix] player swap failed', err?.message);
        }
    });
};

const swapToError = (errorKey) => {
    const node = processingNode();
    if (!node) {
        return;
    }
    const context = {
        reason_key: errorKey,
        is_videounavailable: errorKey === 'videounavailable',
        is_drm_unsupported: errorKey === 'drm_unsupported',
        is_capability_lost: errorKey === 'capability_lost',
    };
    Templates.renderForPromise('mod_fastpix/error', context).then(({html, js}) => {
        Templates.replaceNodeContents(node, html, js);
        return null;
    }).catch((err) => {
        if (globalThis.console) {
            globalThis.console.error('[mod_fastpix] error swap failed', err?.message);
        }
    });
};

const scheduleTick = (cmid) => {
    if (pollsRemaining <= 0) {
        revealFallback();
        return;
    }
    timer = globalThis.setTimeout(() => tick(cmid), POLL_INTERVAL_MS);
};

// Poll our own readiness oracle directly. get_player_state resolves the asset
// (status + playback id) for this activity. We deliberately do NOT gate on
// local_fastpix_get_upload_status: the upload-session 'state' can stay 'pending'
// even after the asset is ready, which left the player stuck on the processing
// screen. ready → swap in place; terminal error → error state; else keep polling
// until MAX_POLLS.
const tick = (cmid) => {
    pollsRemaining -= 1;
    fetchMany([{
        methodname: 'mod_fastpix_get_player_state',
        args: {cmid: cmid},
    }])[0].then((response) => {
        // Tolerant truthy: PARAM_BOOL may arrive as JSON true OR 1 over AJAX.
        const ready = response && (response.ready === true || response.ready === 1);
        if (ready) {
            stop();
            swapToPlayer(response);
            return null;
        }
        if (response?.error_key) {
            stop();
            swapToError(response.error_key);
            return null;
        }
        scheduleTick(cmid);
        return null;
    }).catch(() => {
        scheduleTick(cmid);
    });
};

// Idempotency guard — if init() is called more than once on the same DOM
// (re-render, hot-reload, etc.) we must not schedule duplicate timers.
let initWired = false;

export const init = (cmid) => {
    if (initWired) {
        return;
    }
    initWired = true;
    stop();
    pollsRemaining = MAX_POLLS;
    timer = globalThis.setTimeout(() => tick(cmid), FIRST_POLL_DELAY_MS);
};
