// AMD module for the mod_fastpix activity edit form upload widget.
//
// Phase C unified panel:
// - Single panel showing drop zone + URL row at once (no pill toggle).
// - File drop or click → set hidden source_type='upload', auto-start upload.
// - URL + Upload button → set hidden source_type='urlpull', validate via
//   local_fastpix_create_url_pull_session.
// - Hidden source_type field is mutated via .value= and a dispatched
//   change event so any consumer (server-side validation, mform hideIf)
//   sees the active mode.
//
// Calls local_fastpix_create_upload_session / local_fastpix_create_url_pull_session
// via core/ajax (CC2). Per A2: zero direct calls to the video CDN — the
// signed upload URL is PUT to using fetch/XHR but the URL is supplied
// by local_fastpix, not constructed here.

import {call as ajaxCall} from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';

/**
 * FastPix resumable upload SDK — npm: @fastpix/resumable-uploads.
 *
 * Loaded via native ESM import() so it sits outside Moodle's RequireJS
 * context (same strategy view.php uses for @fastpix/fp-player). Splits
 * the file into chunkSize-KB pieces, uses the GCS two-step resumable
 * protocol (POST x-goog-resumable: start → PUT chunks with Content-Range),
 * retries failed chunks, and resumes from the last completed chunk on
 * network failure — so multi-GB uploads survive transient disconnects.
 *
 * Requires local_fastpix v >= 2026051201 (which sends X-Client-Type:
 * web-browser on /v1/on-demand/upload so FastPix returns a POST-signed
 * resumable URL — earlier versions returned a PUT-signed URL the SDK
 * cannot use and the initial POST would return 405).
 *
 * https://www.npmjs.com/package/@fastpix/resumable-uploads
 */
const UPLOAD_SDK_URL = 'https://cdn.jsdelivr.net/npm/@fastpix/resumable-uploads@latest/+esm';

/** Chunk size in KB (16 MB — matches the SDK default). */
const UPLOAD_CHUNK_KB = 16384;

/** Per-chunk retry attempts before the upload gives up. */
const UPLOAD_CHUNK_ATTEMPTS = 5;

const SELECTORS = {
    region:           '[data-region="fastpix-upload-widget"]',
    picker:           '[data-region="fastpix-upload-picker"]',
    dropzone:         '[data-region="fastpix-upload-dropzone"]',
    input:            '[data-region="fastpix-upload-input"]',
    browseLink:       '[data-action="fastpix-browse-trigger"]',
    progressWrap:     '[data-region="fastpix-upload-progress"]',
    progressBar:      '[data-region="fastpix-upload-bar"]',
    progressBarFill:  '[data-region="fastpix-upload-bar-fill"]',
    progressPct:      '[data-region="fastpix-upload-percent"]',
    status:           '[data-region="fastpix-upload-status"]',
    urlStatus:        '[data-region="fastpix-urlpull-status"]',
    sourceType:       '[name="source_type"]',
    sourceUrl:        '[name="source_url"]',
    validateBtn:      '[name="validate_url"]',
};

// Course context id received from init() — forwarded to local_fastpix's upload
// web services so they check mod/fastpix:uploadmedia against the course context
// (where editing teachers hold the capability), not the system context.
let widgetContextId = null;

const getSessionField = (fieldname) => document.querySelector(`input[name="${fieldname}"]`);

/**
 * Read the form's Media-settings fields into the create_upload_session args.
 * local_fastpix_create_upload_session consumes: contextid, title (required),
 * accesspolicy (required), captionsmode, languagecode — NOT filename/size.
 *
 * @param {File} file The file being uploaded (filename → fallback title).
 * @returns {Object} The web-service args.
 */
const gatherUploadArgs = (file) => {
    const nameEl = document.querySelector('input[name="name"]');
    let title = nameEl?.value ? nameEl.value.trim() : '';
    if (!title) {
        title = file?.name ? file.name.replace(/\.[^.]+$/, '') : 'Untitled video';
    }
    const apEl = document.querySelector('input[name="access_policy"]:checked');
    const accesspolicy = apEl ? apEl.value : 'private';
    let captionsmode = 'none';
    if (document.querySelector('input[name="captionsenabled"]:checked')) {
        const modeEl = document.querySelector('input[name="captionsmode"]:checked');
        captionsmode = (modeEl?.value === 'vtt') ? 'vtt' : 'auto';
    }
    const langEl = document.querySelector('select[name="languagecode"]');
    const languagecode = (captionsmode === 'auto' && langEl) ? langEl.value : '';
    return {
        contextid: widgetContextId,
        title: title,
        accesspolicy: accesspolicy,
        captionsmode: captionsmode,
        languagecode: languagecode,
    };
};

const setSourceType = (value) => {
    const el = document.querySelector(SELECTORS.sourceType);
    if (!el) { return; }
    el.value = value;
    el.dispatchEvent(new Event('change', {bubbles: true}));
};

const setStatus = (region, message, kind) => {
    const el = region.querySelector(SELECTORS.status);
    if (!el) { return; }
    el.hidden = false;
    el.textContent = message;
    el.className = `mt-3 small alert alert-${kind}`;
};

const clearStatus = (region) => {
    const el = region.querySelector(SELECTORS.status);
    if (!el) { return; }
    el.hidden = true;
    el.textContent = '';
    el.className = 'mt-3 small';
};

const setUrlStatus = (message, kind) => {
    const el = document.querySelector(SELECTORS.urlStatus);
    if (!el) { return; }
    if (!message) {
        el.textContent = '';
        el.className = 'fastpix-vs-url-status';
        return;
    }
    const states = {success: 'success', danger: 'danger', warning: 'warning'};
    const state = states[kind] || 'muted';
    el.textContent = message;
    el.className = 'fastpix-vs-url-status fastpix-vs-url-status--' + state;
};

/**
 * Lazy-load the FastPix resumable upload SDK. Cached on `window` so the
 * dynamic import only runs once per page lifecycle even if the user
 * uploads multiple files. @fastpix/resumable-uploads exposes
 * `Uploader.init(opts)` — a STATIC factory, not a constructor.
 *
 * @returns {Promise<{createUpload: (opts: Object) => Object}>}
 */
const loadUploadSdk = async () => {
    if (window.__fastpixUploadSdk) {
        return window.__fastpixUploadSdk;
    }
    const mod = await import(UPLOAD_SDK_URL);
    if (!mod.Uploader || typeof mod.Uploader.init !== 'function') {
        throw new Error('upload_sdk_no_init');
    }
    const adapter = {
        createUpload: (opts) => mod.Uploader.init(opts),
    };
    window.__fastpixUploadSdk = adapter;
    return adapter;
};

/**
 * Resumable + chunked upload to a FastPix POST-signed resumable URL.
 *
 *   - File is split into UPLOAD_CHUNK_KB chunks.
 *   - Each chunk PUTs to the session URI with Content-Range; failed
 *     chunks retry up to UPLOAD_CHUNK_ATTEMPTS times.
 *   - On a dropped connection, the SDK resumes from the last completed
 *     chunk instead of restarting the entire upload.
 *   - Progress is reported as a 0..100 percentage of total bytes.
 *
 * @param {File} file
 * @param {string} uploadUrl POST-signed resumable URL from local_fastpix.
 * @param {(percent: number) => void} onProgress
 * @returns {Promise<void>} resolves on `success`.
 */
const putToSignedUrl = async (file, uploadUrl, onProgress) => {
    let sdk;
    try {
        sdk = await loadUploadSdk();
    } catch (e) {
        if (window.console) {
            console.error('[mod_fastpix] failed to load upload SDK', e);
        }
        throw new Error('upload_sdk_load_failed');
    }

    return new Promise((resolve, reject) => {
        const upload = sdk.createUpload({
            endpoint:          uploadUrl,
            file:              file,
            chunkSize:         UPLOAD_CHUNK_KB,
            retryChunkAttempt: UPLOAD_CHUNK_ATTEMPTS,
        });

        upload.on('progress', (event) => {
            // FastPix resumable SDK reports the percentage at
            // event.detail.progress (0..100) — NOT event.detail. Reading
            // event.detail rounded an object to NaN, leaving the bar at 0%.
            const raw = (typeof event?.detail?.progress === 'number')
                ? event.detail.progress
                : 0;
            const pct = Math.max(0, Math.min(100, Math.round(raw)));
            onProgress(pct);
        });

        upload.on('success', () => {
            onProgress(100);
            resolve();
        });

        upload.on('error', (event) => {
            const detail = event.detail || {};
            const msg = detail.message || detail.toString?.() || 'upload_failed';
            reject(new Error(msg));
        });
    });
};

const showProgressUI = (region) => {
    // Keep the upload dropzone visible during upload (don't hide it) — just
    // reveal the progress region below it.
    const progress = region.querySelector(SELECTORS.progressWrap);
    if (progress) { progress.hidden = false; }
};

const showSuccessUI = (region) => {
    const progress = region.querySelector(SELECTORS.progressWrap);
    if (progress) { progress.hidden = true; }
    // Show just the completion state — not the filename.
    setStatus(region, 'Upload complete. Save the activity to finalise.', 'success');
};

const showDropzoneUI = (region) => {
    const dropzone = region.querySelector(SELECTORS.dropzone);
    if (dropzone) { dropzone.hidden = false; }
    const progress = region.querySelector(SELECTORS.progressWrap);
    if (progress) { progress.hidden = true; }
};

const handleFileSelected = async (region, sessionField, file) => {
    if (!file) { return; }
    setSourceType('upload');
    clearStatus(region);
    showProgressUI(region);

    let session;
    try {
        [session] = await Promise.all(ajaxCall([{
            methodname: 'local_fastpix_create_upload_session',
            args: gatherUploadArgs(file),
        }]));
    } catch (e) {
        Notification.exception(e);
        showDropzoneUI(region);
        setStatus(region, 'Failed to create upload session.', 'danger');
        return;
    }

    const bar = region.querySelector(SELECTORS.progressBar);
    const fill = region.querySelector(SELECTORS.progressBarFill);
    const pct = region.querySelector(SELECTORS.progressPct);

    try {
        // create_upload_session returns 'uploadurl' (signed PUT target) +
        // 'uploadid'. NOTE the no-underscore field names — they differ from
        // create_url_pull_session ('upload_url' / 'session_id').
        await putToSignedUrl(file, session.uploadurl, (percent) => {
            if (bar) { bar.value = percent; }
            if (fill) { fill.style.width = `${percent}%`; }
            if (pct) { pct.textContent = `${percent}%`; }
        });
    } catch (e) {
        showDropzoneUI(region);
        setStatus(region, `Upload failed: ${e.message}`, 'danger');
        return;
    }

    // Persist the upload-session reference for the activity row. create_upload_session
    // currently returns only 'uploadid' (a FastPix UUID), not the integer session id
    // that create_url_pull_session returns — prefer session_id if present.
    sessionField.value = String(session.session_id || session.uploadid || '');
    showSuccessUI(region);
};

const wireDropzone = (region, sessionField) => {
    const dropzone = region.querySelector(SELECTORS.dropzone);
    const input = region.querySelector(SELECTORS.input);
    const browse = region.querySelector(SELECTORS.browseLink);
    if (!dropzone || !input) { return; }

    // Browse link (rendered above the transparent file input z-index-wise).
    if (browse) {
        browse.addEventListener('click', (e) => {
            e.preventDefault();
            input.click();
        });
    }

    dropzone.addEventListener('click', (e) => {
        // The browse link handles its own click. The native input also catches
        // clicks directly via z-index. Avoid double-trigger.
        if (e.target === input || e.target === browse) { return; }
        input.click();
    });

    dropzone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            input.click();
        }
    });

    const setDragging = (on) => {
        dropzone.classList.toggle('is-dragging', !!on);
    };

    ['dragenter', 'dragover'].forEach((ev) => dropzone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
        setDragging(true);
    }));
    ['dragleave', 'dragend'].forEach((ev) => dropzone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
        setDragging(false);
    }));
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        setDragging(false);
        const file = e.dataTransfer?.files?.[0];
        if (file) {
            handleFileSelected(region, sessionField, file);
        }
    });

    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (file) {
            handleFileSelected(region, sessionField, file);
        }
    });
};

const validateUrl = async (sessionField, button) => {
    const urlInput = document.querySelector(SELECTORS.sourceUrl);
    if (!urlInput?.value) {
        setUrlStatus('Enter a URL first.', 'warning');
        return;
    }

    setSourceType('urlpull');
    setUrlStatus('Uploading…', 'muted');
    if (button) { button.disabled = true; }

    let session;
    try {
        [session] = await Promise.all(ajaxCall([{
            methodname: 'local_fastpix_create_url_pull_session',
            args: { source_url: urlInput.value, contextid: widgetContextId },
        }]));
    } catch (e) {
        Notification.exception(e);
        setUrlStatus('URL rejected.', 'danger');
        if (button) { button.disabled = false; }
        return;
    }

    sessionField.value = String(session.session_id);
    setUrlStatus('✓ URL accepted. Save the activity to finalise.', 'success');
    if (button) { button.disabled = false; }
};

const renderInto = async (region) => {
    const {html, js} = await Templates.renderForPromise('mod_fastpix/upload_widget', {});
    Templates.replaceNodeContents(region, html, js);
};

export const init = async (config) => {
    widgetContextId = config.contextId;
    const region = document.querySelector(SELECTORS.region);
    if (!region) { return; }

    const sessionField = getSessionField(config.fieldnameSession);
    if (!sessionField) { return; }

    // Wire the URL Upload button at outer-document scope BEFORE the inner
    // template renders, so the listener is in place even if the user
    // somehow clicks before render completes.
    const validateBtn = document.querySelector(SELECTORS.validateBtn);
    if (validateBtn && validateBtn.dataset.fastpixWired !== '1') {
        validateBtn.dataset.fastpixWired = '1';
        validateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            validateUrl(sessionField, validateBtn);
        });
    }

    // Editing the URL invalidates a prior validate (so the form doesn't
    // submit a stale upload_session_id against a new URL).
    const urlInput = document.querySelector(SELECTORS.sourceUrl);
    if (urlInput && urlInput.dataset.fastpixWired !== '1') {
        urlInput.dataset.fastpixWired = '1';
        urlInput.addEventListener('input', () => {
            if (sessionField) { sessionField.value = ''; }
            setUrlStatus('', 'muted');
        });
    }

    await renderInto(region);

    // After render, re-query for URL input + validate button (template may
    // replace them) and wire dropzone.
    const renderedUrlInput = document.querySelector(SELECTORS.sourceUrl);
    const renderedValidateBtn = document.querySelector(SELECTORS.validateBtn);
    if (renderedValidateBtn && renderedValidateBtn.dataset.fastpixWired !== '1') {
        renderedValidateBtn.dataset.fastpixWired = '1';
        renderedValidateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            validateUrl(sessionField, renderedValidateBtn);
        });
    }
    if (renderedUrlInput && renderedUrlInput.dataset.fastpixWired !== '1') {
        renderedUrlInput.dataset.fastpixWired = '1';
        renderedUrlInput.addEventListener('input', () => {
            if (sessionField) { sessionField.value = ''; }
            setUrlStatus('', 'muted');
        });
    }

    wireDropzone(region, sessionField);
};
