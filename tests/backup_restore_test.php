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
 * Backup/restore tests for mod_fastpix (rule M9, ADR-010 / BR1).
 *
 * Exercises the four stepslib files committed under backup/moodle2/ through
 * the real backup_controller / restore_controller harness (no mocking of the
 * pipeline). Two scenarios per the Phase E gate checklist:
 *
 *   1. Same-account round-trip — the activity row, every fastpix_attempt row,
 *      and the fastpix_id/asset reference (fastpix_asset_id) survive. Asset
 *      BYTES are NOT captured: we only persist the integer reference.
 *
 *   2. Cross-FastPix-account restore — the preserved fastpix_asset_id points
 *      at an asset row absent from the target Moodle's local_fastpix tenant.
 *      Restore must complete gracefully (no throw, no corruption) and keep the
 *      reference verbatim so view.php's resolve path can return the documented
 *      "Video unavailable" state (ADR-010 / PR-22 — do NOT recreate the asset).
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix;

use advanced_testcase;
use backup;
use backup_controller;
use backup_setting;
use restore_controller;
use restore_dbops;
use stdClass;
/**
 * Tests for the listed class.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \backup_fastpix_activity_structure_step
 * @covers     \restore_fastpix_activity_structure_step
 */
final class backup_restore_test extends advanced_testcase {
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        parent::setUpBeforeClass();
    }

    /**
     * Stand up a course + fastpix activity with a known fastpix_asset_id, then
     * (optionally) one attempt row per supplied user with realistic, distinct
     * field values. Returns [course, activity, [attemptid => attemptrow, ...]].
     *
     * @param int|null $assetid value to write to fastpix.fastpix_asset_id
     * @param array $users user records to give attempts (empty = no attempts)
     */
    private function setup_activity(?int $assetid, array $users = []): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $activity = $this->getDataGenerator()->create_module('fastpix', [
            'course'                   => $course->id,
            'name'                     => 'Lecture 7 — Thermodynamics',
            'completion_watch_percent' => 80,
            'no_skip_required'         => 1,
            'default_show_captions'    => 1,
        ]);
        // The asset reference is the load-bearing field for both scenarios.
        $DB->set_field('fastpix', 'fastpix_asset_id', $assetid, ['id' => $activity->id]);
        // Non-default media settings so the round-trip catches a dropped column.
        $DB->set_field('fastpix', 'access_policy', 'public', ['id' => $activity->id]);
        $DB->set_field('fastpix', 'captions_mode', 'auto', ['id' => $activity->id]);
        $DB->set_field('fastpix', 'language_code', 'es', ['id' => $activity->id]);
        $activity = $DB->get_record('fastpix', ['id' => $activity->id], '*', MUST_EXIST);

        $attempts = [];
        foreach ($users as $i => $user) {
            $now = time();
            // Realistic, per-user-distinct values so the round-trip comparison
            // catches a column that silently maps to the wrong row.
            $attemptid = $DB->insert_record('fastpix_attempt', (object) [
                'userid'            => $user->id,
                'activity_id'       => $activity->id,
                'asset_id'          => $assetid ?? 1,
                'session_token'     => str_repeat((string) ($i + 1), 64),
                'session_start_ts'  => $now - (3600 + $i),
                'last_callback_ts'  => $now - (10 + $i),
                'seek_count'        => 2 + $i,
                'watched_intervals' => '[[0,' . (600 + $i) . ']]',
                'current_position'  => 600 + $i,
                'has_completed'     => $i === 0 ? 1 : 0,
                'fraud_count'       => $i,
                'last_fraud_reason' => $i > 0 ? 'implausible_gain' : null,
                'completion_state'  => $i === 0 ? 'complete' : 'in_progress',
                'milestone_25_at'   => $now - 900,
                'milestone_50_at'   => $now - 600,
                'milestone_75_at'   => $i === 0 ? $now - 300 : null,
                'milestone_100_at'  => $i === 0 ? $now - 60 : null,
            ]);
            $attempts[$attemptid] = $DB->get_record('fastpix_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        }

        return [$course, $activity, $attempts];
    }

    /**
     * Back a course up and restore it into a brand-new course. Mirrors the
     * canonical core harness (mod_h5pactivity). MODE_IMPORT writes an unzipped
     * backup dir; restore targets a fresh course.
     *
     * @param stdClass $srccourse The source course to back up.
     * @param bool $userdata Whether to include user data in the backup.
     * @return int the new course id
     */
    private function backup_and_restore(stdClass $srccourse, bool $userdata): int {
        global $USER, $CFG;

        // Turn off file logging so the backup temp dir is removable.
        $CFG->backup_file_logger_level = backup::LOG_NONE;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $srccourse->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value($userdata);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course(
            $srccourse->fullname,
            $srccourse->shortname . '_restored',
            $srccourse->category
        );
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value($userdata);

        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    // Scenario 1 — same-account round-trip.

    public function test_same_account_roundtrip_preserves_activity_attempts_and_asset_reference(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $assetid = 4242;
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        [$course, $activity, $attempts] = $this->setup_activity($assetid, [$student1, $student2]);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $this->assertEquals(2, $DB->count_records('fastpix_attempt', ['activity_id' => $activity->id]));

        $newcourseid = $this->backup_and_restore($course, true);

        // Exactly one restored activity in the new course.
        $this->assertEquals(1, $DB->count_records('fastpix', ['course' => $newcourseid]));
        $restored = $DB->get_record('fastpix', ['course' => $newcourseid], '*', MUST_EXIST);

        // Activity row survives, including the asset REFERENCE preserved verbatim.
        $this->assertEquals($activity->name, $restored->name);
        $this->assertEquals($activity->completion_watch_percent, $restored->completion_watch_percent);
        $this->assertEquals($activity->no_skip_required, $restored->no_skip_required);
        $this->assertEquals($activity->default_show_captions, $restored->default_show_captions);
        $this->assertEquals($activity->access_policy, $restored->access_policy);
        $this->assertEquals($activity->captions_mode, $restored->captions_mode);
        $this->assertEquals($activity->language_code, $restored->language_code);
        $this->assertEquals($assetid, $restored->fastpix_asset_id);

        // All attempt rows survive and are re-parented to the new activity.
        $restoredattempts = $DB->get_records('fastpix_attempt', ['activity_id' => $restored->id]);
        $this->assertCount(2, $restoredattempts);

        // Match each restored attempt back to its source by userid and compare
        // every persisted field. session_token is asserted separately below.
        foreach ($attempts as $orig) {
            $match = null;
            foreach ($restoredattempts as $candidate) {
                if ((int) $candidate->userid === (int) $orig->userid) {
                    $match = $candidate;
                    break;
                }
            }
            $this->assertNotNull($match, 'Restored attempt missing for source user.');

            $this->assertEquals($orig->asset_id, $match->asset_id);
            $this->assertEquals($orig->seek_count, $match->seek_count);
            $this->assertEquals($orig->watched_intervals, $match->watched_intervals);
            $this->assertEquals($orig->current_position, $match->current_position);
            $this->assertEquals($orig->has_completed, $match->has_completed);
            $this->assertEquals($orig->fraud_count, $match->fraud_count);
            $this->assertEquals($orig->last_fraud_reason, $match->last_fraud_reason);
            $this->assertEquals($orig->completion_state, $match->completion_state);
            $this->assertEquals($orig->milestone_25_at, $match->milestone_25_at);
            $this->assertEquals($orig->milestone_50_at, $match->milestone_50_at);
            $this->assertEquals($orig->milestone_75_at, $match->milestone_75_at);
            $this->assertEquals($orig->milestone_100_at, $match->milestone_100_at);
        }
    }

    public function test_same_account_roundtrip_mints_fresh_session_token_not_the_original(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $student = $this->getDataGenerator()->create_user();
        [$course, $activity, $attempts] = $this->setup_activity(99, [$student]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $origtoken = reset($attempts)->session_token;

        $newcourseid = $this->backup_and_restore($course, true);

        $restored = $DB->get_record('fastpix', ['course' => $newcourseid], '*', MUST_EXIST);
        $match = $DB->get_record(
            'fastpix_attempt',
            ['activity_id' => $restored->id, 'userid' => $student->id],
            '*',
            MUST_EXIST
        );

        // S6: the original token is never serialised into the backup; a fresh
        // non-empty placeholder is minted on restore. It must differ and stay
        // within the NOT NULL char(64) column.
        $this->assertNotEmpty($match->session_token);
        $this->assertNotEquals($origtoken, $match->session_token);
        $this->assertLessThanOrEqual(64, strlen($match->session_token));
    }

    public function test_backup_file_does_not_contain_asset_bytes_or_session_token(): void {
        global $DB, $USER, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();

        $student = $this->getDataGenerator()->create_user();
        [$course, , $attempts] = $this->setup_activity(7777, [$student]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $origtoken = reset($attempts)->session_token;

        $CFG->backup_file_logger_level = backup::LOG_NONE;
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // MODE_IMPORT leaves the backup UNZIPPED under the backup temp dir keyed
        // by $backupid — read the serialised activity XML straight out of it.
        $backupdir = make_backup_temp_directory($backupid);
        $xmlfiles = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($backupdir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() === 'fastpix.xml') {
                $xmlfiles[] = $file->getPathname();
            }
        }
        $this->assertNotEmpty($xmlfiles, 'fastpix.xml not produced by backup.');
        $xml = file_get_contents($xmlfiles[0]);

        // The asset REFERENCE is captured (the integer fastpix_asset_id) ...
        $this->assertStringContainsString('<fastpix_asset_id>7777</fastpix_asset_id>', $xml);
        // ... but the session_token (auth material, S6) is NOT serialised.
        $this->assertStringNotContainsString($origtoken, $xml);
        $this->assertStringNotContainsString('session_token', $xml);
        // And there is no <playback>/<asset> byte payload element — we only
        // ever persist the reference, never the media.
        $this->assertStringNotContainsString('<assetbytes', $xml);
        $this->assertStringNotContainsString('<playback_token', $xml);
    }

    // Scenario 2 — cross-FastPix-account restore (ADR-010 / BR1 / PR-22).

    public function test_cross_account_restore_preserves_dangling_asset_reference_without_throwing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // 9000001 deliberately references an asset that exists on no
        // local_fastpix tenant on this Moodle — the cross-account condition.
        $danglingassetid = 9000001;
        $student = $this->getDataGenerator()->create_user();
        [$course, $activity] = $this->setup_activity($danglingassetid, [$student]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // The restore itself must not throw and must not corrupt the run. If
        // the stepslib tried to map / recreate the asset (PR-22), this would
        // fail here.
        $newcourseid = $this->backup_and_restore($course, true);

        $this->assertEquals(1, $DB->count_records('fastpix', ['course' => $newcourseid]));
        $restored = $DB->get_record('fastpix', ['course' => $newcourseid], '*', MUST_EXIST);

        // The dangling reference is preserved verbatim (NOT remapped, NOT
        // nulled, NOT recreated) so view.php can resolve to "Video unavailable".
        $this->assertEquals($danglingassetid, $restored->fastpix_asset_id);

        // No asset row was conjured into local_fastpix to satisfy the reference.
        $this->assertFalse(
            $DB->record_exists('local_fastpix_asset', ['id' => $danglingassetid]),
            'Cross-account restore must NOT recreate the asset (PR-22 / ADR-010).'
        );

        // The attempt row survives intact even though its asset is unresolvable.
        $this->assertEquals(1, $DB->count_records('fastpix_attempt', ['activity_id' => $restored->id]));
    }

    public function test_restore_without_userdata_keeps_activity_and_asset_reference_but_drops_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $assetid = 5150;
        $student = $this->getDataGenerator()->create_user();
        [$course, $activity] = $this->setup_activity($assetid, [$student]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // When userdata = false, activity + asset reference survive, attempts do not.
        $newcourseid = $this->backup_and_restore($course, false);

        $restored = $DB->get_record('fastpix', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals($assetid, $restored->fastpix_asset_id);
        $this->assertEquals(0, $DB->count_records('fastpix_attempt', ['activity_id' => $restored->id]));
    }
}
