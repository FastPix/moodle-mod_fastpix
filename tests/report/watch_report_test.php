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
 * Tests for the watch-report aggregation service.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix\report;

/**
 * @covers \mod_fastpix\report\watch_report
 */
final class watch_report_test extends \advanced_testcase {
    /**
     * Create a course + ready asset (with duration) + linked fastpix activity.
     *
     * @param int $duration Asset duration in seconds.
     * @return array{0:\stdClass,1:\stdClass,2:int} [course, activity, assetid]
     */
    private function setup_video(int $duration): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', [
            'course' => $course->id,
            'completion_watch_percent' => 90,
        ]);
        $now = time();
        $assetid = $DB->insert_record('local_fastpix_asset', (object)[
            'fastpix_id'   => 'asset_' . uniqid(),
            'owner_userid' => 0,
            'title'        => 'Report asset',
            'duration'     => $duration,
            'status'       => 'ready',
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('fastpix', 'fastpix_asset_id', $assetid, ['id' => $activity->id]);
        $activity = $DB->get_record('fastpix', ['id' => $activity->id], '*', MUST_EXIST);
        return [$course, $activity, (int)$assetid];
    }

    /**
     * Add one enrolled student + their attempt with the given watched intervals.
     *
     * @param \stdClass $course
     * @param \stdClass $activity
     * @param int $assetid
     * @param array $intervals [[start,end],...]
     * @param array $extra Overrides on the attempt row.
     * @return \stdClass The created user.
     */
    private function add_attempt(\stdClass $course, \stdClass $activity, int $assetid, array $intervals, array $extra = []): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $DB->insert_record('fastpix_attempt', (object)array_merge([
            'userid'            => $user->id,
            'activity_id'       => $activity->id,
            'asset_id'          => $assetid,
            'session_token'     => 'tok' . $user->id,
            'session_start_ts'  => time() - 200,
            'watched_intervals' => json_encode($intervals),
            'current_position'  => 0,
            'has_completed'     => 0,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ], $extra));
        return $user;
    }

    public function test_video_report_coverage_and_summary(): void {
        [$course, $activity, $assetid] = $this->setup_video(100);
        // A watches 0–50 (50%); B watches the whole thing (100%) and completed.
        $this->add_attempt($course, $activity, $assetid, [[0, 50]]);
        $this->add_attempt($course, $activity, $assetid, [[0, 100]], [
            'has_completed'    => 1,
            'milestone_25_at'  => time(),
            'milestone_50_at'  => time(),
            'milestone_75_at'  => time(),
            'milestone_100_at' => time(),
        ]);

        $report = watch_report::instance()->get_video_report($activity);

        $this->assertSame(100, $report->duration);
        $this->assertSame(2, $report->viewers);
        $this->assertSame(75, $report->summary->avgpercent);       // (50 + 100) / 2
        $this->assertSame(50, $report->summary->completionrate);   // 1 of 2 completed
        $this->assertCount(2, $report->rows);
    }

    public function test_heatmap_is_percentage_of_viewers_per_bucket(): void {
        global $DB;
        [$course, $activity, $assetid] = $this->setup_video(100);
        // A watches first half only; B watches the whole video.
        $this->add_attempt($course, $activity, $assetid, [[0, 50]]);
        $this->add_attempt($course, $activity, $assetid, [[0, 100]]);

        $attempts = $DB->get_records('fastpix_attempt', ['activity_id' => $activity->id]);
        $heatmap = watch_report::instance()->build_heatmap($attempts, 100);

        // First bucket: both viewers (100%). Last bucket: only B (50%).
        $this->assertSame(100, $heatmap->values[0]);
        $this->assertSame(50, $heatmap->values[count($heatmap->values) - 1]);
        // The drop happens mid-timeline.
        $this->assertNotNull($heatmap->dropoff);
        $this->assertSame(50, $heatmap->dropoff['droppct']);
    }

    public function test_user_report_is_scoped_to_the_student(): void {
        [$course, $activity, $assetid] = $this->setup_video(100);
        $a = $this->add_attempt($course, $activity, $assetid, [[0, 40]]);
        $this->add_attempt($course, $activity, $assetid, [[0, 80]]);

        $report = watch_report::instance()->get_user_report((int)$course->id, (int)$a->id);

        $this->assertCount(1, $report->rows);
        $this->assertSame(40, $report->rows[0]->coveragepercent);
    }

    public function test_milestones_text(): void {
        $svc = watch_report::instance();
        $this->assertSame('—', $svc->milestones_text([25 => false, 50 => false, 75 => false, 100 => false]));
        $this->assertSame('25/50%', $svc->milestones_text([25 => true, 50 => true, 75 => false, 100 => false]));
        $this->assertSame('25/50/75/100%', $svc->milestones_text([25 => true, 50 => true, 75 => true, 100 => true]));
    }

    public function test_no_attempts_yields_empty_report(): void {
        [, $activity] = $this->setup_video(100);
        $report = watch_report::instance()->get_video_report($activity);
        $this->assertSame(0, $report->viewers);
        $this->assertSame(0, $report->summary->avgpercent);
        $this->assertEmpty($report->rows);
        $this->assertEmpty($report->heatmap->values);
    }
}
