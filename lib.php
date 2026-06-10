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
 * Library of Moodle-required callbacks for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declare which Moodle activity features this module supports.
 *
 * @param string $feature One of the FEATURE_* constants.
 * @return mixed True/false for a supported feature, a value for FEATURE_MOD_PURPOSE, or null when unknown.
 */
function fastpix_supports($feature) {
    switch ($feature) {
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Create a new FastPix activity instance.
 *
 * @param \stdClass $data Submitted form data.
 * @param \mod_fastpix_mod_form|null $mform The activity form, if available.
 * @return int The id of the newly created activity row.
 */
function fastpix_add_instance($data, $mform = null) {
    global $DB;

    // Player-behaviour + media settings are normalised in the shared service.
    $settings = \mod_fastpix\service\form_settings_service::instance();
    $resolved = $settings->resolve($data);

    $now = time();
    $record = (object) [
        'course'                   => $data->course,
        'name'                     => $data->name,
        'intro'                    => $data->intro ?? '',
        'introformat'              => $data->introformat ?? FORMAT_HTML,
        'fastpix_asset_id'         => null,
        'upload_session_id'        => !empty($data->upload_session_id) ? (int)$data->upload_session_id : null,
        'completion_watch_percent' => !empty($data->completionwatchedpercentenabled)
            ? (int)$data->completionwatchedpercent
            : 90,
        'no_skip_required'         => $resolved->noskiprequired,
        'default_show_captions'    => $resolved->defaultshowcaptions,
        'access_policy'            => $resolved->accesspolicy,
        'captions_mode'            => $resolved->captionsmode,
        'language_code'            => $resolved->languagecode,
        'grademax'                 => isset($data->grade) ? (float)$data->grade : 100,
        'timecreated'              => $now,
        'timemodified'             => $now,
    ];

    $id = $DB->insert_record('fastpix', $record);

    $settings->save_captions_file($data, $resolved->captionsmode, $resolved->captionsdraftid);

    // Create the grade item now (core's edit_module_post_actions only updates
    // existing items — the module is responsible for creating it on add).
    $record->id = $id;
    $record->cmidnumber = $data->cmidnumber ?? '';
    fastpix_grade_item_update($record);

    return $id;
}

/**
 * Update an existing FastPix activity instance.
 *
 * @param \stdClass $data Submitted form data.
 * @param \mod_fastpix_mod_form|null $mform The activity form, if available.
 * @return bool True on success.
 */
function fastpix_update_instance($data, $mform = null) {
    global $DB;

    // Player-behaviour + media settings are normalised in the shared service.
    $settings = \mod_fastpix\service\form_settings_service::instance();
    $resolved = $settings->resolve($data);

    $record = (object) [
        'id'                       => $data->instance,
        'name'                     => $data->name,
        'intro'                    => $data->intro ?? '',
        'introformat'              => $data->introformat ?? FORMAT_HTML,
        'completion_watch_percent' => !empty($data->completionwatchedpercentenabled)
            ? (int)$data->completionwatchedpercent
            : 90,
        'no_skip_required'         => $resolved->noskiprequired,
        'default_show_captions'    => $resolved->defaultshowcaptions,
        'access_policy'            => $resolved->accesspolicy,
        'captions_mode'            => $resolved->captionsmode,
        'language_code'            => $resolved->languagecode,
        'grademax'                 => isset($data->grade) ? (float)$data->grade : 100,
        'timemodified'             => time(),
    ];

    if (!empty($data->upload_session_id)) {
        // Asset swap: if the activity is being pointed at a DIFFERENT upload, release
        // the OLD asset reference now — read it from the DB row before we clear the
        // link (the new reference registers when the new asset links, in
        // resolve_for_view). Unchanged upload → leave the reference as-is (idempotent).
        $existing = $DB->get_record('fastpix', ['id' => $data->instance], 'id, upload_session_id, fastpix_asset_id');
        if (
            $existing
            && (int)$data->upload_session_id !== (int)($existing->upload_session_id ?? 0)
            && !empty($existing->fastpix_asset_id)
        ) {
            \mod_fastpix\service\asset_lifecycle_service::instance()->release_reference((int)$data->instance);
        }

        $record->upload_session_id = (int)$data->upload_session_id;
        // The webhook will populate fastpix_asset_id; clear any stale reference.
        $record->fastpix_asset_id = null;
    }

    $DB->update_record('fastpix', $record);

    $settings->save_captions_file($data, $resolved->captionsmode, $resolved->captionsdraftid);

    // Keep the grade item in sync with the (possibly changed) name / max grade.
    $record->course = $data->course;
    $record->cmidnumber = $data->cmidnumber ?? '';
    fastpix_grade_item_update($record);

    return true;
}

/**
 * Delete a FastPix activity instance and its attempt rows.
 *
 * @param int $id The activity instance id.
 * @return bool True on success, false if the instance does not exist.
 */
function fastpix_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists('fastpix', ['id' => $id])) {
        return false;
    }

    // Release this activity's asset reference before the row is gone. local_fastpix
    // soft-deletes the asset only when its LAST reference is released. Fail-safe:
    // the delete must always succeed even if the service is unavailable.
    \mod_fastpix\service\asset_lifecycle_service::instance()->release_reference((int)$id);

    $DB->delete_records('fastpix_attempt', ['activity_id' => $id]);
    $DB->delete_records('fastpix', ['id' => $id]);

    return true;
}

/**
 * Gradebook item registration / write. Bare-name per M1 — Moodle core
 * invokes this via call_user_func("{$modname}_grade_item_update", ...).
 *
 * @param \stdClass $activity Row from mdl_fastpix.
 * @param array|string|null $grades Array of {userid, rawgrade, dategraded}
 *        objects, or the literal string 'reset' to clear all grades, or null
 *        for item-only registration.
 * @return int GRADE_UPDATE_OK / GRADE_UPDATE_FAILED / GRADE_UPDATE_MULTIPLE.
 * @package mod_fastpix
 */
function fastpix_grade_item_update($activity, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname'  => $activity->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => (float)($activity->grademax ?? 100),
        'grademin'  => 0,
        'idnumber'  => $activity->cmidnumber ?? '',
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/fastpix',
        $activity->course,
        'mod',
        'fastpix',
        $activity->id,
        0,
        $grades,
        $params
    );
}

/**
 * Bulk regrade entry point — invoked by Moodle's gradebook recompute UI.
 * Iterates attempts where has_completed=1 and writes rawgrade = grademax
 * (CG4: completion is binary — no partial credit).
 *
 * @param \stdClass|null $activity NULL → walk all fastpix instances.
 * @param int $userid 0 → all enrolled users; otherwise filter.
 * @param bool $nullifnone If true and no qualifying attempt exists,
 *                        write rawgrade=null to clear stale gradebook rows.
 * @package mod_fastpix
 */
function fastpix_update_grades($activity = null, $userid = 0, $nullifnone = true) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if ($activity === null) {
        // Walk all fastpix activities + recurse for each.
        $rs = $DB->get_recordset('fastpix');
        foreach ($rs as $row) {
            fastpix_update_grades($row, $userid, $nullifnone);
        }
        $rs->close();
        return;
    }

    $sql = "SELECT userid FROM {fastpix_attempt}
             WHERE activity_id = :aid AND has_completed = 1";
    $params = ['aid' => $activity->id];
    if ($userid > 0) {
        $sql .= ' AND userid = :uid';
        $params['uid'] = $userid;
    }
    $completedusers = $DB->get_fieldset_sql($sql, $params);

    // The grade_update() call REQUIRES the $grades array to be keyed by user id — same
    // constraint applies to watch_tracker_service::record_progress (CG1).
    $grades = [];
    foreach ($completedusers as $uid) {
        $uid = (int)$uid;
        $grades[$uid] = (object)[
            'userid'     => $uid,
            'rawgrade'   => (float)($activity->grademax ?? 100),
            'dategraded' => time(),
        ];
    }

    if (empty($grades)) {
        if ($nullifnone && $userid > 0) {
            // Single-user clear path — write rawgrade=null for that user.
            $grades = [
                $userid => (object)['userid' => $userid, 'rawgrade' => null],
            ];
        } else if (!$nullifnone) {
            return;
        } else {
            // No completions yet on this activity — register the item only.
            fastpix_grade_item_update($activity);
            return;
        }
    }

    fastpix_grade_item_update($activity, $grades);
}

/**
 * Surface custom-completion rules + threshold value on cm_info->customdata
 * so the completion-rules UI (and our custom_completion class) can read the
 * configured threshold for each activity. Pattern matches mod_quiz / mod_forum.
 * @package mod_fastpix
 */
function fastpix_get_coursemodule_info($coursemodule) {
    global $DB;

    $activity = $DB->get_record(
        'fastpix',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completion_watch_percent'
    );
    if (!$activity) {
        return false;
    }

    $info = new \cached_cm_info();
    $info->name = $activity->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('fastpix', $activity, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionwatchedpercent'] =
            (int)$activity->completion_watch_percent;
    }
    // Force Moodle to render the brand-coloured icon.svg as an <img> rather
    // than applying the monologo→mask-image→purpose-tint pipeline. Without
    // this, Moodle 4.x recolours the icon to the assessment-purpose tint
    // (pink/red in Boost) and the brand greens are lost.
    $info->iconurl = new \moodle_url('/mod/fastpix/pix/icon.svg');
    return $info;
}

/**
 * Localized descriptions for the activity-completion-rules UI (CG3).
 * Reads the threshold stamped onto cm->customdata by
 * fastpix_get_coursemodule_info().
 * @package mod_fastpix
 */
function fastpix_get_completion_active_rule_descriptions($cm) {
    if (empty($cm->customdata['customcompletionrules']['completionwatchedpercent'])) {
        return [];
    }
    $threshold = (int)$cm->customdata['customcompletionrules']['completionwatchedpercent'];
    return [
        get_string('completionwatchedpercent_desc', 'mod_fastpix', $threshold),
    ];
}

/**
 * Add the "Watch report" link to the activity's secondary navigation, for users
 * who can see all attempts (mod/fastpix:viewallattempts). The reporting surface
 * is display-only over mdl_fastpix_attempt (no tracking, no FastPix calls).
 *
 * @param \settings_navigation $settings The settings navigation object.
 * @param \navigation_node $fastpixnode The activity's navigation node.
 * @package mod_fastpix
 */
function fastpix_extend_settings_navigation(settings_navigation $settings, navigation_node $fastpixnode) {
    global $PAGE;

    // Read the magic property into a local first: empty($PAGE->cm) is unreliable
    // because moodle_page has __get but no __isset for 'cm', so empty()/isset()
    // report it missing even when it is set, and the node would never be added.
    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }
    $context = \context_module::instance($cm->id);
    if (!has_capability('mod/fastpix:viewallattempts', $context)) {
        return;
    }

    $url = new moodle_url('/mod/fastpix/report.php', ['id' => $cm->id]);
    $node = navigation_node::create(
        get_string('watchreport', 'mod_fastpix'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'watchreport',
        new pix_icon('i/report', '')
    );
    // Promote to a primary secondary-nav tab (fastpix has well under Moodle's
    // 5-tab limit, so it shows alongside the activity + Settings, not in "More").
    $node->set_show_in_secondary_navigation(true);
    $fastpixnode->add_node($node);
}

/**
 * Serve files from mod_fastpix file areas. Only the teacher-uploaded captions
 * (.vtt) area is exposed, gated on mod/fastpix:uploadmedia — these are upload
 * working material, not student-facing (students get captions from FastPix).
 *
 * @param \stdClass $course Course object.
 * @param \stdClass $cm Course-module object.
 * @param \context $context Module context.
 * @param string $filearea The requested file area.
 * @param array $args itemid / filepath / filename segments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional options for sending the file.
 * @return bool False on failure; otherwise streams the file and exits.
 * @package mod_fastpix
 */
function fastpix_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== 'captions') {
        return false;
    }

    require_login($course, true, $cm);
    require_capability('mod/fastpix:uploadmedia', $context);

    // The captions area uses a fixed itemid of 0 (one file per activity).
    array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = get_file_storage()->get_file($context->id, 'mod_fastpix', 'captions', 0, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 86400, 0, $forcedownload, $options);
}
