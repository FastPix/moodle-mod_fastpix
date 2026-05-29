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
use local_fastpix\service\playback_service as lf_playback_service;
use local_fastpix\exception\asset_not_found;
use local_fastpix\exception\asset_not_ready;
use mod_fastpix\service\session_token_service;

/**
 * External function to refresh a playback token for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Refresh a playback JWT before it expires (CC6 / D2). Re-validates the
 * session token, capability, and attempt state on every call.
 */
class refresh_playback_token extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters The parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'          => new external_value(PARAM_INT, 'Course module id'),
            'session_token' => new external_value(PARAM_ALPHANUM, 'Active session token'),
        ]);
    }

    /**
     * Describe the return structure of execute().
     *
     * @return external_single_structure The return definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'playback_token' => new external_value(PARAM_RAW, 'Freshly minted DRM JWT'),
            'expires_at_ts'  => new external_value(PARAM_INT, 'Unix timestamp when the JWT expires'),
        ]);
    }

    /**
     * Mint a fresh playback token for an in-progress attempt.
     *
     * @param int $cmid The course module id.
     * @param string $sessiontoken The active session token.
     * @return array See execute_returns().
     */
    public static function execute(int $cmid, string $sessiontoken): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'          => $cmid,
            'session_token' => $sessiontoken,
        ]);

        $cm = get_coursemodule_from_id('fastpix', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/fastpix:view', $context);

        // The session_token_service owns the attempt-lookup + token-verify logic
        // and throws one of three distinct lang keys (S3 / A6 layering).
        $attempt = session_token_service::instance()->resolve_active_attempt(
            (int)$USER->id,
            (int)$cm->instance,
            (string)$params['session_token']
        );

        $asset = asset_service::get_by_id((int)$attempt->asset_id);
        if ($asset === null) {
            throw new \moodle_exception('error_videounavailable', 'mod_fastpix');
        }

        try {
            $payload = lf_playback_service::resolve((string)$asset->fastpix_id, $USER->id);
        } catch (asset_not_found $e) {
            throw new \moodle_exception('error_videounavailable', 'mod_fastpix');
        } catch (asset_not_ready $e) {
            throw new \moodle_exception('error_videounavailable', 'mod_fastpix');
        }

        return [
            'playback_token' => $payload->playbacktoken,
            'expires_at_ts'  => $payload->expiresatts,
        ];
    }
}
