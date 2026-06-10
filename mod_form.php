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
 * Activity settings form for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * The settings form for creating and editing a FastPix activity.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_fastpix_mod_form extends moodleform_mod {
    /** FastPix-supported caption languages (stable). */
    const CAPTION_LANGS_SUPPORTED = ['en', 'es', 'it', 'pt', 'de', 'fr'];

    /** FastPix beta caption languages — tagged "(Beta)" in the picker. */
    const CAPTION_LANGS_BETA = [
        'pl', 'ru', 'nl', 'ca', 'tr', 'sv', 'uk', 'no', 'fi', 'sk',
        'el', 'cs', 'hr', 'da', 'ro', 'bg',
    ];

    /**
     * Build the language <select> options for auto-generated captions:
     * supported languages first, then beta languages tagged "(Beta)".
     *
     * @return array<string,string> code => display label
     */
    public static function caption_language_options(): array {
        $options = [];
        foreach (self::CAPTION_LANGS_SUPPORTED as $code) {
            $options[$code] = get_string('lang_' . $code, 'mod_fastpix');
        }
        foreach (self::CAPTION_LANGS_BETA as $code) {
            $options[$code] = get_string('lang_' . $code, 'mod_fastpix')
                . ' ' . get_string('captions_beta_tag', 'mod_fastpix');
        }
        return $options;
    }

    /**
     * Define the activity settings form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('activityname', 'mod_fastpix'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Media settings sit above the video source: teachers pick protection +
        // captions before uploading, matching the design reference order.
        $this->add_media_settings($mform);

        $mform->addElement('header', 'videosource', get_string('videosource', 'mod_fastpix'));

        // Intro card under the section header: a lavender rounded card with a
        // violet icon tile + heading + description. Inline SVG cloud-upload
        // (stroke=currentColor → colour comes from CSS .fastpix-vs-intro-icon)
        // — FontAwesome/Tabler glyph names render inconsistently here.
        $vsicon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M16 16l-4-4-4 4"/><path d="M12 12v9"/>'
            . '<path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>';
        $vscard = html_writer::div(
            html_writer::div($vsicon, 'fastpix-vs-intro-icon')
            . html_writer::div(
                html_writer::tag(
                    'div',
                    s(get_string('videosource_card_title', 'mod_fastpix')),
                    ['class' => 'fastpix-vs-intro-title']
                )
                . html_writer::tag(
                    'div',
                    s(get_string('videosource_intro', 'mod_fastpix')),
                    ['class' => 'fastpix-vs-intro-desc']
                ),
                'fastpix-vs-intro-body'
            ),
            'fastpix-vs-intro'
        );
        $mform->addElement('html', $vscard);

        // The source_type is a registered hidden mform element. AMD sets its value
        // when the user touches one input or the other (file selected →
        // 'upload'; URL Upload button clicked → 'urlpull'). PHP validation
        // reads $data['source_type'] unchanged.
        $mform->addElement('hidden', 'source_type', 'upload');
        $mform->setType('source_type', PARAM_ALPHA);

        // Single unified upload panel — mustache template renders both the
        // drop zone AND the URL row into this div, gradient-framed per mockup.
        $mform->addElement('html', '<div data-region="fastpix-upload-widget"
            data-fieldname-session="upload_session_id"></div>');

        $mform->addElement('hidden', 'upload_session_id');
        $mform->setType('upload_session_id', PARAM_INT);

        $mform->addElement('header', 'playbackoptions', get_string('playbackoptions', 'mod_fastpix'));

        // Resolve current values for edit mode so the cards render with the
        // correct checked state on first paint. Create mode falls through to
        // the conservative 0/0 default.
        $noskipchecked   = 0;
        $captionschecked = 0;
        if (!empty($this->_instance)) {
            global $DB;
            $existing = $DB->get_record(
                'fastpix',
                ['id' => $this->_instance],
                'no_skip_required, default_show_captions'
            );
            if ($existing) {
                $noskipchecked   = !empty($existing->no_skip_required) ? 1 : 0;
                $captionschecked = !empty($existing->default_show_captions) ? 1 : 0;
            }
        }

        // Intro card — film-strip icon + section description.
        $introtitle = s(get_string('playbackoptions_card_title', 'mod_fastpix'));
        $introdesc  = s(get_string('playbackoptions_intro', 'mod_fastpix'));
        $filmsvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="4" width="18" height="16" rx="2"/>
                <path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/>
            </svg>';
        $mform->addElement(
            'html',
            '<div class="fastpix-pb-intro">
                <div class="fastpix-pb-intro-icon">' . $filmsvg . '</div>
                <div class="fastpix-pb-intro-body">
                    <div class="fastpix-pb-intro-title">' . $introtitle . '</div>
                    <p class="fastpix-pb-intro-desc">' . $introdesc . '</p>
                </div>
            </div>'
        );

        // Options panel — two rows separated by a hairline divider.
        $locksvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="4" y="11" width="16" height="10" rx="2"/>
                <path d="M8 11V7a4 4 0 0 1 8 0v4"/>
            </svg>';
        $ccsvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="14" rx="3"/>
                <path d="M10 10a2 2 0 0 0-2 2v0a2 2 0 0 0 2 2M16 10a2 2 0 0 0-2 2v0a2 2 0 0 0 2 2"/>
            </svg>';

        $noskiplabel = s(get_string('noskip', 'mod_fastpix'));
        $noskipdesc  = s(get_string('noskip_desc', 'mod_fastpix'));
        $capslabel   = s(get_string('autocaptions', 'mod_fastpix'));
        $capsdesc    = s(get_string('autocaptions_desc', 'mod_fastpix'));

        // Each card row renders a hidden 0-fallback followed by the visible
        // checkbox — same wire format mform's advcheckbox emits, so the save
        // path picks the value up identically. Native input lets us style
        // freely without fighting mform's renderer.
        $mform->addElement(
            'html',
            '<div class="fastpix-pb-options">
                <div class="fastpix-pb-row">
                    <div class="fastpix-pb-row-icon">' . $locksvg . '</div>
                    <div class="fastpix-pb-row-label">' . $noskiplabel . '</div>
                    <div class="fastpix-pb-row-check">
                        <input type="hidden" name="no_skip_required" value="0">
                        <input type="checkbox" id="id_no_skip_required"
                               name="no_skip_required" value="1"' .
                               ($noskipchecked ? ' checked' : '') . '>
                    </div>
                    <div class="fastpix-pb-row-desc">' . $noskipdesc . '</div>
                </div>
                <div class="fastpix-pb-row">
                    <div class="fastpix-pb-row-icon">' . $ccsvg . '</div>
                    <div class="fastpix-pb-row-label">' . $capslabel . '</div>
                    <div class="fastpix-pb-row-check">
                        <input type="hidden" name="default_show_captions" value="0">
                        <input type="checkbox" id="id_default_show_captions"
                               name="default_show_captions" value="1"' .
                               ($captionschecked ? ' checked' : '') . '>
                    </div>
                    <div class="fastpix-pb-row-desc">' . $capsdesc . '</div>
                </div>
            </div>'
        );

        global $PAGE, $COURSE;
        $cmid = !empty($this->_cm->id) ? (int)$this->_cm->id : 0;
        // The upload widget forwards this context id to local_fastpix's upload
        // web services, which check mod/fastpix:uploadmedia against it. That
        // capability is granted to editingteacher at CONTEXT_COURSE (db/access.php),
        // so the COURSE context is correct here — the previous CONTEXT_SYSTEM id
        // denied editing teachers. $COURSE is always populated on this form for
        // both activity create and edit.
        $PAGE->requires->js_call_amd('mod_fastpix/upload_widget', 'init', [[
            'contextId'         => \context_course::instance($COURSE->id)->id,
            'fieldnameSession'  => 'upload_session_id',
            'cmid'              => $cmid,
        ]]);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * The "Media settings" section: access policy + captions & transcript.
     *
     * Rendered as custom HTML (segmented access-policy control + a captions card
     * with a pill toggle, auto/vtt tabs, language picker, and a .vtt dropzone) to
     * match the design reference. The controls are real form inputs (radios /
     * checkbox / select / file) styled via CSS — accessible and read server-side
     * via optional_param() (lib.php + validate_fastpix_rules), NOT registered
     * mform elements. Toggle/tab show-hide is CSS-only (:checked ~ siblings); the
     * .vtt dropzone uploads to a Moodle draft area via the mod_fastpix/captions_upload
     * AMD module. mod_fastpix makes no FastPix HTTP call here (A2).
     *
     * @param \MoodleQuickForm $mform The form being built.
     * @return void
     */
    protected function add_media_settings($mform): void {
        global $CFG, $DB, $PAGE;
        require_once($CFG->dirroot . '/repository/lib.php');

        $mform->addElement('header', 'mediasettings', get_string('mediasettings', 'mod_fastpix'));

        // Decorative intro card (matches the other section intros in this form).
        $msicon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="3"/>'
            . '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 '
            . '1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 '
            . '1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 '
            . '4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 '
            . '0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 '
            . '1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 '
            . '1.65 0 0 0-1.51 1z"/></svg>';
        $mscard = html_writer::div(
            html_writer::div($msicon, 'fastpix-ms-ico')
            . html_writer::div(
                html_writer::tag(
                    'div',
                    s(get_string('mediasettings_card_title', 'mod_fastpix')),
                    ['class' => 'fastpix-ms-title']
                )
                . html_writer::tag(
                    'div',
                    s(get_string('mediasettings_intro', 'mod_fastpix')),
                    ['class' => 'fastpix-ms-sub']
                ),
                'fastpix-ms-body'
            ),
            'fastpix-ms-head'
        );
        $mform->addElement('html', $mscard);

        // Current stored values (edit mode) for first-paint state.
        $currentpolicy = 'private';
        $currentmode   = 'none';
        $currentlang   = 'en';
        if (!empty($this->_instance)) {
            $existing = $DB->get_record(
                'fastpix',
                ['id' => $this->_instance],
                'access_policy, captions_mode, language_code'
            );
            if ($existing) {
                if (in_array($existing->access_policy, ['private', 'public', 'drm'], true)) {
                    $currentpolicy = $existing->access_policy;
                }
                $currentmode = $existing->captions_mode ?: 'none';
                $currentlang = $existing->language_code ?: 'en';
            }
        }

        // Access policy: custom-HTML segmented control (read via optional_param).
        $mform->addElement('html', $this->render_access_policy_control($currentpolicy));

        // Captions & transcript: custom-HTML card.
        // Prepare a draft file area for the .vtt (loads any stored file on edit);
        // the AMD dropzone uploads into it, and the hidden captionsfile field
        // carries the draft itemid through the normal form submit.
        $context = !empty($this->context) ? $this->context : \context_system::instance();
        // Reuse the submitted draft itemid on a validation re-render so an
        // already-uploaded .vtt survives; 0 on first display mints a fresh one.
        $draftitemid = file_get_submitted_draft_itemid('captionsfile');
        file_prepare_draft_area(
            $draftitemid,
            $context->id,
            'mod_fastpix',
            'captions',
            0,
            ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['.vtt']]
        );
        $existingname = '';
        $draftinfo = file_get_drafarea_files($draftitemid, '/');
        if (!empty($draftinfo->list)) {
            $existingname = (string)reset($draftinfo->list)->filename;
        }

        // The Moodle "Upload" repository instance backs the dropzone's AJAX POST.
        $repoid = 0;
        $uploadrepos = repository::get_instances(['currentcontext' => $context, 'type' => 'upload']);
        if ($uploadrepos) {
            $repoid = (int)reset($uploadrepos)->id;
        }

        $mform->addElement('html', $this->render_captions_control(
            $currentmode,
            $currentlang,
            $draftitemid,
            $existingname
        ));

        $PAGE->requires->js_call_amd('mod_fastpix/captions_upload', 'init', [[
            'itemid'    => $draftitemid,
            'repoId'    => $repoid,
            'contextId' => $context->id,
            'strings'   => [
                'uploading' => get_string('captions_uploading', 'mod_fastpix'),
                'uploaderror' => get_string('captions_uploaderror', 'mod_fastpix'),
                'badtype'   => get_string('captions_badtype', 'mod_fastpix'),
            ],
        ]]);
    }

    /**
     * Render the access-policy segmented control as custom HTML.
     *
     * Real <input type="radio"> elements (name="access_policy") styled via CSS
     * into the reference's three-up segmented buttons — accessible (for=label,
     * keyboard-navigable, role="radiogroup") and JS-free. The helper line under
     * the buttons swaps with the selection via a CSS :checked ~ sibling rule,
     * which is why the inputs precede the help paragraphs in source order.
     *
     * @param string $current The currently selected policy (private|public|drm).
     * @return string The control's HTML.
     */
    protected function render_access_policy_control(string $current): string {
        $policies = ['private', 'public', 'drm'];

        $seg = '';
        foreach ($policies as $policy) {
            $id = 'fastpix_ap_' . $policy;
            $attrs = [
                'class' => 'fastpix-ap-input',
                'type'  => 'radio',
                'name'  => 'access_policy',
                'id'    => $id,
                'value' => $policy,
            ];
            if ($policy === $current) {
                $attrs['checked'] = 'checked';
            }
            $seg .= html_writer::empty_tag('input', $attrs);
            $seg .= html_writer::tag(
                'label',
                s(get_string('accesspolicy_' . $policy, 'mod_fastpix')),
                ['class' => 'fastpix-ap-btn', 'for' => $id]
            );
        }
        // Helper lines AFTER the inputs (sibling order matters for the CSS swap).
        foreach ($policies as $policy) {
            $seg .= html_writer::tag(
                'p',
                s(get_string('accesspolicy_' . $policy . '_help', 'mod_fastpix')),
                ['class' => 'fastpix-ap-help', 'data-policy' => $policy]
            );
        }

        $label = html_writer::tag(
            'div',
            s(get_string('accesspolicy', 'mod_fastpix')),
            ['class' => 'fastpix-ap-label']
        );
        return html_writer::div(
            $label . html_writer::div(
                $seg,
                'fastpix-ap-seg',
                ['role' => 'radiogroup', 'aria-label' => get_string('accesspolicy', 'mod_fastpix')]
            ),
            'fastpix-ap'
        );
    }

    /**
     * Render the captions & transcript card as custom HTML.
     *
     * Real inputs (a checkbox toggle, auto/vtt radios, a language <select>, a
     * .vtt file input) styled via CSS to match the reference. The toggle and
     * tabs drive show/hide entirely through CSS :checked ~ sibling rules, which
     * is why all three inputs precede the head/body in source order. Values are
     * read server-side via optional_param(); the .vtt is uploaded to a draft
     * area by the mod_fastpix/captions_upload AMD module and its itemid rides
     * along in the hidden captionsfile field.
     *
     * @param string $mode Stored caption mode: none | auto | vtt.
     * @param string $lang Stored language code (auto mode).
     * @param int $draftitemid Draft area itemid backing the dropzone.
     * @param string $existingname Filename already in the draft area, or ''.
     * @return string The card HTML.
     */
    protected function render_captions_control(string $mode, string $lang, int $draftitemid, string $existingname): string {
        $enabled   = ($mode !== 'none');
        $activetab = ($mode === 'vtt') ? 'vtt' : 'auto';

        // State-bearing inputs first (CSS :checked ~ targets later siblings).
        // The hidden 0 precedes the checkbox so a ticked box wins on POST.
        $inputs = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'captionsenabled', 'value' => '0']);
        $togattrs = [
            'type'       => 'checkbox',
            'id'         => 'fastpix_cap_toggle',
            'class'      => 'fastpix-cap-toggle-input',
            'name'       => 'captionsenabled',
            'value'      => '1',
            'aria-label' => get_string('captions', 'mod_fastpix'),
        ];
        if ($enabled) {
            $togattrs['checked'] = 'checked';
        }
        $inputs .= html_writer::empty_tag('input', $togattrs);
        foreach (['auto', 'vtt'] as $m) {
            $rattrs = [
                'type'  => 'radio',
                'id'    => 'fastpix_cap_' . $m,
                'class' => 'fastpix-cap-mode-input',
                'name'  => 'captionsmode',
                'value' => $m,
            ];
            if ($m === $activetab) {
                $rattrs['checked'] = 'checked';
            }
            $inputs .= html_writer::empty_tag('input', $rattrs);
        }

        // Header row: icon tile + title/subtitle + pill toggle.
        $ccico = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
            . '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm3.5 5A2.5 2.5 0 0 0 5 '
            . '11.5v1A2.5 2.5 0 0 0 7.5 15a2.5 2.5 0 0 0 2.45-2H8.4a1 1 0 0 1-.9.5 1 1 0 0 1-1-1v-1a1 1 0 0 1 1-1 1 1 0 '
            . '0 1 .9.5h1.55A2.5 2.5 0 0 0 7.5 9zm8 0a2.5 2.5 0 0 0-2.5 2.5v1A2.5 2.5 0 0 0 15.5 15a2.5 2.5 0 0 0 '
            . '2.45-2H16.4a1 1 0 0 1-.9.5 1 1 0 0 1-1-1v-1a1 1 0 0 1 1-1 1 1 0 0 1 .9.5h1.55A2.5 2.5 0 0 0 15.5 9z"/></svg>';
        $head = html_writer::div(
            html_writer::div($ccico, 'fastpix-cap-ico')
            . html_writer::div(
                html_writer::tag('div', s(get_string('captions', 'mod_fastpix')), ['class' => 'fastpix-cap-title'])
                . html_writer::tag('div', s(get_string('captions_desc', 'mod_fastpix')), ['class' => 'fastpix-cap-sub']),
                'fastpix-cap-headtext'
            )
            . html_writer::tag('label', '', [
                'class'       => 'fastpix-cap-toggle',
                'for'         => 'fastpix_cap_toggle',
                'aria-hidden' => 'true',
            ]),
            'fastpix-cap-head'
        );

        // Tabs.
        $tabs = html_writer::div(
            html_writer::tag('label', s(get_string('captionsmode_auto', 'mod_fastpix')), [
                'class' => 'fastpix-cap-tab', 'for' => 'fastpix_cap_auto',
            ])
            . html_writer::tag('label', s(get_string('captionsmode_vtt', 'mod_fastpix')), [
                'class' => 'fastpix-cap-tab', 'for' => 'fastpix_cap_vtt',
            ]),
            'fastpix-cap-tabs'
        );

        // Auto pane: full-width language picker.
        $options = '';
        foreach (self::caption_language_options() as $code => $labeltext) {
            $oattrs = ['value' => $code];
            if ($code === $lang) {
                $oattrs['selected'] = 'selected';
            }
            $options .= html_writer::tag('option', s($labeltext), $oattrs);
        }
        $autopane = html_writer::div(
            html_writer::tag('label', s(get_string('captionslanguage', 'mod_fastpix')), [
                'class' => 'fastpix-cap-mini', 'for' => 'fastpix_lang',
            ])
            . html_writer::tag('select', $options, [
                'id' => 'fastpix_lang', 'name' => 'languagecode', 'class' => 'fastpix-cap-select',
            ])
            . html_writer::tag('p', s(get_string('captionslanguage_help', 'mod_fastpix')), ['class' => 'fastpix-cap-help']),
            'fastpix-cap-pane fastpix-cap-pane-auto'
        );

        // VTT pane: custom dropzone uploaded by mod_fastpix/captions_upload.
        $uploadico = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
            . '<path d="M11 16V7.85l-2.6 2.6L7 9l5-5 5 5-1.4 1.45L13 7.85V16zM6 20a2 2 0 0 1-2-2v-3h2v3h12v-3h2v3a2 '
            . '2 0 0 1-2 2z"/></svg>';
        $browse = html_writer::tag('a', s(get_string('captionsfile_browse', 'mod_fastpix')), [
            'href' => '#', 'data-action' => 'fastpix-vtt-browse', 'class' => 'fastpix-vtt-browse',
        ]);
        $statusattrs = ['class' => 'fastpix-vtt-status', 'data-region' => 'fastpix-vtt-status'];
        if ($existingname === '') {
            $statusattrs['hidden'] = 'hidden';
        }
        $dropzone = html_writer::div(
            html_writer::empty_tag('input', [
                'type' => 'file', 'class' => 'fastpix-vtt-file',
                'data-region' => 'fastpix-vtt-input', 'accept' => '.vtt', 'hidden' => 'hidden',
            ])
            . html_writer::div($uploadico, 'fastpix-vtt-ico')
            . html_writer::tag(
                'div',
                s(get_string('captionsfile_droptext', 'mod_fastpix')) . ' ' . $browse,
                ['class' => 'fastpix-vtt-main']
            )
            . html_writer::tag('div', s(get_string('captionsfile_help', 'mod_fastpix')), ['class' => 'fastpix-vtt-sub'])
            . html_writer::tag('div', s($existingname), $statusattrs),
            'fastpix-vtt-dropzone' . ($existingname !== '' ? ' has-file' : ''),
            [
                'data-region' => 'fastpix-vtt-dropzone',
                'tabindex'    => '0',
                'role'        => 'button',
                'aria-label'  => get_string('captionsfile', 'mod_fastpix'),
            ]
        );
        $vttpane = html_writer::div(
            $dropzone . html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => 'captionsfile', 'value' => $draftitemid,
            ]),
            'fastpix-cap-pane fastpix-cap-pane-vtt'
        );

        $body = html_writer::div($tabs . $autopane . $vttpane, 'fastpix-cap-body');

        return html_writer::div($inputs . $head . $body, 'fastpix-cap-card');
    }

    /**
     * Add the custom completion rule elements to the form.
     *
     * @return array The names of the added completion rule elements.
     */
    public function add_completion_rules() {
        $mform = $this->_form;

        // Standard Moodle completion-rule rendering: a single group with the
        // enable checkbox + the percentage input + a "%" suffix. The custom
        // multi-row "card" was reverted — injecting card chrome into Moodle's
        // completion section (raw divs, or per-piece flex CSS over the group's
        // DOM) was unreliable and risked the rule not rendering under
        // "Add requirements". This standard structure always shows there.
        //
        // Element names (completionwatchedpercentenabled, completionwatchedpercent)
        // are the wire contract the completion API, custom_completion, lib.php,
        // and the tests bind to (CG3) — do not rename.
        $group = [];
        $group[] = $mform->createElement(
            'checkbox',
            'completionwatchedpercentenabled',
            '',
            get_string('completionwatchedpercent', 'mod_fastpix')
        );
        $group[] = $mform->createElement('text', 'completionwatchedpercent', '', ['size' => 3]);
        $mform->setType('completionwatchedpercent', PARAM_INT);
        $group[] = $mform->createElement('static', 'completionwatchedpercentsuffix', '', '%');
        $mform->addGroup(
            $group,
            'completionwatchedpercentgroup',
            get_string('completionwatchedpercent_group', 'mod_fastpix'),
            ' ',
            false
        );
        $mform->disabledIf('completionwatchedpercent', 'completionwatchedpercentenabled', 'notchecked');
        $mform->setDefault('completionwatchedpercent', 90);

        return ['completionwatchedpercentgroup'];
    }

    /**
     * Determine whether any custom completion rule is enabled.
     *
     * @param array $data The submitted form data.
     * @return bool True if the watched-percent rule is enabled.
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionwatchedpercentenabled'])
            && (int)$data['completionwatchedpercent'] > 0;
    }

    /**
     * Preprocess existing values before the form is displayed.
     *
     * @param array $defaultvalues The default values, passed by reference.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        if (!empty($defaultvalues['completion_watch_percent'])) {
            $defaultvalues['completionwatchedpercent'] = (int)$defaultvalues['completion_watch_percent'];
            $defaultvalues['completionwatchedpercentenabled'] = 1;
        }

        // Media settings (access policy + captions) are custom-HTML controls;
        // their first-paint state is rendered directly in add_media_settings()
        // from the stored row, not via $defaultvalues.
    }

    /**
     * Server-side validation of the submitted activity settings.
     *
     * @param array $data The submitted form data.
     * @param array $files The submitted files.
     * @return array Errors keyed by form element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return array_merge($errors, $this->validate_fastpix_rules($data));
    }

    /**
     * mod_fastpix-specific validation rules, extracted so they can be
     * exercised by PHPUnit without standing up the full moodleform_mod
     * context (which needs $COURSE, grade_item, etc.).
     *
     * @param array $data Form submission data (uses 'source_type',
     *                    'upload_session_id', 'completionwatchedpercent*').
     *                    'source_url' is read separately from $_POST since
     *                    it is rendered as a raw HTML input.
     * @return array<string,string> Errors keyed by visible mform element name.
     */
    public function validate_fastpix_rules(array $data): array {
        global $DB;
        $errors = [];

        // The source_url is rendered as a raw HTML <input>, NOT a registered mform
        // element, so $data never includes it. Read directly from $_POST.
        // PARAM_URL pairs with the local_fastpix SSRF guard on the validate
        // click to catch malformed URLs at the form layer.
        $sourceurl  = optional_param('source_url', '', PARAM_URL);
        $sourcetype = $data['source_type'] ?? 'upload';

        // All errors below attach to 'name' (a visible text element at the
        // top of the form). 'source_url' and 'upload_session_id' have no
        // visible mform row, so errors keyed there are silently dropped.
        if ($sourcetype === 'upload' && empty($data['upload_session_id'])) {
            $errors['name'] = get_string('error_uploadrequired', 'mod_fastpix');
        }
        if ($sourcetype === 'urlpull') {
            if (empty($sourceurl)) {
                $errors['name'] = get_string('error_urlrequired', 'mod_fastpix');
            } else if (empty($data['upload_session_id'])) {
                $errors['name'] = get_string('error_urlnotvalidated', 'mod_fastpix');
            }
        }

        if (!empty($data['completionwatchedpercentenabled'])) {
            $threshold = (int)$data['completionwatchedpercent'];
            if ($threshold <= 0 || $threshold > 100) {
                $errors['completionwatchedpercentgroup'] = get_string('error_thresholdrange', 'mod_fastpix');
            }
        }

        // Access policy: DRM is only selectable when the site has DRM configured.
        // The control is custom HTML, so the value arrives via POST — read it
        // with optional_param (tests pass it on $data directly). The error
        // attaches to 'name' (a visible element) since the segmented control has
        // no mform row of its own, mirroring the upload/url errors above.
        $policy = $data['access_policy'] ?? optional_param('access_policy', 'private', PARAM_ALPHA);
        if ($policy === 'drm' && !\local_fastpix\service\feature_flag_service::instance()->drm_enabled()) {
            $errors['name'] = get_string('error_drmnotconfigured', 'mod_fastpix');
        }

        // Captions: auto requires a language; vtt requires an uploaded .vtt file.
        // Custom-HTML controls arrive via POST (tests pass them on $data).
        $capenabled = isset($data['captionsenabled'])
            ? !empty($data['captionsenabled'])
            : (bool)optional_param('captionsenabled', 0, PARAM_BOOL);
        if ($capenabled) {
            $mode = $data['captionsmode'] ?? optional_param('captionsmode', 'auto', PARAM_ALPHA);
            if ($mode === 'auto') {
                $lang = $data['languagecode'] ?? optional_param('languagecode', '', PARAM_ALPHA);
                $valid = array_merge(self::CAPTION_LANGS_SUPPORTED, self::CAPTION_LANGS_BETA);
                if ($lang === '' || !in_array($lang, $valid, true)) {
                    $errors['name'] = get_string('error_languagerequired', 'mod_fastpix');
                }
            } else if ($mode === 'vtt') {
                $draftid = (int)($data['captionsfile'] ?? optional_param('captionsfile', 0, PARAM_INT));
                $hasfile = false;
                if ($draftid > 0) {
                    require_once($GLOBALS['CFG']->libdir . '/filelib.php');
                    $info = file_get_draft_area_info($draftid);
                    $hasfile = !empty($info['filecount']);
                }
                if (!$hasfile) {
                    $errors['name'] = get_string('error_vttrequired', 'mod_fastpix');
                }
            }
        }

        if (!empty($this->_instance)) {
            $existing = $DB->get_record('fastpix', ['id' => $this->_instance]);
            if ($existing) {
                // Service owns the "has any real attempts?" check (A6).
                // Real = watched_intervals non-empty (excludes teacher previews).
                $hasrealattempts = \mod_fastpix\service\playback_service::instance()
                    ->has_attempts_for((int)$this->_instance);
                $newsession = !empty($data['upload_session_id']) ? (int)$data['upload_session_id'] : null;
                $oldsession = !empty($existing->upload_session_id) ? (int)$existing->upload_session_id : null;
                if ($hasrealattempts && $newsession !== null && $newsession !== $oldsession) {
                    $errors['name'] = get_string('error_assetswapblocked', 'mod_fastpix');
                }
            }
        }

        return $errors;
    }
}
