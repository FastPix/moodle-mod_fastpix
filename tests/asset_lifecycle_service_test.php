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
 * mod_fastpix is a lifecycle-managed consumer of local_fastpix's reference
 * counting: it registers a reference ('mod_fastpix:<activityid>') when an asset
 * links to an activity and releases it on delete / asset-swap. local_fastpix
 * soft-deletes the asset only when its LAST reference is released.
 *
 * Inserting into local_fastpix_* tables from a test fixture is explicitly allowed
 * (A5 / CC5 "test fixtures excepted").
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix;

use mod_fastpix\service\asset_lifecycle_service;
use local_fastpix\service\asset_service;

/**
 * @covers \mod_fastpix\service\asset_lifecycle_service
 */
final class asset_lifecycle_service_test extends \advanced_testcase {
    /**
     * Insert a realistic ready local_fastpix_asset fixture and return its PK.
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
    private function create_activity(int $courseid, ?int $assetid, ?int $uploadsessionid = null): \stdClass {
        global $DB;
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $courseid]);
        $DB->set_field('fastpix', 'fastpix_asset_id', $assetid, ['id' => $activity->id]);
        if ($uploadsessionid !== null) {
            $DB->set_field('fastpix', 'upload_session_id', $uploadsessionid, ['id' => $activity->id]);
        }
        return $DB->get_record('fastpix', ['id' => $activity->id], '*', MUST_EXIST);
    }

    /** Fetch an asset's FastPix UUID (the key the ref API uses). */
    private function fastpix_id_of(int $assetid): string {
        global $DB;
        return (string)$DB->get_field('local_fastpix_asset', 'fastpix_id', ['id' => $assetid], MUST_EXIST);
    }

    public function test_register_reference_is_idempotent(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $assetid = $this->create_asset_fixture();
        $activity = $this->create_activity($course->id, $assetid);
        $uuid = $this->fastpix_id_of($assetid);

        $svc = asset_lifecycle_service::instance();
        $svc->register_reference((int)$activity->id, $uuid);
        $this->assertSame(1, asset_service::reference_count($uuid));

        // Registering again must NOT stack a duplicate reference.
        $svc->register_reference((int)$activity->id, $uuid);
        $this->assertSame(1, asset_service::reference_count($uuid));
    }

    public function test_delete_instance_releases_the_reference(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/fastpix/lib.php');
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assetid = $this->create_asset_fixture();
        $activity = $this->create_activity($course->id, $assetid);
        $uuid = $this->fastpix_id_of($assetid);

        asset_lifecycle_service::instance()->register_reference((int)$activity->id, $uuid);
        $this->assertSame(1, asset_service::reference_count($uuid));

        $this->assertTrue(fastpix_delete_instance((int)$activity->id));
        $this->assertSame(0, asset_service::reference_count($uuid));
    }

    public function test_shared_asset_survives_until_last_reference_released(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/fastpix/lib.php');
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assetid = $this->create_asset_fixture();
        $uuid = $this->fastpix_id_of($assetid);
        $a = $this->create_activity($course->id, $assetid);
        $b = $this->create_activity($course->id, $assetid);

        $svc = asset_lifecycle_service::instance();
        $svc->register_reference((int)$a->id, $uuid);
        $svc->register_reference((int)$b->id, $uuid);
        $this->assertSame(2, asset_service::reference_count($uuid));

        // Delete one — asset keeps a reference, must NOT be soft-deleted (M9).
        fastpix_delete_instance((int)$a->id);
        $this->assertSame(1, asset_service::reference_count($uuid));
        $this->assertNull($DB->get_field('local_fastpix_asset', 'deleted_at', ['id' => $assetid]));

        // Delete the last — reference hits zero and local_fastpix soft-deletes it.
        fastpix_delete_instance((int)$b->id);
        $this->assertSame(0, asset_service::reference_count($uuid));
        $this->assertNotNull($DB->get_field('local_fastpix_asset', 'deleted_at', ['id' => $assetid]));
    }

    public function test_update_to_a_different_asset_releases_the_old_reference(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/fastpix/lib.php');
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assetid = $this->create_asset_fixture();
        $uuid = $this->fastpix_id_of($assetid);
        // Activity currently uses upload session 10, linked to the old asset.
        $activity = $this->create_activity($course->id, $assetid, 10);
        asset_lifecycle_service::instance()->register_reference((int)$activity->id, $uuid);
        $this->assertSame(1, asset_service::reference_count($uuid));

        // Edit the activity to point at a DIFFERENT upload session (asset swap).
        $data = (object)[
            'instance'          => (int)$activity->id,
            'course'            => (int)$course->id,
            'name'              => 'Swapped video',
            'intro'             => '',
            'introformat'       => FORMAT_HTML,
            'upload_session_id' => 20,
        ];
        fastpix_update_instance($data);

        // The old asset's reference must be released (and, being the last, the
        // asset soft-deleted). The new asset registers later, at first view.
        $this->assertSame(0, asset_service::reference_count($uuid));
    }

    public function test_delete_is_failsafe_when_asset_missing(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/fastpix/lib.php');
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        // Point the activity at an asset id that does not exist — release must
        // no-op gracefully and the delete must still succeed.
        $activity = $this->create_activity($course->id, 999999);

        $this->assertTrue(fastpix_delete_instance((int)$activity->id));
        $this->assertFalse($DB->record_exists('fastpix', ['id' => $activity->id]));
    }
}
