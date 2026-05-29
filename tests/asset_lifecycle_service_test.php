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
 * Tests for \mod_fastpix\service\asset_lifecycle_service.
 *
 * Covers all three branches of soft_delete_if_unreferenced() (Phase E,
 * recycle-bin / activity-delete path; PR-11):
 *   ① activity has no fastpix_asset_id          → no-op
 *   ② asset still referenced by ANOTHER activity → NOT soft-deleted (M9)
 *   ③ activity is the LAST reference            → delegated soft-delete fires
 *
 * Branch ③ asserts the delegate \local_fastpix\service\asset_service::soft_delete()
 * stamps deleted_at on the real local_fastpix_asset fixture row. Inserting into
 * local_fastpix_* tables from a test fixture is explicitly allowed (A5 / CC5
 * "test fixtures excepted").
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix;

use mod_fastpix\service\asset_lifecycle_service;

/**
 * Tests for \mod_fastpix\service\asset_lifecycle_service.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix\service\asset_lifecycle_service
 */
final class asset_lifecycle_service_test extends \advanced_testcase {
    /**
     * Insert a realistic local_fastpix_asset fixture row and return its PK.
     * Realistic values: a 1-hour lecture video, ready, captioned (S4 / fixture
     * guidance — not duration=0).
     */
    private function create_asset_fixture(): int {
        global $DB;

        $now = time();
        return (int)$DB->insert_record('local_fastpix_asset', (object)[
            'fastpix_id'           => 'fpx-' . substr(sha1((string)$now . rand()), 0, 28),
            'playback_id'          => 'pb-' . substr(sha1((string)$now . rand()), 0, 28),
            'owner_userid'         => 0,
            'title'                => 'Intro to Photosynthesis (Lecture 4)',
            'duration'             => 3600.000,
            'status'               => 'ready',
            'access_policy'        => 'private',
            'drm_required'         => 0,
            'no_skip_required'     => 0,
            'has_captions'         => 1,
            'deleted_at'           => null,
            'gdpr_delete_attempts' => 0,
            'timecreated'          => $now,
            'timemodified'         => $now,
        ]);
    }

    /**
     * Stand up a mod_fastpix activity, optionally bound to an asset PK.
     */
    private function create_activity(int $courseid, ?int $assetid): \stdClass {
        global $DB;

        $activity = $this->getDataGenerator()->create_module('fastpix', [
            'course' => $courseid,
        ]);
        $DB->set_field('fastpix', 'fastpix_asset_id', $assetid, ['id' => $activity->id]);
        return $DB->get_record('fastpix', ['id' => $activity->id], '*', MUST_EXIST);
    }

    /**
     * ① Activity has no asset linked (fastpix_asset_id is null) — the upload
     * webhook never arrived. The service must no-op: no exception, nothing
     * soft-deleted (there is nothing to soft-delete).
     */
    public function test_no_op_when_activity_has_no_asset(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->create_activity($course->id, null);

        // No asset rows exist at all — assert the service touches nothing.
        $this->assertEquals(0, $DB->count_records('local_fastpix_asset'));

        asset_lifecycle_service::instance()->soft_delete_if_unreferenced((int)$activity->id);

        $this->assertEquals(0, $DB->count_records('local_fastpix_asset'));
    }

    /**
     * ② The asset is still referenced by ANOTHER live mod_fastpix activity.
     * Deleting one activity must NOT soft-delete the shared asset (M9 — one
     * asset can back many activities across courses).
     */
    public function test_asset_not_soft_deleted_while_another_activity_references_it(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $assetid = $this->create_asset_fixture();

        $going = $this->create_activity($course->id, $assetid);   // The one being deleted.
        $staying = $this->create_activity($course->id, $assetid); // Keeps the reference alive.

        // Simulate fastpix_pre_course_module_delete() for $going while $staying
        // still references the same asset PK.
        asset_lifecycle_service::instance()->soft_delete_if_unreferenced((int)$going->id);

        $asset = $DB->get_record('local_fastpix_asset', ['id' => $assetid], '*', MUST_EXIST);
        $this->assertNull(
            $asset->deleted_at,
            'Shared asset must stay live while activity ' . $staying->id . ' still references it.'
        );
    }

    /**
     * ③ The activity being deleted is the LAST reference to the asset. The
     * service must delegate to \local_fastpix\service\asset_service::soft_delete(),
     * which stamps deleted_at on the local_fastpix_asset row.
     */
    public function test_asset_soft_deleted_when_last_reference_removed(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $assetid = $this->create_asset_fixture();
        $activity = $this->create_activity($course->id, $assetid);

        // Pre-condition: nobody has soft-deleted it yet.
        $before = $DB->get_record('local_fastpix_asset', ['id' => $assetid], '*', MUST_EXIST);
        $this->assertNull($before->deleted_at);

        asset_lifecycle_service::instance()->soft_delete_if_unreferenced((int)$activity->id);

        $after = $DB->get_record('local_fastpix_asset', ['id' => $assetid], '*', MUST_EXIST);
        $this->assertNotNull(
            $after->deleted_at,
            'Last reference removed — asset_service::soft_delete() must have stamped deleted_at.'
        );
        $this->assertGreaterThan(0, (int)$after->deleted_at);
    }
}
