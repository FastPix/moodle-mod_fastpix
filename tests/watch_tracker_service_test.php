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
 * Tests for \mod_fastpix\service\watch_tracker_service.
 *
 * Coverage emphasis is the six fraud checks (PR-9) — each gets a
 * boundary test, and the ones with the 10-second tolerance (S4 ②,
 * ④) get BOTH a clean (tolerance-respected) and fraud (tolerance-
 * exceeded) test. Interval merge gets its own group; happy-path and
 * idempotency round out the surface to ≥90% per M6.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix;

use mod_fastpix\service\watch_tracker_service;
/**
 * Tests for the class(es) listed in @covers.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix\service\watch_tracker_service
 */
final class watch_tracker_service_test extends \advanced_testcase {
    /**
     * Build a clean fixture for tests that exercise record_progress() through
     * its full DB-write path. Returns:
     *   [activity, attempt, asset, context, studentid]
     */
    private function setup_fixture(int $duration = 100, int $threshold = 90, int $noskip = 0): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
        ]);
        $activity = $this->getDataGenerator()->create_module('fastpix', [
            'course' => $course->id,
        ]);
        // Create_module → fastpix_add_instance reads completionwatchedpercent
        // via the mod_form-style param key, not the raw column name. Easiest
        // to skip the form-key dance and just set the columns directly.
        $DB->set_field('fastpix', 'completion_watch_percent', $threshold, ['id' => $activity->id]);
        $DB->set_field('fastpix', 'no_skip_required', $noskip, ['id' => $activity->id]);
        $activity = $DB->get_record('fastpix', ['id' => $activity->id], '*', MUST_EXIST);
        $activity->cmid = $DB->get_field(
            'course_modules',
            'id',
            ['module' => $DB->get_field('modules', 'id', ['name' => 'fastpix']), 'instance' => $activity->id]
        );

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $context = \context_module::instance($activity->cmid);

        $now = time();
        $attemptid = $DB->insert_record('fastpix_attempt', (object)[
            'userid'            => $student->id,
            'activity_id'       => $activity->id,
            'asset_id'          => 1,
            'session_token'     => str_repeat('a', 64),
            'session_start_ts'  => $now - 50, // 50s into the session
            'last_callback_ts'  => null,
            'watched_intervals' => '',
            'current_position'  => 0,
            'has_completed'     => 0,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ]);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attemptid], '*', MUST_EXIST);

        $asset = (object)[
            'id'               => 1,
            'duration'         => $duration,
            'no_skip_required' => $noskip,
            'status'           => 'ready',
            'deleted_at'       => null,
        ];

        // Ensure the role lookup later in the capability_lost test doesn't
        // re-discover this fixture's student in the wrong role.
        $this->setUser($student);

        return [$activity, $attempt, $asset, $context, (int)$student->id, $now];
    }

    // Fraud check ① — exceeds_duration.

    public function test_exceeds_duration_triggers_on_intervals_past_asset_duration(): void {
        $this->resetAfterTest();
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 110]], // Ends past asset duration (100).
            110.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertContains('exceeds_duration', $result->fraud_reasons);
        $this->assertSame('exceeds_duration', $result->attempt->last_fraud_reason);
    }

    // Fraud check ② — exceeds_wall_clock (with 10s tolerance — PR-10).

    public function test_exceeds_wall_clock_triggers_at_elapsed_plus_11s(): void {
        $this->resetAfterTest();
        // Session started 50s ago — 61s of watch claim is +11 over elapsed.
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 61]], // 61s claimed; elapsed=50; 61 > 50+10
            61.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertContains('exceeds_wall_clock', $result->fraud_reasons);
    }

    public function test_exceeds_wall_clock_clean_at_elapsed_plus_9s(): void {
        $this->resetAfterTest();
        // 59s claimed; elapsed=50; 59 <= 50+10 — within tolerance.
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 59]],
            59.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertNotContains('exceeds_wall_clock', $result->fraud_reasons);
    }

    // Fraud check ③ — regression.

    public function test_regression_triggers_when_client_coverage_less_than_server(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        // Seed server with [0,40] already watched.
        $attempt->watched_intervals = '[[0,40]]';
        $DB->update_record('fastpix_attempt', $attempt);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        // Client claims only [0,20] — that's a regression.
        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 20]],
            20.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertContains('regression', $result->fraud_reasons);
    }

    // Fraud check ④ — implausible_gain (with 10s tolerance — PR-10).

    public function test_implausible_gain_triggers_when_gain_exceeds_wall_plus_10(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(1000);

        // Previous callback was 5s ago; client claims gain of 16 seconds.
        $attempt->last_callback_ts = $now - 5;
        $attempt->watched_intervals = '[[0,30]]';
        $DB->update_record('fastpix_attempt', $attempt);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        // Need fresh now > session_start so check ② doesn't trip too.
        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 46]], // Gain=16; wall=5; 16 > 5+10 → fraud.
            46.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertContains('implausible_gain', $result->fraud_reasons);
    }

    public function test_implausible_gain_clean_at_gain_equals_wall_plus_9(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(1000);

        $attempt->last_callback_ts = $now - 5;
        $attempt->watched_intervals = '[[0,30]]';
        $DB->update_record('fastpix_attempt', $attempt);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        // Gain=14; wall=5; 14 <= 5+10 → clean (within tolerance).
        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 44]],
            44.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertNotContains('implausible_gain', $result->fraud_reasons);
    }

    // Fraud check ⑤ — capability_lost.

    public function test_capability_lost_triggers_when_capability_revoked(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        // Yank mod/fastpix:view from the student role within this module
        // context — has_capability($cap, $context, $userid) will now return false.
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
        assign_capability('mod/fastpix:view', CAP_PROHIBIT, $studentrole->id, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 5]],
            5.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertContains('capability_lost', $result->fraud_reasons);
    }

    // Fraud check ⑥ — seek_on_noskip.

    public function test_seek_on_noskip_triggers_when_seek_count_increases_on_noskip_activity(): void {
        $this->resetAfterTest();
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 90, /* noskip */ 1);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 5]],
            5.0,
            false, /* client_seek_count */
            1,
            $context,
            $now
        );

        $this->assertContains('seek_on_noskip', $result->fraud_reasons);
    }

    public function test_seek_on_noskip_clean_when_activity_allows_seeking(): void {
        $this->resetAfterTest();
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 90, /* noskip */ 0);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 5]],
            5.0,
            false, /* client_seek_count */
            1,
            $context,
            $now
        );

        $this->assertNotContains('seek_on_noskip', $result->fraud_reasons);
    }

    // Interval merge.

    public function test_merge_intervals_handles_empty_existing(): void {
        $merged = watch_tracker_service::instance()->merge_intervals([], [[0, 5], [10, 15]]);
        $this->assertSame([[0.0, 5.0], [10.0, 15.0]], $merged);
    }

    public function test_merge_intervals_handles_disjoint(): void {
        $merged = watch_tracker_service::instance()->merge_intervals([[0, 5]], [[10, 15]]);
        $this->assertSame([[0.0, 5.0], [10.0, 15.0]], $merged);
    }

    public function test_merge_intervals_merges_adjacent_within_epsilon(): void {
        // Intervals [0,5] and [5.005, 10] → gap = 0.005 ≤ MERGE_EPS_S(0.01) → merge.
        $merged = watch_tracker_service::instance()->merge_intervals([[0, 5]], [[5.005, 10]]);
        $this->assertSame([[0.0, 10.0]], $merged);
    }

    public function test_merge_intervals_merges_overlapping(): void {
        $merged = watch_tracker_service::instance()->merge_intervals([[0, 7]], [[5, 12]]);
        $this->assertSame([[0.0, 12.0]], $merged);
    }

    public function test_merge_intervals_sorts_by_start(): void {
        // Input out of order — output must be sorted.
        $merged = watch_tracker_service::instance()->merge_intervals([[20, 25], [0, 5]], [[10, 15]]);
        $this->assertSame([[0.0, 5.0], [10.0, 15.0], [20.0, 25.0]], $merged);
    }

    // Happy path.

    public function test_clean_callback_updates_watched_intervals(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 5]],
            5.0,
            false,
            0,
            $context,
            $now
        );

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        // Json_decode returns int when the encoded value happens to be an
        // integer (PHP-stdlib behaviour); compare with assertEquals (loose)
        // rather than assertSame (strict-type) so [0,5] vs [0.0,5.0] passes.
        $this->assertEquals([[0, 5]], json_decode($reloaded->watched_intervals, true));
    }

    public function test_clean_callback_updates_current_position(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 5]],
            5.5,
            false,
            0,
            $context,
            $now
        );

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertEqualsWithDelta(5.5, (float)$reloaded->current_position, 0.001);
    }

    public function test_clean_callback_updates_last_callback_ts(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 5]],
            5.0,
            false,
            0,
            $context,
            $now
        );

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame($now, (int)$reloaded->last_callback_ts);
    }

    public function test_clean_callback_fires_milestone_25_idempotently(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 99);

        // 25 seconds out of 100 = 25%. First call fires milestone_25.
        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 25]],
            25.0,
            false,
            0,
            $context,
            $now
        );
        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $firstmilestonets = (int)$reloaded->milestone_25_at;
        $this->assertGreaterThan(0, $firstmilestonets);

        // Repeat call at same coverage — milestone_25_at must NOT change (CG5).
        watch_tracker_service::instance()->record_progress(
            $activity,
            $reloaded,
            $asset,
            [[0, 25]],
            25.0,
            false,
            0,
            $context,
            $now + 5
        );
        $reloaded2 = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame($firstmilestonets, (int)$reloaded2->milestone_25_at);
    }

    public function test_clean_callback_sets_has_completed_when_threshold_crossed(): void {
        $this->resetAfterTest();
        global $DB;
        // Threshold=50 → 50s/100s = 50% triggers completion.
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 50);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 55]],
            55.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertEmpty($result->fraud_reasons);
        $this->assertSame('complete', $result->completion_state);
        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame(1, (int)$reloaded->has_completed);
    }

    public function test_ended_fired_does_not_force_completion_below_threshold(): void {
        $this->resetAfterTest();
        global $DB;
        // Threshold=99 → 5/100 = 5% coverage, well under. ended_fired is recorded
        // for fraud audit but is NOT a completion shortcut: a learner who seeks to
        // the end without watching has near-zero coverage and must NOT complete
        // (watch_tracker_service::record_progress — completion is coverage-only).
        // Treating 'ended' as completion would be a trivial skip-to-complete
        // bypass of the watch-tracking model (S4).
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 99);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 5]],
            5.0, /* ended_fired */
            true,
            0,
            $context,
            $now
        );

        $this->assertSame('in_progress', $result->completion_state);
        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame(0, (int)$reloaded->has_completed);
    }

    // Fraud non-update — invariants on the failing path.

    public function test_fraud_increments_fraud_count(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        // Interval [[100, 110]] trips ONLY exceeds_duration (max_end=110 > duration=100):
        // - claimed coverage = 10 → under elapsed (50) + 10 tolerance → ② clean
        // - server_persisted = 0 → ③ no regression
        // - gain = 10, wall = 50, 10 < 60 → ④ clean
        // - capability ok → ⑤ clean
        // - no_skip_required=0 → ⑥ clean.
        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[100, 110]],
            110.0,
            false,
            0,
            $context,
            $now
        );

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame(1, (int)$reloaded->fraud_count);
    }

    public function test_fraud_does_not_update_watched_intervals(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 110]],
            110.0,
            false,
            0,
            $context,
            $now
        );

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame('', (string)$reloaded->watched_intervals);
    }

    public function test_fraud_does_not_update_current_position(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);

        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 110]],
            110.0,
            false,
            0,
            $context,
            $now
        );

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertEqualsWithDelta(0.0, (float)$reloaded->current_position, 0.001);
    }

    public function test_fraud_does_not_trigger_completion(): void {
        $this->resetAfterTest();
        global $DB;
        // Threshold=10 — would trip on any small claim. But we send fraud.
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 10);

        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 999]], // Exceeds_duration AND exceeds_wall_clock.
            999.0,
            false,
            0,
            $context,
            $now
        );

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame(0, (int)$reloaded->has_completed);
    }

    // Idempotency.

    public function test_replay_does_not_re_fire_completion_when_has_completed_already(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 50);

        // First call crosses threshold.
        $first = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 55]],
            55.0,
            false,
            0,
            $context,
            $now
        );
        $this->assertSame('complete', $first->completion_state);

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame(1, (int)$reloaded->has_completed);

        // Replay same payload — has_completed stays 1, completion_state stays complete,
        // no new milestone re-fires.
        $second = watch_tracker_service::instance()->record_progress(
            $activity,
            $reloaded,
            $asset,
            [[0, 55]],
            55.0,
            false,
            0,
            $context,
            $now + 1
        );
        $this->assertSame('complete', $second->completion_state);
        $reloaded2 = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame(1, (int)$reloaded2->has_completed);
    }

    // Phase D Slice A Step 4 — grade-transition gating (CG4).

    public function test_grade_update_fires_on_completion_transition_0_to_1(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, $studentid, $now] = $this->setup_fixture(100, 50);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 55]],
            55.0,
            false,
            0,
            $context,
            $now
        );
        $this->assertSame('complete', $result->completion_state);

        // Grade_item now exists, and the student has a grade_grades row.
        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $activity->id]);
        $this->assertNotFalse($item, 'grade_item row must exist after transition');
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $studentid]);
        $this->assertNotFalse($grade, 'grade_grades row must exist after transition');
        $this->assertEqualsWithDelta((float)$activity->grademax, (float)$grade->finalgrade, 0.001);
    }

    public function test_grade_update_does_not_fire_when_already_complete(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, $studentid, $now] = $this->setup_fixture(100, 50);

        // Pre-set has_completed=1 so this is a replay-style call, NOT a transition.
        $attempt->has_completed = 1;
        $DB->update_record('fastpix_attempt', $attempt);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 55]],
            55.0,
            false,
            0,
            $context,
            $now
        );

        // The grade item exists from activity creation (standard Moodle); what
        // must NOT happen on a non-transition callback is a grade VALUE being
        // written for the student.
        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $activity->id]);
        $grade = $item ? $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $attempt->userid]) : false;
        $this->assertTrue(
            $grade === false || $grade->rawgrade === null,
            'no grade value must be written for the student on a non-transition callback'
        );
    }

    public function test_grade_update_does_not_fire_on_fraud_callback(): void {
        $this->resetAfterTest();
        global $DB;
        // Threshold low enough that the claim WOULD cross — but the fraud
        // check ① (exceeds_duration) rejects the payload first.
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 10);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[100, 110]], // Max_end=110 > duration=100 → fraud.
            110.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertNotEmpty($result->fraud_reasons);
        $this->assertSame('in_progress', $result->completion_state);

        // Grade item exists from creation; assert no grade VALUE was written.
        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $activity->id]);
        $grade = $item ? $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $attempt->userid]) : false;
        $this->assertTrue(
            $grade === false || $grade->rawgrade === null,
            'no grade value must be written for the student on a fraud-rejected callback'
        );
    }

    public function test_grade_update_writes_grademax_not_partial_credit(): void {
        $this->resetAfterTest();
        global $DB;
        // Grademax defaults to 100; threshold 50 → covers 55%, completes.
        [$activity, $attempt, $asset, $context, $studentid, $now] = $this->setup_fixture(100, 50);
        $DB->set_field('fastpix', 'grademax', 80, ['id' => $activity->id]);
        $activity = $DB->get_record('fastpix', ['id' => $activity->id]);

        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 55]],
            55.0,
            false,
            0,
            $context,
            $now
        );

        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $activity->id]);
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $studentid]);
        // CG4 — completion is binary; rawgrade is the activity's grademax,
        // not coverage_percent / threshold-fraction / anything else.
        $this->assertEqualsWithDelta(80.0, (float)$grade->finalgrade, 0.001);
    }

    // Phase D Slice B — edge-case hardening per tt.md.

    public function test_merge_rollback_when_client_intervals_shorter_than_server(): void {
        $this->resetAfterTest();
        global $DB;
        // Tt.md edge case #14 — client lost localStorage state and POSTs
        // a strict subset of what the server already knows. The observable
        // contract: server intervals are unchanged AND fraud_count
        // increments via ③ regression.
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100);
        $attempt->watched_intervals = '[[0,80]]';
        $DB->update_record('fastpix_attempt', $attempt);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 20]], // Client claims less than server has.
            20.0,
            false,
            0,
            $context,
            $now
        );

        $this->assertContains('regression', $result->fraud_reasons);

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame(
            '[[0,80]]',
            (string)$reloaded->watched_intervals,
            'server intervals must NOT be replaced by the shorter client payload'
        );
        $this->assertSame(1, (int)$reloaded->fraud_count);
    }

    public function test_ended_event_snaps_coverage_to_full_duration(): void {
        $this->resetAfterTest();
        global $DB;
        // Tt.md edge case #23 — client posts intervals topping out at 99.97
        // with ended_fired=true. Server forces has_completed=1 even if the
        // raw coverage percent would otherwise be 99 (rounded). Threshold
        // here is set artificially high (100) so the test isolates
        // ended_fired as the cause, not the threshold comparator.
        //
        // session_start_ts must be far enough back that ② exceeds_wall_clock
        // is satisfied — claiming ~100s of watch needs ~100s of elapsed.
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 100);
        $attempt->session_start_ts = $now - 200;
        $DB->update_record('fastpix_attempt', $attempt);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        $result = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 99.97]],
            99.97, /* ended_fired */
            true,
            0,
            $context,
            $now
        );

        $this->assertEmpty($result->fraud_reasons);
        $this->assertSame('complete', $result->completion_state);
        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertSame(1, (int)$reloaded->has_completed);
    }

    public function test_pause_then_resume_continues_to_credit_subsequent_intervals(): void {
        $this->resetAfterTest();
        global $DB;
        // Smoke-bug-2 regression: confirm the server-side merge is correct
        // for the pause-then-resume case (clean callback [[0,5]] followed by
        // a separate callback [[5,15]]). If THIS test passes, the merge is
        // fine and the pause/play "strip stops updating" bug is purely
        // client-side (stuck isSeeking / endedFired in the play handler).
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 90);
        $attempt->session_start_ts = $now - 300;
        $DB->update_record('fastpix_attempt', $attempt);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        $svc = watch_tracker_service::instance();

        $r1 = $svc->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 5]],
            5.0,
            false,
            0,
            $context,
            $now
        );
        $this->assertEmpty($r1->fraud_reasons, 'pre-pause callback must be clean');

        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        // Post-resume callback — note: the client legitimately may post the
        // full state [[0,5],[5,15]], but we test the worst-case where it only
        // sends the new segment [[5,15]] to confirm the server-side merge
        // accumulates correctly.
        $r2 = $svc->record_progress(
            $activity,
            $attempt,
            $asset,
            [[5, 15]],
            15.0,
            false,
            0,
            $context,
            $now + 30
        );
        $this->assertEmpty($r2->fraud_reasons, 'post-resume callback must be clean');

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertEquals(
            [[0, 15]],
            json_decode($reloaded->watched_intervals, true),
            'server merge must collapse [[0,5]] + [[5,15]] to [[0,15]]'
        );
    }

    public function test_idempotent_multi_session_merge(): void {
        $this->resetAfterTest();
        global $DB;
        // Two record_progress calls representing two browser sessions.
        // Session 2 posts [[0,30],[40,100]] (the full client state, including
        // what session 1 already saved). The server merges with [[0,30]] and
        // arrives at [[0,30],[40,100]] = 90s = 90% = complete.
        //
        // Need a long session_start_ts to keep ② / ④ checks within tolerance
        // across both calls.
        [$activity, $attempt, $asset, $context, , $now] = $this->setup_fixture(100, 90);
        $attempt->session_start_ts = $now - 300;
        $DB->update_record('fastpix_attempt', $attempt);
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        // Session 1 callback.
        $r1 = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 30]],
            30.0,
            false,
            0,
            $context,
            $now
        );
        $this->assertEmpty($r1->fraud_reasons);
        $this->assertSame(30, $r1->coverage_percent);

        // Reload; simulate session 2 starting later.
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $now2 = $now + 200;

        // Session 2 callback — client sends FULL state including the new
        // [40, 100] segment.
        $r2 = watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 30], [40, 100]],
            100.0,
            false,
            0,
            $context,
            $now2
        );
        $this->assertEmpty($r2->fraud_reasons, 'multi-session POST must not trip any fraud check');
        $this->assertSame('complete', $r2->completion_state);
        $this->assertSame(90, $r2->coverage_percent);

        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);
        $this->assertEquals(
            [[0, 30], [40, 100]],
            json_decode($reloaded->watched_intervals, true),
            'server-side merge must accumulate both sessions'
        );
        $this->assertSame(1, (int)$reloaded->has_completed);
    }

    public function test_subsequent_clean_callbacks_after_completion_do_not_re_write_grade(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $attempt, $asset, $context, $studentid, $now] = $this->setup_fixture(100, 50);

        // First call — transition fires grade_update.
        watch_tracker_service::instance()->record_progress(
            $activity,
            $attempt,
            $asset,
            [[0, 55]],
            55.0,
            false,
            0,
            $context,
            $now
        );
        $reloaded = $DB->get_record('fastpix_attempt', ['id' => $attempt->id]);

        // Snapshot grade_grades_history rowcount immediately after the transition.
        // Each grade_update writes one history row (Moodle's gradebook audit log);
        // a second write would bump this counter.
        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $activity->id]);
        $historyafterfirst = $DB->count_records(
            'grade_grades_history',
            ['itemid' => $item->id, 'userid' => $studentid]
        );

        // Second clean callback (more coverage), still in COMPLETE state.
        watch_tracker_service::instance()->record_progress(
            $activity,
            $reloaded,
            $asset,
            [[0, 80]],
            80.0,
            false,
            0,
            $context,
            $now + 30
        );

        $historyaftersecond = $DB->count_records(
            'grade_grades_history',
            ['itemid' => $item->id, 'userid' => $studentid]
        );

        // No new history row → grade_update was NOT invoked on the replay.
        $this->assertSame(
            $historyafterfirst,
            $historyaftersecond,
            'grade_grades_history must not grow on post-transition callbacks (CG4 idempotency)'
        );
    }
}
