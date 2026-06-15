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
 * Tests for the get_player_state external function of the FastPix activity module.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix\external;

use core_external\external_api;
use mod_fastpix\external\get_player_state;
use mod_fastpix\service\playback_service;

/**
 * Tests for \mod_fastpix\external\get_player_state.
 *
 * The web service performs the S3 auth dance (validate_parameters →
 * validate_context → require_capability('mod/fastpix:view')) then delegates
 * to \mod_fastpix\service\playback_service::resolve_for_view and flattens the
 * returned view-state DTO into the external return structure:
 *
 *   view_state_player     → ['ready' => true, ...$dto->to_player_payload()]
 *   view_state_processing → ['ready' => false]
 *   view_state_error      → ['ready' => false, 'error_key' => $dto->reason_key]
 *
 * Faking strategy (matches record_view_progress_test.php — real DB rows, no
 * mocking of local_fastpix): the consumed surface (asset_service,
 * lf_playback_service) reads real local_fastpix_asset rows. A 'ready' state is
 * produced with an access_policy='public' asset so lf_playback_service::resolve
 * mints a tokenless payload WITHOUT needing the signing key bootstrapped — the
 * same fixture local_fastpix's own playback_service_test uses. A 'processing'
 * state uses a non-ready asset; a terminal 'videounavailable' error uses a
 * soft-deleted asset.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix\external\get_player_state
 * @covers     \mod_fastpix\dto\view_state_player::to_player_payload
 */
final class get_player_state_test extends \advanced_testcase {
    /**
     * Insert a local_fastpix_asset row. Defaults describe a ready, public
     * (tokenless) asset so the playback resolve path needs no signing key.
     *
     * @param array $overrides
     * @return \stdClass the inserted row (with ->id)
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
     * Build course + enrolled student + activity row whose fastpix_asset_id
     * points at the given asset id, then setUser($student) so $USER inside
     * execute() resolves to the student. Returns [activity, student].
     *
     * fastpix_add_instance() hardcodes fastpix_asset_id=NULL and derives
     * completion_watch_percent from the completion-enabled flags, so the
     * generator cannot set those directly — we write them with set_field after
     * creation (the activity row is what resolve_for_view reads).
     *
     * @param int|null $assetid local_fastpix_asset.id, or null for none
     * @param int|null $watchpercent completion_watch_percent to force, or null to leave default
     * @param int|null $uploadsessionid upload_session_id to set, or null
     * @return array [activity stdClass from generator, student stdClass]
     */
    private function make_activity_for_student(
        ?int $assetid,
        ?int $watchpercent = null,
        ?int $uploadsessionid = null
    ): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', [
            'course' => $course->id,
        ]);

        if ($assetid !== null) {
            $DB->set_field('fastpix', 'fastpix_asset_id', $assetid, ['id' => $activity->id]);
        }
        if ($uploadsessionid !== null) {
            $DB->set_field('fastpix', 'upload_session_id', $uploadsessionid, ['id' => $activity->id]);
        }
        if ($watchpercent !== null) {
            $DB->set_field('fastpix', 'completion_watch_percent', $watchpercent, ['id' => $activity->id]);
        }

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        return [$activity, $student];
    }

    // 1. Auth dance (S3 / PR-7)

    public function test_user_without_view_capability_is_rejected(): void {
        $this->resetAfterTest();
        global $DB;

        $asset = $this->insert_asset();
        [$activity, $student] = $this->make_activity_for_student((int)$asset->id);

        // Yank mod/fastpix:view from the student role for this context. Either
        // required_capability_exception or require_login_exception is correct —
        // both extend moodle_exception and both block the endpoint (CAP_PROHIBIT
        // on the visibility-gating cap can surface as require_login first).
        $context = \context_module::instance((int)$activity->cmid);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
        assign_capability('mod/fastpix:view', CAP_PROHIBIT, $studentrole->id, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\moodle_exception::class);
        get_player_state::execute((int)$activity->cmid);
    }

    public function test_unknown_cmid_throws_before_any_resolve(): void {
        // Get_coursemodule_from_id(..., MUST_EXIST) fires before validate_context
        // / require_capability — proves the endpoint never reaches the service
        // layer with a bogus cmid.
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\dml_missing_record_exception::class);
        get_player_state::execute(999999);
    }

    public function test_guest_without_login_is_rejected_by_validate_context(): void {
        // No setUser → validate_context()'s require_login leg must reject before
        // require_capability runs. Exercises the validate_context path (item 1).
        $this->resetAfterTest();

        $asset = $this->insert_asset();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', [
            'course'           => $course->id,
            'fastpix_asset_id' => (int)$asset->id,
        ]);

        $this->setGuestUser();

        $this->expectException(\require_login_exception::class);
        get_player_state::execute((int)$activity->cmid);
    }

    // 2. Ready asset → full player payload (also covers to_player_payload)

    public function test_ready_asset_returns_ready_true_with_player_payload(): void {
        global $CFG;
        $this->resetAfterTest();

        $asset = $this->insert_asset([
            'duration'      => 120,
            'access_policy' => 'public',
            'drm_required'  => 0,
        ]);
        [$activity, $student] = $this->make_activity_for_student((int)$asset->id, 80);

        $result = get_player_state::execute((int)$activity->cmid);

        $this->assertIsArray($result);
        $this->assertTrue($result['ready']);
        $this->assertArrayNotHasKey('error_key', $result);

        // Player payload (to_player_payload) — every field present and correct.
        $this->assertSame($asset->playback_id, $result['playback_id']);
        $this->assertNotEmpty($result['session_token']);
        $this->assertSame(64, strlen($result['session_token']));
        $this->assertSame(80, $result['completion_watch_percent']);
        $this->assertSame(120, $result['asset_duration_seconds']);
        $this->assertSame((int)$activity->id, $result['activity_id']);
        $this->assertSame((int)$activity->cmid, $result['cm_id']);
        $this->assertSame((int)$asset->id, $result['asset_id']);
        $this->assertIsBool($result['drm_required']);
        $this->assertFalse($result['drm_required']);
        $this->assertIsBool($result['no_skip_required']);
        $this->assertIsBool($result['has_completed']);
        $this->assertSame('[]', $result['initial_intervals_json']);
        $this->assertSame(0, $result['initial_coverage_percent']);

        // Library URLs are what view.mustache / player.js import (CC9 / M1 player
        // bootstrap). Both are now served from the Moodle site, never a CDN: the
        // player comes from local_fastpix (ADR-017), hls.js is vendored under
        // mod_fastpix and resolved to an absolute wwwroot URL.
        $this->assertSame(
            \local_fastpix\service\playback_service::player_lib_url(),
            $result['player_lib_url']
        );
        $this->assertSame(
            (new \moodle_url(playback_service::HLS_LIB_URL))->out(false),
            $result['hls_lib_url']
        );
        // Regression lock — neither library may load from a CDN (Moodle policy).
        $this->assertStringContainsString($CFG->wwwroot, $result['player_lib_url']);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $result['player_lib_url']);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $result['hls_lib_url']);
    }

    public function test_ready_response_validates_against_execute_returns(): void {
        // Item 5a — a ready result (every VALUE_OPTIONAL player field present)
        // must clean cleanly through the declared return structure.
        $this->resetAfterTest();

        $asset = $this->insert_asset();
        [$activity] = $this->make_activity_for_student((int)$asset->id);

        $result = get_player_state::execute((int)$activity->cmid);
        $cleaned = external_api::clean_returnvalue(get_player_state::execute_returns(), $result);

        $this->assertTrue($cleaned['ready']);
        $this->assertSame($asset->playback_id, $cleaned['playback_id']);
        $this->assertArrayHasKey('player_lib_url', $cleaned);
    }

    // 3. Still-processing asset → ready=false, no player fields, no error_key

    public function test_processing_asset_returns_ready_false_without_player_fields(): void {
        $this->resetAfterTest();

        // Status != 'ready' → resolve_for_view returns view_state_processing.
        // upload_session_id present so the processing branch (not the
        // videounavailable branch) is taken.
        $asset = $this->insert_asset(['status' => 'created']);
        [$activity] = $this->make_activity_for_student((int)$asset->id, null, 424242);

        $result = get_player_state::execute((int)$activity->cmid);

        $this->assertIsArray($result);
        $this->assertFalse($result['ready']);
        $this->assertArrayNotHasKey('error_key', $result);
        // No player payload leaks on a not-ready response.
        $this->assertArrayNotHasKey('playback_id', $result);
        $this->assertArrayNotHasKey('session_token', $result);
        $this->assertArrayNotHasKey('player_lib_url', $result);
    }

    public function test_not_ready_response_validates_against_execute_returns(): void {
        // Item 5b — a not-ready response (player fields ABSENT) must still
        // validate, proving the player fields are correctly VALUE_OPTIONAL.
        $this->resetAfterTest();

        $asset = $this->insert_asset(['status' => 'created']);
        [$activity] = $this->make_activity_for_student((int)$asset->id, null, 424242);

        $result = get_player_state::execute((int)$activity->cmid);
        $cleaned = external_api::clean_returnvalue(get_player_state::execute_returns(), $result);

        $this->assertFalse($cleaned['ready']);
        $this->assertArrayNotHasKey('playback_id', $cleaned);
    }

    // 4. Terminal error → ready=false + error_key

    public function test_deleted_asset_returns_videounavailable_error(): void {
        $this->resetAfterTest();

        // Soft-deleted asset: asset_service::get_by_id returns null, and with no
        // upload_session_id resolve_for_view returns view_state_error
        // ('videounavailable', ADR-010).
        $asset = $this->insert_asset(['deleted_at' => time() - 60]);
        [$activity] = $this->make_activity_for_student((int)$asset->id);

        $result = get_player_state::execute((int)$activity->cmid);

        $this->assertIsArray($result);
        $this->assertFalse($result['ready']);
        $this->assertSame('videounavailable', $result['error_key']);
        $this->assertArrayNotHasKey('playback_id', $result);
    }

    public function test_no_asset_no_session_returns_videounavailable_error(): void {
        $this->resetAfterTest();

        // Fastpix_asset_id NULL and upload_session_id NULL → videounavailable.
        [$activity] = $this->make_activity_for_student(null);

        $result = get_player_state::execute((int)$activity->cmid);

        $this->assertFalse($result['ready']);
        $this->assertSame('videounavailable', $result['error_key']);
    }

    public function test_error_response_validates_against_execute_returns(): void {
        // Error_key is VALUE_OPTIONAL too — a {ready:false, error_key} payload
        // (no player fields) cleans through the structure.
        $this->resetAfterTest();

        $asset = $this->insert_asset(['deleted_at' => time() - 60]);
        [$activity] = $this->make_activity_for_student((int)$asset->id);

        $result = get_player_state::execute((int)$activity->cmid);
        $cleaned = external_api::clean_returnvalue(get_player_state::execute_returns(), $result);

        $this->assertFalse($cleaned['ready']);
        $this->assertSame('videounavailable', $cleaned['error_key']);
    }

    /**
     * drm_unsupported is the view_state_error branch where an asset is
     * drm_required but the resolved payload carried no drm_token. The REAL
     * local_fastpix playback_service always mints a drm_token for DRM assets
     * (the empty-token branch is purely defensive against an older
     * local_fastpix that lacks the property), so it is not reproducible through
     * the live resolve path without mocking the consumed surface — which this
     * suite deliberately does not do (record_view_progress_test pattern).
     *
     * We therefore assert the boundary contract directly: a {ready:false,
     * error_key:'drm_unsupported'} payload — the exact shape execute() emits
     * for ANY view_state_error — cleans through execute_returns. This proves
     * the endpoint's error mapping declares error_key wide enough for the
     * drm_unsupported reason key without leaking player fields (S9).
     */
    public function test_drm_unsupported_error_shape_validates_against_execute_returns(): void {
        $this->resetAfterTest();

        $payload = ['ready' => false, 'error_key' => 'drm_unsupported'];
        $cleaned = external_api::clean_returnvalue(get_player_state::execute_returns(), $payload);

        $this->assertFalse($cleaned['ready']);
        $this->assertSame('drm_unsupported', $cleaned['error_key']);
        $this->assertArrayNotHasKey('playback_id', $cleaned);
    }
}
