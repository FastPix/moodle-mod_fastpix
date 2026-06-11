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

namespace mod_fastpix\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Privacy provider for mod_fastpix (rule S10).
 *
 * Declares every PII column on mdl_fastpix_attempt and implements full
 * export / delete / userlist contracts. Session tokens and fraud reasons
 * are user-bound — they are exported and deleted alongside the rest.
 *
 * mdl_fastpix (activity instances) holds no per-user data and is therefore
 * out of scope for this provider — those rows are course-content metadata
 * and survive a user-data wipe.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The populated metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'fastpix_attempt',
            [
                'userid'            => 'privacy:metadata:fastpix_attempt:userid',
                'activity_id'       => 'privacy:metadata:fastpix_attempt:activity_id',
                'asset_id'          => 'privacy:metadata:fastpix_attempt:asset_id',
                'watched_intervals' => 'privacy:metadata:fastpix_attempt:watched_intervals',
                'current_position'  => 'privacy:metadata:fastpix_attempt:current_position',
                'has_completed'     => 'privacy:metadata:fastpix_attempt:has_completed',
                'seek_count'        => 'privacy:metadata:fastpix_attempt:seek_count',
                'fraud_count'       => 'privacy:metadata:fastpix_attempt:fraud_count',
                'last_fraud_reason' => 'privacy:metadata:fastpix_attempt:last_fraud_reason',
                'session_token'     => 'privacy:metadata:fastpix_attempt:session_token',
                'session_start_ts'  => 'privacy:metadata:fastpix_attempt:session_start_ts',
                'last_callback_ts'  => 'privacy:metadata:fastpix_attempt:last_callback_ts',
                'completion_state'  => 'privacy:metadata:fastpix_attempt:completion_state',
                'milestone_25_at'   => 'privacy:metadata:fastpix_attempt:milestones',
                'milestone_50_at'   => 'privacy:metadata:fastpix_attempt:milestones',
                'milestone_75_at'   => 'privacy:metadata:fastpix_attempt:milestones',
                'milestone_100_at'  => 'privacy:metadata:fastpix_attempt:milestones',
            ],
            'privacy:metadata:fastpix_attempt'
        );

        return $collection;
    }

    /**
     * Return the contexts that contain personal data for the given user.
     *
     * @param int $userid The user id.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {fastpix_attempt} fa ON fa.activity_id = cm.instance
                 WHERE ctx.contextlevel = :modlevel
                   AND m.name = :modname
                   AND fa.userid = :userid";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'modlevel' => CONTEXT_MODULE,
            'modname'  => 'fastpix',
            'userid'   => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Return the users who have personal data within the given context.
     *
     * @param userlist $userlist The userlist to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $sql = "SELECT fa.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {fastpix_attempt} fa ON fa.activity_id = cm.instance
                 WHERE cm.id = :cmid
                   AND m.name = :modname";

        $userlist->add_from_sql('userid', $sql, [
            'cmid'    => $context->instanceid,
            'modname' => 'fastpix',
        ]);
    }

    /**
     * Export all personal data for the approved contexts of a user.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('fastpix', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            $attempt = $DB->get_record(
                'fastpix_attempt',
                ['userid' => $userid, 'activity_id' => $cm->instance]
            );
            if (!$attempt) {
                continue;
            }

            // Activity-level metadata (course / cm / activity name) is written
            // once per context by Moodle's standard helper — gives the user a
            // recognisable header before our row-level data appears.
            helper::export_context_files($context, $contextlist->get_user());

            $export = self::build_attempt_export($attempt);

            writer::with_context($context)->export_data(
                [get_string('privacy:path:attempt', 'mod_fastpix')],
                $export
            );
        }
    }

    /**
     * Build the exportable representation of one attempt row. The session_token
     * is auth material (S6) — declared in get_metadata for completeness but
     * redacted here, since including it would let a downloaded SAR be replayed.
     *
     * @param \stdClass $attempt The fastpix_attempt row.
     * @return \stdClass The export object.
     */
    private static function build_attempt_export(\stdClass $attempt): \stdClass {
        return (object) [
            'watched_intervals' => $attempt->watched_intervals,
            'current_position'  => (float) $attempt->current_position,
            'has_completed'     => (bool) $attempt->has_completed,
            'seek_count'        => (int) $attempt->seek_count,
            'fraud_count'       => (int) $attempt->fraud_count,
            'last_fraud_reason' => $attempt->last_fraud_reason,
            'completion_state'  => $attempt->completion_state,
            'session_start_ts'  => transform::datetime($attempt->session_start_ts),
            'last_callback_ts'  => !empty($attempt->last_callback_ts)
                ? transform::datetime($attempt->last_callback_ts)
                : null,
            'milestone_25_at'   => !empty($attempt->milestone_25_at)
                ? transform::datetime($attempt->milestone_25_at) : null,
            'milestone_50_at'   => !empty($attempt->milestone_50_at)
                ? transform::datetime($attempt->milestone_50_at) : null,
            'milestone_75_at'   => !empty($attempt->milestone_75_at)
                ? transform::datetime($attempt->milestone_75_at) : null,
            'milestone_100_at'  => !empty($attempt->milestone_100_at)
                ? transform::datetime($attempt->milestone_100_at) : null,
            'session_token'     => '[redacted]',
        ];
    }

    /**
     * Delete all personal data for all users in the given context.
     *
     * @param context $context The context to delete data from.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('fastpix', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $DB->delete_records('fastpix_attempt', ['activity_id' => $cm->instance]);
    }

    /**
     * Delete personal data for a single user across the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete from.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('fastpix', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $DB->delete_records('fastpix_attempt', [
                'userid'      => $userid,
                'activity_id' => $cm->instance,
            ]);
        }
    }

    /**
     * Delete personal data for the listed users within a context.
     *
     * @param approved_userlist $userlist The approved users to delete data for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('fastpix', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = array_merge($inparams, ['aid' => $cm->instance]);
        $DB->delete_records_select(
            'fastpix_attempt',
            "userid {$insql} AND activity_id = :aid",
            $params
        );
    }
}
