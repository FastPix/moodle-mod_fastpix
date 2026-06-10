// AMD module: custom .vtt captions dropzone for the mod_fastpix activity form.
//
// Uploads the selected WebVTT file to a Moodle draft area via the repository
// upload AJAX endpoint (the same endpoint the core filepicker uses). The hidden
// captionsfile field already carries the draft itemid through the normal form
// submit; lib.php then copies the draft area into the activity's captions
// filearea on save.
//
// Per A2: mod_fastpix makes zero FastPix HTTP calls — this posts ONLY to
// Moodle's own /repository/repository_ajax.php, never to the video CDN.

import Config from 'core/config';
import Notification from 'core/notification';

const SELECTORS = {
    dropzone: '[data-region="fastpix-vtt-dropzone"]',
    input:    '[data-region="fastpix-vtt-input"]',
    browse:   '[data-action="fastpix-vtt-browse"]',
    status:   '[data-region="fastpix-vtt-status"]',
};

let cfg = {itemid: 0, repoId: 0, contextId: 0, strings: {}};

const setStatus = (dropzone, message, kind) => {
    const el = dropzone.querySelector(SELECTORS.status);
    if (!el) {
        return;
    }
    el.hidden = !message;
    el.textContent = message || '';
    el.className = 'fastpix-vtt-status' + (kind ? ' fastpix-vtt-status--' + kind : '');
};

const uploadFile = async (dropzone, file) => {
    if (!file) {
        return;
    }
    if (!/\.vtt$/i.test(file.name)) {
        setStatus(dropzone, cfg.strings.badtype, 'danger');
        return;
    }
    setStatus(dropzone, cfg.strings.uploading, 'muted');

    const formData = new FormData();
    formData.append('sesskey', Config.sesskey);
    formData.append('repo_id', cfg.repoId);
    formData.append('itemid', cfg.itemid);
    formData.append('ctx_id', cfg.contextId);
    formData.append('savepath', '/');
    formData.append('title', file.name);
    formData.append('overwrite', 1);
    formData.append('repo_upload_file', file);

    try {
        const response = await fetch(
            Config.wwwroot + '/repository/repository_ajax.php?action=upload',
            {method: 'POST', body: formData}
        );
        const data = await response.json();
        if (!data || data.error || data.errorcode) {
            setStatus(dropzone, (data && data.error) || cfg.strings.uploaderror, 'danger');
            return;
        }
        dropzone.classList.add('has-file');
        setStatus(dropzone, data.file || file.name, 'success');
    } catch (e) {
        Notification.exception(e);
        setStatus(dropzone, cfg.strings.uploaderror, 'danger');
    }
};

export const init = (config) => {
    cfg = config || cfg;
    const dropzone = document.querySelector(SELECTORS.dropzone);
    if (!dropzone) {
        return;
    }
    const input = dropzone.querySelector(SELECTORS.input);
    const browse = dropzone.querySelector(SELECTORS.browse);

    if (browse) {
        browse.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (input) {
                input.click();
            }
        });
    }

    dropzone.addEventListener('click', (e) => {
        if (e.target === browse || (browse && browse.contains(e.target))) {
            return;
        }
        if (input) {
            input.click();
        }
    });

    dropzone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (input) {
                input.click();
            }
        }
    });

    if (input) {
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (file) {
                uploadFile(dropzone, file);
            }
        });
    }

    ['dragenter', 'dragover'].forEach((ev) => dropzone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('is-dragging');
    }));
    ['dragleave', 'dragend'].forEach((ev) => dropzone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('is-dragging');
    }));
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('is-dragging');
        const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (file) {
            uploadFile(dropzone, file);
        }
    });
};
