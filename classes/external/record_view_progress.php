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

namespace mod_fastpix\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_fastpix\service\asset_service;
use mod_fastpix\service\session_token_service;
use mod_fastpix\service\watch_tracker_service;

/**
 * External function to record per-tick watch progress for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Per-tick watch progress endpoint (~ every 10s from amd/src/watch_tracker.js).
 *
 * Auth dance ORDER MATTERS (S3 / PR-7):
 *   validate_parameters → validate_context (does require_login + sesskey)
 *   → require_capability → session_token_service::resolve_active_attempt
 *
 * Delegates ALL business logic — the six fraud checks (PR-9) and
 * interval merge (CG5 milestones, CG4 completion recomputation) — to
 * watch_tracker_service. This external function is parameter
 * marshalling + auth, nothing more (A6).
 *
 * Log hygiene (PR-21): debugging() lines include attempt_id +
 * fraud_reason only; raw userid / session_token / playback_token
 * never appear.
 */
class record_view_progress extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters The parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'              => new external_value(PARAM_INT, 'Course module id'),
            'session_token'     => new external_value(PARAM_ALPHANUM, 'Active session token'),
            'watched_intervals' => new external_value(PARAM_RAW, 'JSON array of [start,end] pairs'),
            'current_position'  => new external_value(PARAM_FLOAT, 'Playback head, seconds'),
            'client_seek_count' => new external_value(PARAM_INT, 'Monotonic client seek counter', VALUE_DEFAULT, 0),
            'ended_fired'       => new external_value(PARAM_BOOL, 'Client saw the ended event', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Describe the return structure of execute().
     *
     * @return external_single_structure The return definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'coverage_percent'   => new external_value(PARAM_INT, 'Server-confirmed coverage 0..100'),
            'completion_state'   => new external_value(PARAM_ALPHA, '"in_progress" | "complete"'),
            'fraud_count'        => new external_value(PARAM_INT, 'Running total of fraud-flag increments on this attempt'),
            'last_fraud_reason'  => new external_value(
                PARAM_ALPHA,
                'Reason for the most recent fraud flag, if any',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
        ]);
    }

    /**
     * Record client-reported watch progress with server-side fraud checks.
     *
     * @param int $cmid The course module id.
     * @param string $sessiontoken The active session token.
     * @param string $watchedintervals JSON-encoded [[start,end], ...] pairs.
     * @param float $currentposition The playback head in seconds.
     * @param int $clientseekcount The monotonic client seek counter.
     * @param bool $endedfired Whether the client saw the ended event.
     * @return array See execute_returns().
     */
    public static function execute(
        int $cmid,
        string $sessiontoken,
        string $watchedintervals,
        float $currentposition,
        int $clientseekcount = 0,
        bool $endedfired = false
    ): array {
        global $DB, $USER;

        // Step 1: validate_parameters — type coercion + length caps.
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'              => $cmid,
            'session_token'     => $sessiontoken,
            'watched_intervals' => $watchedintervals,
            'current_position'  => $currentposition,
            'client_seek_count' => $clientseekcount,
            'ended_fired'       => $endedfired,
        ]);

        // Step 2: validate_context — require_login + sesskey.
        $cm = get_coursemodule_from_id('fastpix', (int)$params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        // Step 3: require_capability.
        require_capability('mod/fastpix:view', $context);

        // Teacher preview short-circuit. playback_service::get_or_create_attempt
        // intentionally does NOT insert a fastpix_attempt row for users with
        // mod/fastpix:addinstance (teachers / admins previewing) — see the
        // "Phase D contract" note there. Their session_token is a real HMAC
        // but there's no matching DB row, so resolve_active_attempt would
        // throw error_session_no_attempt every tick of the watch tracker.
        // Return a soft-success response so the tracker keeps quiet and no
        // fraud_count is incremented for a teacher just opening the activity.
        if (has_capability('mod/fastpix:addinstance', $context)) {
            return [
                'coverage_percent'  => 0,
                'completion_state'  => 'in_progress',
                'fraud_count'       => 0,
                'last_fraud_reason' => null,
            ];
        }

        // Step 4: session_token verify + attempt state check (delegated to the
        // service that owns these three error modes — A6 / S3).
        $attempt = session_token_service::instance()->resolve_active_attempt(
            (int)$USER->id,
            (int)$cm->instance,
            (string)$params['session_token']
        );

        // Parse + validate the intervals payload. Bounded defensive cap
        // (MAX_INTERVALS) protects against pathological client payloads.
        $decoded = json_decode((string)$params['watched_intervals'], true);
        if (!is_array($decoded)) {
            throw new \invalid_parameter_exception('watched_intervals must be a JSON array');
        }
        if (count($decoded) > watch_tracker_service::MAX_INTERVALS) {
            throw new \invalid_parameter_exception('watched_intervals exceeds maximum allowed length');
        }
        $cleanintervals = [];
        foreach ($decoded as $iv) {
            // Use isset rather than bare index access — JSON objects decode to
            // assoc arrays whose integer-key lookup would emit a PHP-8 warning
            // before the is_numeric check could reject them.
            if (
                !is_array($iv) || !isset($iv[0], $iv[1])
                    || !is_numeric($iv[0]) || !is_numeric($iv[1])
            ) {
                throw new \invalid_parameter_exception('watched_intervals entries must be [number, number] pairs');
            }
            $cleanintervals[] = [(float)$iv[0], (float)$iv[1]];
        }

        $activity = $DB->get_record('fastpix', ['id' => (int)$cm->instance], '*', MUST_EXIST);

        $asset = asset_service::get_by_id((int)$attempt->asset_id);
        if ($asset === null) {
            throw new \moodle_exception('error_videounavailable', 'mod_fastpix');
        }

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            $cleanintervals,
            (float)$params['current_position'],
            (bool)$params['ended_fired'],
            (int)$params['client_seek_count'],
            $context,
            time()
        );

        if (!empty($result->fraud_reasons)) {
            debugging(sprintf(
                'mod_fastpix: fraud attempt_id=%d reasons=%s coverage=%d',
                (int)$result->attempt->id,
                implode(',', $result->fraud_reasons),
                (int)$result->coverage_percent
            ), DEBUG_DEVELOPER);
        }

        return [
            'coverage_percent'  => (int)$result->coverage_percent,
            'completion_state'  => (string)$result->completion_state,
            'fraud_count'       => (int)$result->attempt->fraud_count,
            'last_fraud_reason' => $result->attempt->last_fraud_reason !== null
                ? (string)$result->attempt->last_fraud_reason
                : null,
        ];
    }
}
