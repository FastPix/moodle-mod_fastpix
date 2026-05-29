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

    // The two "Player behaviour" toggles are rendered as raw HTML checkboxes
    // in mod_form.php (for the styled card look), not registered mform elements,
    // so the real form's get_data() never populates them — there we read the
    // posted checkbox value. But programmatic callers (the data generator,
    // restore, Behat) pass the values directly on $data, so honour those first.
    $noskiprequired = isset($data->no_skip_required)
        ? ((int)(bool)$data->no_skip_required)
        : (optional_param('no_skip_required', 0, PARAM_BOOL) ? 1 : 0);
    $defaultshowcaptions = isset($data->default_show_captions)
        ? ((int)(bool)$data->default_show_captions)
        : (optional_param('default_show_captions', 0, PARAM_BOOL) ? 1 : 0);

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
        'no_skip_required'         => $noskiprequired,
        'default_show_captions'    => $defaultshowcaptions,
        'grademax'                 => isset($data->grade) ? (float)$data->grade : 100,
        'timecreated'              => $now,
        'timemodified'             => $now,
    ];

    $id = $DB->insert_record('fastpix', $record);

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

    // See fastpix_add_instance(): the raw-HTML "Player behaviour" checkboxes are
    // not registered mform elements, so the real form never reaches $data — read
    // the posted value there. Programmatic callers pass them on $data directly.
    $noskiprequired = isset($data->no_skip_required)
        ? ((int)(bool)$data->no_skip_required)
        : (optional_param('no_skip_required', 0, PARAM_BOOL) ? 1 : 0);
    $defaultshowcaptions = isset($data->default_show_captions)
        ? ((int)(bool)$data->default_show_captions)
        : (optional_param('default_show_captions', 0, PARAM_BOOL) ? 1 : 0);

    $record = (object) [
        'id'                       => $data->instance,
        'name'                     => $data->name,
        'intro'                    => $data->intro ?? '',
        'introformat'              => $data->introformat ?? FORMAT_HTML,
        'completion_watch_percent' => !empty($data->completionwatchedpercentenabled)
            ? (int)$data->completionwatchedpercent
            : 90,
        'no_skip_required'         => $noskiprequired,
        'default_show_captions'    => $defaultshowcaptions,
        'grademax'                 => isset($data->grade) ? (float)$data->grade : 100,
        'timemodified'             => time(),
    ];

    if (!empty($data->upload_session_id)) {
        $record->upload_session_id = (int)$data->upload_session_id;
        // The webhook will populate fastpix_asset_id; clear any stale reference.
        $record->fastpix_asset_id = null;
    }

    $DB->update_record('fastpix', $record);

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
 * Recycle-bin hook — Phase E (backup/restore + asset lifecycle).
 *
 * Soft-deletes the backing FastPix asset via local_fastpix, but only when no
 * other live activity still references it (M9). Reference counting + the
 * delegate to \local_fastpix\service\asset_service::soft_delete() live in the
 * service layer (A1/A6); this callback just bridges $cm -> service.
 *
 * @param \stdClass $cm The course-module being deleted.
 * @package mod_fastpix
 */
function fastpix_pre_course_module_delete($cm) {
    \mod_fastpix\service\asset_lifecycle_service::instance()
        ->soft_delete_if_unreferenced((int)$cm->instance);
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
