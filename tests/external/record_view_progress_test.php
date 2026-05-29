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
 * Tests for the record_view_progress external function of the FastPix activity module.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix\external;

use mod_fastpix\external\record_view_progress;

/**
 * Tests for \mod_fastpix\external\record_view_progress.
 *
 * Auth dance order is verified by the failure-mode tests below (PR-7):
 * if validate_parameters / validate_context / require_capability /
 * session_token_service::resolve_active_attempt are not invoked in the
 * documented order, these tests will surface the wrong exception type
 * for the wrong stage.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix\external\record_view_progress
 */
final class record_view_progress_test extends \advanced_testcase {
    /**
     * Build a full pre-execute fixture: course + activity + student + attempt
     * + a real local_fastpix_asset row whose id matches attempt.asset_id.
     * setUser($student) so $USER inside execute() resolves correctly.
     *
     * Returns: stdClass with course, activity, student, attempt, asset, sessiontoken.
     */
    private function setup_fixture(
        int $duration = 100,
        string $sessiontoken = null,
        string $completionstate = 'in_progress'
    ): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
        ]);
        $activity = $this->getDataGenerator()->create_module('fastpix', [
            'course' => $course->id,
            'completion_watch_percent' => 90,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $now = time();
        $assetid = $DB->insert_record('local_fastpix_asset', (object)[
            'fastpix_id'   => 'test_asset_' . uniqid(),
            'owner_userid' => $student->id,
            'title'        => 'Phpunit asset',
            'duration'     => $duration,
            'status'       => 'ready',
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        // For session token verification we need a token the
        // session_token_service can re-derive. Use the real service.
        if ($sessiontoken === null) {
            $sessiontoken = \mod_fastpix\service\session_token_service::instance()
                ->issue((int)$student->id, (int)$activity->id, $now - 50);
        }

        $attemptid = $DB->insert_record('fastpix_attempt', (object)[
            'userid'            => $student->id,
            'activity_id'       => $activity->id,
            'asset_id'          => $assetid,
            'session_token'     => $sessiontoken,
            'session_start_ts'  => $now - 50,
            'last_callback_ts'  => null,
            'watched_intervals' => '',
            'current_position'  => 0,
            'has_completed'     => 0,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => $completionstate,
        ]);

        $this->setUser($student);

        return (object)[
            'course'       => $course,
            'activity'     => $activity,
            'student'      => $student,
            'attempt'      => $DB->get_record('fastpix_attempt', ['id' => $attemptid]),
            'sessiontoken' => $sessiontoken,
        ];
    }

    // Happy path.

    public function test_happy_path_returns_coverage_and_completion(): void {
        $this->resetAfterTest();
        $f = $this->setup_fixture(100);

        $result = record_view_progress::execute(
            (int)$f->activity->cmid,
            $f->sessiontoken,
            '[[0,30]]', // 30 seconds watched
            30.0,
            0,
            false
        );

        $this->assertIsArray($result);
        $this->assertSame(30, $result['coverage_percent']);
        $this->assertSame('in_progress', $result['completion_state']);
        $this->assertSame(0, $result['fraud_count']);
    }

    // Auth dance failures (each maps to a distinct exception).

    public function test_missing_cmid_throws(): void {
        $this->resetAfterTest();
        $f = $this->setup_fixture();

        $this->expectException(\dml_missing_record_exception::class);
        record_view_progress::execute(
            /* cmid */            999999,
            $f->sessiontoken,
            '[]',
            0.0,
            0,
            false
        );
    }

    public function test_capability_lost_blocked(): void {
        $this->resetAfterTest();
        global $DB;
        $f = $this->setup_fixture();

        // Yank the capability before invoking. Either required_capability_exception
        // OR require_login_exception is acceptable — both extend moodle_exception
        // and both correctly block the endpoint. (CAP_PROHIBIT on the
        // visibility-gating capability often manifests as the module appearing
        // hidden, which Moodle reports via require_login first.)
        $context = \context_module::instance((int)$f->activity->cmid);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
        assign_capability('mod/fastpix:view', CAP_PROHIBIT, $studentrole->id, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\moodle_exception::class);
        record_view_progress::execute(
            (int)$f->activity->cmid,
            $f->sessiontoken,
            '[]',
            0.0,
            0,
            false
        );
    }

    public function test_session_token_mismatch_throws_session_invalid(): void {
        $this->resetAfterTest();
        $f = $this->setup_fixture();

        // Use errorcode (the API contract) — the message is a lang string,
        // not a stable contract.
        try {
            record_view_progress::execute(
                (int)$f->activity->cmid,
                /* wrong token, valid format */ str_repeat('z', 64),
                '[]',
                0.0,
                0,
                false
            );
            $this->fail('expected moodle_exception was not thrown');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_invalid', $e->errorcode);
        }
    }

    public function test_attempt_not_found_throws_no_attempt(): void {
        $this->resetAfterTest();
        global $DB;
        $f = $this->setup_fixture();

        // Remove the attempt row so resolve_active_attempt cannot find it.
        $DB->delete_records('fastpix_attempt', ['id' => $f->attempt->id]);

        try {
            record_view_progress::execute(
                (int)$f->activity->cmid,
                $f->sessiontoken,
                '[]',
                0.0,
                0,
                false
            );
            $this->fail('expected moodle_exception was not thrown');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_no_attempt', $e->errorcode);
        }
    }

    public function test_attempt_completed_throws_session_finalised(): void {
        $this->resetAfterTest();
        // Use the fixture's optional completion-state flag to seed a
        // finalised attempt row. session_token_service::resolve_active_attempt
        // throws on completion_state !== 'in_progress'.
        $f = $this->setup_fixture(100, null, 'complete');

        try {
            record_view_progress::execute(
                (int)$f->activity->cmid,
                $f->sessiontoken,
                '[[0,5]]',
                5.0,
                0,
                false
            );
            $this->fail('expected moodle_exception was not thrown');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_finalised', $e->errorcode);
        }
    }

    // Payload validation.

    public function test_invalid_json_intervals_throws(): void {
        $this->resetAfterTest();
        $f = $this->setup_fixture();

        $this->expectException(\invalid_parameter_exception::class);
        record_view_progress::execute(
            (int)$f->activity->cmid,
            $f->sessiontoken,
            'this-is-not-json',
            0.0,
            0,
            false
        );
    }

    public function test_malformed_intervals_throws(): void {
        $this->resetAfterTest();
        $f = $this->setup_fixture();

        // Valid JSON but wrong shape (entries aren't [number, number]).
        $this->expectException(\invalid_parameter_exception::class);
        record_view_progress::execute(
            (int)$f->activity->cmid,
            $f->sessiontoken,
            '[{"start": 0, "end": 5}]',
            5.0,
            0,
            false
        );
    }

    public function test_too_many_intervals_throws(): void {
        $this->resetAfterTest();
        $f = $this->setup_fixture();

        $payload = [];
        $cap = \mod_fastpix\service\watch_tracker_service::MAX_INTERVALS;
        for ($i = 0; $i <= $cap; $i++) {
            $payload[] = [$i, $i + 0.1];
        }

        $this->expectException(\invalid_parameter_exception::class);
        record_view_progress::execute(
            (int)$f->activity->cmid,
            $f->sessiontoken,
            json_encode($payload),
            0.0,
            0,
            false
        );
    }
}
