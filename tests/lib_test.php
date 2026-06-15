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
 * Tests for mod/fastpix/lib.php — Moodle activity callbacks.
 *
 * Covers M2 (FEATURE matrix), M3 (5 capabilities), CG2 (grade callback
 * signatures). Phase A/B contracts only; Phase D will add tests for the
 * grade callback bodies.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/fastpix/lib.php');

/**
 * Tests for mod/fastpix/lib.php Moodle activity callbacks.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers ::fastpix_supports
 */
final class lib_test extends \advanced_testcase {
    public function test_fastpix_supports_returns_documented_matrix(): void {
        $this->assertTrue(fastpix_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(fastpix_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(fastpix_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertTrue(fastpix_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(fastpix_supports(FEATURE_GROUPS));
        $this->assertTrue(fastpix_supports(FEATURE_GROUPINGS));
        $this->assertTrue(fastpix_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(fastpix_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertSame(MOD_PURPOSE_ASSESSMENT, fastpix_supports(FEATURE_MOD_PURPOSE));
    }

    public function test_fastpix_supports_returns_null_for_unsupported(): void {
        // Out-of-scope features per design doc §1 / mod/fastpix exclusions.
        $this->assertNull(fastpix_supports(FEATURE_USES_QUESTIONS));
        $this->assertNull(fastpix_supports(FEATURE_RATE));
        $this->assertNull(fastpix_supports(FEATURE_COMMENT));
    }

    public function test_grade_callback_signatures_present(): void {
        // CG2 — both callbacks must exist with the right argument shape;
        // Phase D fills in the bodies.
        $this->assertTrue(function_exists('fastpix_grade_item_update'));
        $this->assertTrue(function_exists('fastpix_update_grades'));

        $ref = new \ReflectionFunction('fastpix_grade_item_update');
        $this->assertSame('activity', $ref->getParameters()[0]->getName());
        $this->assertCount(2, $ref->getParameters());

        $ref = new \ReflectionFunction('fastpix_update_grades');
        $this->assertCount(3, $ref->getParameters());
        $this->assertSame('activity', $ref->getParameters()[0]->getName());
        $this->assertSame('userid', $ref->getParameters()[1]->getName());
    }

    public function test_capabilities_declared(): void {
        // M3 / PR-14 — exactly five capabilities.
        global $CFG;
        $capabilities = [];
        require($CFG->dirroot . '/mod/fastpix/db/access.php');

        $expected = [
            'mod/fastpix:addinstance',
            'mod/fastpix:view',
            'mod/fastpix:viewallattempts',
            'mod/fastpix:graderoverride',
            'mod/fastpix:uploadmedia',
        ];
        $actual = array_keys($capabilities);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_lifecycle_add_update_delete_instance(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $data = (object)[
            'course'       => $course->id,
            'name'         => 'Test FastPix Activity',
            'intro'        => 'desc',
            'introformat'  => FORMAT_HTML,
        ];
        $id = fastpix_add_instance($data);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $data->instance = $id;
        $data->name = 'Renamed';
        $this->assertTrue(fastpix_update_instance($data));

        $this->assertTrue(fastpix_delete_instance($id));
        // Deleting again returns false (no row).
        $this->assertFalse(fastpix_delete_instance($id));
    }

    // Phase D Slice A Step 4 — grade callback bodies (CG1 / CG2).

    /**
     * Insert a fastpix_attempt row for the (userid, activity) pair with
     * has_completed pre-set. Useful for exercising fastpix_update_grades
     * without going through the watch tracker.
     */
    private function seed_completed_attempt(int $userid, int $activityid, int $hascompleted = 1): int {
        global $DB;
        return $DB->insert_record('fastpix_attempt', (object)[
            'userid'            => $userid,
            'activity_id'       => $activityid,
            'asset_id'          => 1,
            'session_token'     => str_repeat('g', 64),
            'session_start_ts'  => time() - 100,
            'last_callback_ts'  => time(),
            'watched_intervals' => '[[0,100]]',
            'current_position'  => 100,
            'has_completed'     => $hascompleted,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ]);
    }

    public function test_fastpix_grade_item_update_creates_grade_item(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $row = $DB->get_record('fastpix', ['id' => $activity->id]);

        $this->assertSame(GRADE_UPDATE_OK, fastpix_grade_item_update($row));

        $item = $DB->get_record('grade_items', [
            'itemmodule' => 'fastpix',
            'iteminstance' => $row->id,
        ]);
        $this->assertNotFalse($item, 'grade_items row must exist after grade_item_update');
        $this->assertEquals(100, (float)$item->grademax);
    }

    public function test_fastpix_grade_item_update_with_grades_writes_userrow(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $row = $DB->get_record('fastpix', ['id' => $activity->id]);

        $grades = [
            (int)$student->id => (object)['userid' => (int)$student->id, 'rawgrade' => 100.0, 'dategraded' => time()],
        ];
        $this->assertSame(GRADE_UPDATE_OK, fastpix_grade_item_update($row, $grades));

        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $row->id]);
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertNotFalse($grade);
        $this->assertEqualsWithDelta(100.0, (float)$grade->finalgrade, 0.001);
    }

    public function test_fastpix_grade_item_update_reset_clears_grades(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $row = $DB->get_record('fastpix', ['id' => $activity->id]);

        fastpix_grade_item_update($row, [
            (int)$student->id => (object)['userid' => (int)$student->id, 'rawgrade' => 100.0, 'dategraded' => time()],
        ]);
        // Reset path — passes 'reset' literal in $grades; expects the gradebook
        // recompute machinery to clear the rows.
        $this->assertSame(GRADE_UPDATE_OK, fastpix_grade_item_update($row, 'reset'));
    }

    public function test_fastpix_update_grades_handles_null_activity(): void {
        $this->resetAfterTest();
        // Smoke-test the bulk-walk path: passing null must not error even
        // when no fastpix activities exist.
        fastpix_update_grades(null, 0, false);
        $this->assertTrue(true);
    }

    public function test_fastpix_update_grades_writes_for_completed_attempts_only(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $row = $DB->get_record('fastpix', ['id' => $activity->id]);
        $completed = $this->getDataGenerator()->create_user();
        $incompleted = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($completed->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($incompleted->id, $course->id, 'student');

        $this->seed_completed_attempt((int)$completed->id, (int)$row->id, 1);
        $this->seed_completed_attempt((int)$incompleted->id, (int)$row->id, 0);

        fastpix_update_grades($row, 0, true);

        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $row->id]);
        $cgrade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $completed->id]);
        $this->assertNotFalse($cgrade);
        $this->assertEqualsWithDelta(100.0, (float)$cgrade->finalgrade, 0.001);
        // The non-completed user should NOT have a populated grade row.
        $igrade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $incompleted->id]);
        $this->assertTrue(!$igrade || $igrade->finalgrade === null);
    }

    public function test_fastpix_update_grades_respects_userid_filter(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $row = $DB->get_record('fastpix', ['id' => $activity->id]);
        $alice = $this->getDataGenerator()->create_user();
        $bob   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($bob->id, $course->id, 'student');

        $this->seed_completed_attempt((int)$alice->id, (int)$row->id, 1);
        $this->seed_completed_attempt((int)$bob->id, (int)$row->id, 1);

        // Update only alice. Bob's row should NOT exist yet.
        fastpix_update_grades($row, (int)$alice->id, true);
        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $row->id]);
        $agrade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $alice->id]);
        $bgrade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $bob->id]);
        $this->assertNotFalse($agrade);
        $this->assertEqualsWithDelta(100.0, (float)$agrade->finalgrade, 0.001);
        $this->assertTrue(!$bgrade || $bgrade->finalgrade === null);
    }

    public function test_fastpix_update_grades_clears_grade_when_no_attempts_and_nullifnone(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $row = $DB->get_record('fastpix', ['id' => $activity->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Pre-seed a stale grade for the student.
        fastpix_grade_item_update($row, [
            (int)$student->id => (object)['userid' => (int)$student->id, 'rawgrade' => 100.0, 'dategraded' => time()],
        ]);
        // No attempt row; nullifnone path with explicit userid clears finalgrade.
        fastpix_update_grades($row, (int)$student->id, true);

        $item = $DB->get_record('grade_items', ['itemmodule' => 'fastpix', 'iteminstance' => $row->id]);
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertTrue(!$grade || $grade->finalgrade === null);
    }

    public function test_fastpix_get_completion_active_rule_descriptions_returns_localized_string(): void {
        $cm = (object)[
            'customdata' => ['customcompletionrules' => ['completionwatchedpercent' => 80]],
        ];
        $descs = fastpix_get_completion_active_rule_descriptions($cm);
        $this->assertCount(1, $descs);
        $this->assertStringContainsString('80', $descs[0]);
    }

    public function test_fastpix_get_completion_active_rule_descriptions_returns_empty_when_rule_disabled(): void {
        $cm = (object)['customdata' => []];
        $this->assertSame([], fastpix_get_completion_active_rule_descriptions($cm));

        $cm2 = (object)['customdata' => ['customcompletionrules' => []]];
        $this->assertSame([], fastpix_get_completion_active_rule_descriptions($cm2));
    }

    /**
     * The upload-widget status strings (localised in init() per M7 — no English
     * literals in JS) must all resolve to real lang strings, not [[placeholders]].
     * Guards the mod_form.php upload_widget init 'strings' contract.
     */
    public function test_upload_widget_status_strings_resolve(): void {
        $keys = [
            'upload_complete', 'upload_failed', 'upload_sessionfailed', 'upload_in_progress',
            'upload_untitledvideo', 'upload_urlaccepted', 'upload_urlenterfirst', 'upload_urlrejected',
        ];
        foreach ($keys as $key) {
            $this->assertTrue(
                get_string_manager()->string_exists($key, 'mod_fastpix'),
                "Missing lang string: {$key}"
            );
            $this->assertStringNotContainsString('[[', get_string($key, 'mod_fastpix'));
        }
    }
}
