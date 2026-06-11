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

/**
 * Activity-form settings resolution + caption-file persistence.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Shared persistence helpers for fastpix_add_instance() / fastpix_update_instance().
 *
 * Both lib.php callbacks normalise the same form fields and save the same caption
 * file area, so that logic lives here (A6 — services own business logic; M1 keeps
 * lib.php to Moodle-required callbacks only).
 */
class form_settings_service {
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
     * Resolve the player-behaviour + media settings from submitted form data.
     *
     * The "Player behaviour" toggles and the access-policy control are raw-HTML
     * inputs (not registered mform elements), so the real form never populates
     * them on $data — they are read from POST. Programmatic callers (generator,
     * restore, Behat) pass the values on $data directly, which is honoured first.
     *
     * @param \stdClass $data Submitted form data.
     * @return \stdClass {noskiprequired, defaultshowcaptions, accesspolicy,
     *                    captionsmode, languagecode, captionsdraftid}
     */
    public function resolve(\stdClass $data): \stdClass {
        $noskiprequired = $this->resolve_bool_flag($data, 'no_skip_required');
        $defaultshowcaptions = $this->resolve_bool_flag($data, 'default_show_captions');

        $accesspolicy = isset($data->access_policy)
            ? $data->access_policy
            : optional_param('access_policy', 'private', PARAM_ALPHA);
        if (!in_array($accesspolicy, ['private', 'public', 'drm'], true)) {
            $accesspolicy = 'private';
        }

        $captionsenabled = isset($data->captionsenabled)
            ? !empty($data->captionsenabled)
            : (bool)optional_param('captionsenabled', 0, PARAM_BOOL);
        $rawmode = isset($data->captionsmode)
            ? $data->captionsmode
            : optional_param('captionsmode', 'auto', PARAM_ALPHA);
        $captionsmode = $this->resolve_captions_mode($captionsenabled, (string)$rawmode);
        $rawlang = isset($data->languagecode)
            ? $data->languagecode
            : optional_param('languagecode', '', PARAM_ALPHA);
        $languagecode = ($captionsmode === 'auto' && $rawlang !== '') ? $rawlang : null;
        $captionsdraftid = isset($data->captionsfile)
            ? (int)$data->captionsfile
            : optional_param('captionsfile', 0, PARAM_INT);

        return (object)[
            'noskiprequired'      => $noskiprequired,
            'defaultshowcaptions' => $defaultshowcaptions,
            'accesspolicy'        => $accesspolicy,
            'captionsmode'        => $captionsmode,
            'languagecode'        => $languagecode,
            'captionsdraftid'     => $captionsdraftid,
        ];
    }

    /**
     * Resolve a 0/1 player-behaviour flag: honour $data when present (programmatic
     * callers), otherwise read it from POST (the raw-HTML toggles).
     *
     * @param \stdClass $data Submitted form data.
     * @param string $field Field name, shared between the $data property and POST key.
     * @return int 0 or 1.
     */
    private function resolve_bool_flag(\stdClass $data, string $field): int {
        if (isset($data->$field)) {
            return (int)(bool)$data->$field;
        }
        return optional_param($field, 0, PARAM_BOOL) ? 1 : 0;
    }

    /**
     * Resolve the caption mode from the enabled toggle and the raw mode string.
     *
     * @param bool $enabled Whether captions are enabled.
     * @param string $rawmode The submitted mode value.
     * @return string One of none|auto|vtt.
     */
    private function resolve_captions_mode(bool $enabled, string $rawmode): string {
        if (!$enabled) {
            return 'none';
        }
        return $rawmode === 'vtt' ? 'vtt' : 'auto';
    }

    /**
     * Persist (or clear) the teacher-uploaded .vtt into the activity's captions
     * filearea. A no-op when no course-module context exists yet (e.g. tests /
     * programmatic callers without $data->coursemodule).
     *
     * @param \stdClass $data Submitted form data (uses $data->coursemodule).
     * @param string $captionsmode Resolved caption mode (none|auto|vtt).
     * @param int $draftid The submitted captions draft itemid.
     * @return void
     */
    public function save_captions_file(\stdClass $data, string $captionsmode, int $draftid): void {
        global $CFG;

        if (empty($data->coursemodule)) {
            return;
        }
        require_once($CFG->libdir . '/filelib.php');
        $modcontext = \context_module::instance($data->coursemodule);

        if ($captionsmode === 'vtt' && $draftid > 0) {
            file_save_draft_area_files(
                $draftid,
                $modcontext->id,
                'mod_fastpix',
                'captions',
                0,
                ['subdirs' => 0, 'accepted_types' => ['.vtt']]
            );
            // Enforce a single caption file: keep the newest, drop any extras the
            // custom dropzone may have left in the draft area.
            $fs = get_file_storage();
            $stored = $fs->get_area_files($modcontext->id, 'mod_fastpix', 'captions', 0, 'timemodified DESC', false);
            $keep = true;
            foreach ($stored as $storedfile) {
                if ($keep) {
                    $keep = false;
                    continue;
                }
                $storedfile->delete();
            }
        } else {
            get_file_storage()->delete_area_files($modcontext->id, 'mod_fastpix', 'captions', 0);
        }
    }
}
