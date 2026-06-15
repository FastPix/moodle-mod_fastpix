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
 * Tests for the refresh_playback_token external function of the FastPix activity module.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix\external;

use core_external\external_api;
use mod_fastpix\external\refresh_playback_token;
use mod_fastpix\service\session_token_service;

/**
 * Tests for \mod_fastpix\external\refresh_playback_token (CC6 / S3).
 *
 * The web service re-validates the session token, capability, and attempt
 * state on every call before minting a fresh playback JWT. Faking strategy
 * matches get_player_state_test: real local_fastpix_asset + fastpix_attempt
 * rows, a public+ready asset so lf_playback_service::resolve mints a tokenless
 * payload without a signing key.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix\external\refresh_playback_token
 */
final class refresh_playback_token_test extends \advanced_testcase {
    /**
     * Insert a ready, public (tokenless) local_fastpix_asset row.
     *
     * @param array $overrides
     * @return \stdClass The inserted row (with ->id).
     */
    private function insert_asset(array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $row = (object)array_merge([
            'fastpix_id'             => 'media_' . uniqid('', true),
            'playback_id'            => 'pb_' . uniqid('', true),
            'owner_userid'           => 0,
            'title'                  => 'Phpunit asset',
            'duration'               => 120,
            'status'                 => 'ready',
            'access_policy'          => 'public',
            'drm_required'           => 0,
            'no_skip_required'       => 0,
            'has_captions'           => 0,
            'last_event_id'          => null,
            'last_event_at'          => null,
            'deleted_at'             => null,
            'gdpr_delete_pending_at' => null,
            'timecreated'            => $now,
            'timemodified'           => $now,
        ], $overrides);
        $row->id = $DB->insert_record('local_fastpix_asset', $row);
        return $row;
    }

    /**
     * Build course + enrolled student + activity, set $USER to the student.
     *
     * @param int|null $assetid local_fastpix_asset.id to link, or null.
     * @return array [activity stdClass, student stdClass]
     */
    private function make_activity_for_student(?int $assetid): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        if ($assetid !== null) {
            $DB->set_field('fastpix', 'fastpix_asset_id', $assetid, ['id' => $activity->id]);
        }
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        return [$activity, $student];
    }

    /**
     * Insert an in-progress fastpix_attempt for the student, with a session
     * token issued for (userid, activity_id, now).
     *
     * @param int $userid
     * @param int $activityid
     * @param int $assetid
     * @param array $overrides
     * @return \stdClass The attempt row (token in ->session_token).
     */
    private function insert_attempt(int $userid, int $activityid, int $assetid, array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $row = (object)array_merge([
            'userid'            => $userid,
            'activity_id'       => $activityid,
            'asset_id'          => $assetid,
            'session_token'     => session_token_service::instance()->issue($userid, $activityid, $now),
            'session_start_ts'  => $now,
            'last_callback_ts'  => null,
            'seek_count'        => 0,
            'watched_intervals' => '',
            'current_position'  => 0,
            'has_completed'     => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ], $overrides);
        $row->id = $DB->insert_record('fastpix_attempt', $row);
        return $row;
    }

    public function test_valid_session_returns_fresh_token(): void {
        $this->resetAfterTest();
        $asset = $this->insert_asset();
        [$activity, $student] = $this->make_activity_for_student((int)$asset->id);
        $attempt = $this->insert_attempt((int)$student->id, (int)$activity->id, (int)$asset->id);

        $result = refresh_playback_token::execute((int)$activity->cmid, $attempt->session_token);
        $result = external_api::clean_returnvalue(refresh_playback_token::execute_returns(), $result);

        $this->assertArrayHasKey('playback_token', $result);
        $this->assertArrayHasKey('expires_at_ts', $result);
        $this->assertIsInt($result['expires_at_ts']);
    }

    public function test_no_attempt_throws_session_no_attempt(): void {
        $this->resetAfterTest();
        $asset = $this->insert_asset();
        [$activity] = $this->make_activity_for_student((int)$asset->id);
        // No attempt row inserted.
        try {
            refresh_playback_token::execute((int)$activity->cmid, str_repeat('a', 64));
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_no_attempt', $e->errorcode);
        }
    }

    public function test_token_mismatch_throws_session_invalid(): void {
        $this->resetAfterTest();
        $asset = $this->insert_asset();
        [$activity, $student] = $this->make_activity_for_student((int)$asset->id);
        $this->insert_attempt((int)$student->id, (int)$activity->id, (int)$asset->id);
        try {
            refresh_playback_token::execute((int)$activity->cmid, str_repeat('0', 64));
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_invalid', $e->errorcode);
        }
    }

    public function test_finalised_attempt_throws_session_finalised(): void {
        $this->resetAfterTest();
        $asset = $this->insert_asset();
        [$activity, $student] = $this->make_activity_for_student((int)$asset->id);
        $attempt = $this->insert_attempt(
            (int)$student->id,
            (int)$activity->id,
            (int)$asset->id,
            ['completion_state' => 'complete']
        );
        try {
            refresh_playback_token::execute((int)$activity->cmid, $attempt->session_token);
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_finalised', $e->errorcode);
        }
    }

    public function test_missing_asset_throws_videounavailable(): void {
        $this->resetAfterTest();
        $asset = $this->insert_asset();
        [$activity, $student] = $this->make_activity_for_student((int)$asset->id);
        // Attempt points at a non-existent asset id → asset_service::get_by_id is null.
        $attempt = $this->insert_attempt((int)$student->id, (int)$activity->id, 99999999);
        try {
            refresh_playback_token::execute((int)$activity->cmid, $attempt->session_token);
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_videounavailable', $e->errorcode);
        }
    }

    public function test_capability_lost_mid_session_is_rejected(): void {
        $this->resetAfterTest();
        global $DB;
        $asset = $this->insert_asset();
        [$activity, $student] = $this->make_activity_for_student((int)$asset->id);
        $attempt = $this->insert_attempt((int)$student->id, (int)$activity->id, (int)$asset->id);

        $context = \context_module::instance((int)$activity->cmid);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
        assign_capability('mod/fastpix:view', CAP_PROHIBIT, $studentrole->id, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\moodle_exception::class);
        refresh_playback_token::execute((int)$activity->cmid, $attempt->session_token);
    }

    public function test_guest_without_login_is_rejected(): void {
        $this->resetAfterTest();
        $asset = $this->insert_asset();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', [
            'course'           => $course->id,
            'fastpix_asset_id' => (int)$asset->id,
        ]);
        $this->setGuestUser();
        $this->expectException(\require_login_exception::class);
        refresh_playback_token::execute((int)$activity->cmid, str_repeat('a', 64));
    }

    public function test_unknown_cmid_throws_before_resolve(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\dml_missing_record_exception::class);
        refresh_playback_token::execute(999999, str_repeat('a', 64));
    }
}
