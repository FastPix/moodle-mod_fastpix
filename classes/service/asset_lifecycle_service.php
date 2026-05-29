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

namespace mod_fastpix\service;

/**
 * Asset-lifecycle service for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Asset-lifecycle bridge for the recycle-bin / activity-delete path (Phase E).
 *
 * When a mod_fastpix activity is deleted, its referenced FastPix asset should be
 * soft-deleted in local_fastpix ONLY when no other live activity still references
 * the same asset (a single asset can back many activities across courses — M9).
 *
 * Reference counting is read-only against this plugin's own mdl_fastpix table (A5
 * forbids only local_fastpix_* mutation; reading mdl_fastpix is in-scope). The
 * mutation itself is delegated to the documented public write API
 * \local_fastpix\service\asset_service::soft_delete(int $id) (CC1) — mod_fastpix
 * never writes local_fastpix_* tables directly and makes zero HTTP calls (A2/A5).
 */
class asset_lifecycle_service {
    /** @var self|null Singleton instance. */
    private static $instance = null;

    /**
     * Get the shared service instance.
     *
     * @return self The singleton instance.
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Soft-delete the asset backing the given activity, but only when no other
     * non-deleted activity references the same asset.
     *
     * Safe to call from fastpix_pre_course_module_delete(): at that point the
     * activity's own mdl_fastpix row still exists, so it is excluded from the
     * reference count by primary key.
     *
     * @param int $activityid mdl_fastpix.id of the activity being deleted.
     */
    public function soft_delete_if_unreferenced(int $activityid): void {
        global $DB;

        $activity = $DB->get_record('fastpix', ['id' => $activityid], 'id, fastpix_asset_id');
        if (!$activity || empty($activity->fastpix_asset_id)) {
            // No asset linked yet (upload webhook never arrived) — nothing to do.
            return;
        }

        $assetid = (int)$activity->fastpix_asset_id;

        // Count OTHER activities still pointing at the same asset.
        $others = $DB->count_records_select(
            'fastpix',
            'fastpix_asset_id = :assetid AND id <> :selfid',
            ['assetid' => $assetid, 'selfid' => $activityid]
        );

        if ($others > 0) {
            // Asset is still referenced elsewhere — leave it alone (M9).
            return;
        }

        // Last reference is going away: delegate the soft-delete to local_fastpix.
        \local_fastpix\service\asset_service::soft_delete($assetid);
    }
}
