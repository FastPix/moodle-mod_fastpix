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
 * Tests for \mod_fastpix\completion\custom_completion.
 *
 * Per CG3 / PR-19, exactly ONE rule (completionwatchedpercent). The
 * boundary tests (89/90/91 against threshold 90) protect the threshold
 * comparator from off-by-one regressions. The sticky-complete test
 * verifies CG4 — once has_completed=1, get_state must return COMPLETE
 * even if a teacher later raises the threshold.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix;

use mod_fastpix\completion\custom_completion;
/**
 * Tests for the class(es) listed in @covers.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix\completion\custom_completion
 */
final class custom_completion_test extends \advanced_testcase {
    /**
     * Build (cm_info, studentid, activity, asset_id) for tests that need to
     * exercise get_state() against a real attempt row. Seeds a
     * local_fastpix_asset row whose id is returned so callers can update
     * mdl_fastpix_attempt.asset_id to match.
     */
    private function setup_fixture(int $duration = 100, int $threshold = 90): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $generated = $this->getDataGenerator()->create_module('fastpix', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
        // The cmid is a synthetic property the generator hangs off the return value
        // (the mdl_fastpix row itself has no cmid column); preserve it before
        // we reload the row from DB.
        $cmid = (int)$generated->cmid;

        // The create_module() call routes via fastpix_add_instance, which only reads
        // completionwatchedpercent if the *enabled* flag is set. Faster to
        // set the column directly here than to thread the form-key dance.
        $DB->set_field('fastpix', 'completion_watch_percent', $threshold, ['id' => $generated->id]);
        $activity = $DB->get_record('fastpix', ['id' => $generated->id], '*', MUST_EXIST);
        $activity->cmid = $cmid;

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $now = time();
        $assetid = $DB->insert_record('local_fastpix_asset', (object)[
            'fastpix_id'   => 'cc_test_' . uniqid(),
            'owner_userid' => $student->id,
            'title'        => 'cc fixture',
            'duration'     => $duration,
            'status'       => 'ready',
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        $cm = get_fast_modinfo($course->id)->get_cm($cmid);

        return [$cm, (int)$student->id, $activity, (int)$assetid];
    }

    /**
     * Insert an attempt row mirroring the watch_intervals + has_completed
     * the test needs to seed. Returns the inserted row id.
     */
    private function seed_attempt(int $userid, int $activityid, int $assetid, string $intervals = '', int $hascompleted = 0): int {
        global $DB;
        return $DB->insert_record('fastpix_attempt', (object)[
            'userid'            => $userid,
            'activity_id'       => $activityid,
            'asset_id'          => $assetid,
            'session_token'     => str_repeat('c', 64),
            'session_start_ts'  => time() - 100,
            'last_callback_ts'  => time(),
            'watched_intervals' => $intervals,
            'current_position'  => 0,
            'has_completed'     => $hascompleted,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ]);
    }


    public function test_get_defined_custom_rules_returns_single_rule(): void {
        $this->assertSame(['completionwatchedpercent'], custom_completion::get_defined_custom_rules());
    }

    public function test_get_state_throws_on_unknown_rule(): void {
        $this->resetAfterTest();
        [$cm, $studentid] = $this->setup_fixture();

        $cc = new custom_completion($cm, $studentid);

        $this->expectException(\coding_exception::class);
        $cc->get_state('completion_imaginary');
    }

    public function test_get_state_incomplete_when_no_attempt(): void {
        $this->resetAfterTest();
        [$cm, $studentid] = $this->setup_fixture();

        $cc = new custom_completion($cm, $studentid);
        $this->assertSame(COMPLETION_INCOMPLETE, $cc->get_state('completionwatchedpercent'));
    }

    public function test_get_state_complete_when_has_completed_flag_set(): void {
        $this->resetAfterTest();
        // Threshold 90, but has_completed=1 → sticky complete (CG4).
        [$cm, $studentid, $activity, $assetid] = $this->setup_fixture(100, 90);
        // Trivially-under-threshold intervals, but has_completed forces COMPLETE.
        $this->seed_attempt($studentid, (int)$activity->id, $assetid, '[[0,5]]', 1);

        $cc = new custom_completion($cm, $studentid);
        $this->assertSame(COMPLETION_COMPLETE, $cc->get_state('completionwatchedpercent'));
    }

    public function test_get_state_complete_at_exactly_threshold(): void {
        $this->resetAfterTest();
        // 90 seconds out of 100 = 90%. Threshold 90 → COMPLETE.
        [$cm, $studentid, $activity, $assetid] = $this->setup_fixture(100, 90);
        $this->seed_attempt($studentid, (int)$activity->id, $assetid, '[[0,90]]');

        $cc = new custom_completion($cm, $studentid);
        $this->assertSame(COMPLETION_COMPLETE, $cc->get_state('completionwatchedpercent'));
    }

    public function test_get_state_incomplete_just_below_threshold(): void {
        $this->resetAfterTest();
        // 89 / 100 = 89% < 90% → INCOMPLETE.
        [$cm, $studentid, $activity, $assetid] = $this->setup_fixture(100, 90);
        $this->seed_attempt($studentid, (int)$activity->id, $assetid, '[[0,89]]');

        $cc = new custom_completion($cm, $studentid);
        $this->assertSame(COMPLETION_INCOMPLETE, $cc->get_state('completionwatchedpercent'));
    }

    public function test_get_state_complete_just_above_threshold(): void {
        $this->resetAfterTest();
        // 91 / 100 = 91% ≥ 90% → COMPLETE.
        [$cm, $studentid, $activity, $assetid] = $this->setup_fixture(100, 90);
        $this->seed_attempt($studentid, (int)$activity->id, $assetid, '[[0,91]]');

        $cc = new custom_completion($cm, $studentid);
        $this->assertSame(COMPLETION_COMPLETE, $cc->get_state('completionwatchedpercent'));
    }

    public function test_get_state_incomplete_when_intervals_empty(): void {
        $this->resetAfterTest();
        [$cm, $studentid, $activity, $assetid] = $this->setup_fixture();
        $this->seed_attempt($studentid, (int)$activity->id, $assetid, '');

        $cc = new custom_completion($cm, $studentid);
        $this->assertSame(COMPLETION_INCOMPLETE, $cc->get_state('completionwatchedpercent'));
    }

    public function test_get_state_incomplete_when_duration_zero(): void {
        $this->resetAfterTest();
        // When duration=0, compute_initial_coverage_percent returns 0, so
        // never reaches threshold → INCOMPLETE regardless of intervals.
        [$cm, $studentid, $activity, $assetid] = $this->setup_fixture(0, 90);
        $this->seed_attempt($studentid, (int)$activity->id, $assetid, '[[0,30]]');

        $cc = new custom_completion($cm, $studentid);
        $this->assertSame(COMPLETION_INCOMPLETE, $cc->get_state('completionwatchedpercent'));
    }

    public function test_get_sort_order_lists_standard_and_custom_conditions(): void {
        $this->resetAfterTest();
        [$cm, $studentid] = $this->setup_fixture();

        $cc = new custom_completion($cm, $studentid);
        // Must include the standard conditions this activity supports plus the
        // custom rule, or Moodle throws when a teacher enables a standard one.
        $this->assertSame([
            'completionview',
            'completionusegrade',
            'completionpassgrade',
            'completionwatchedpercent',
        ], $cc->get_sort_order());
        // The custom rule set itself stays exactly one (CG3).
        $this->assertSame(['completionwatchedpercent'], $cc::get_defined_custom_rules());
    }

    public function test_get_custom_rule_descriptions_returns_one_entry(): void {
        $this->resetAfterTest();
        [$cm, $studentid] = $this->setup_fixture();

        $cc = new custom_completion($cm, $studentid);
        $descs = $cc->get_custom_rule_descriptions();
        $this->assertSame(['completionwatchedpercent'], array_keys($descs));
        $this->assertNotEmpty($descs['completionwatchedpercent']);
    }
}
