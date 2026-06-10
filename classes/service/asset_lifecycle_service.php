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
 * Asset-lifecycle bridge to local_fastpix's reference counting.
 *
 * local_fastpix owns the authoritative asset lifecycle: each consumer registers
 * a reference when it links an asset and releases it when it stops using it; the
 * asset is soft-deleted by local_fastpix only when the LAST reference is released
 * (at zero refs). mod_fastpix is one such consumer — this service registers /
 * releases the reference keyed by 'mod_fastpix:<activityid>'.
 *
 * The reference is registered when the asset is actually linked to the activity
 * (the null -> set backfill in playback_service::resolve_for_view), NOT at form
 * save — the FastPix asset id is unknown until the upload webhook readies it.
 *
 * All calls go through the documented asset_service API (CC1) — mod_fastpix never
 * writes local_fastpix_* tables directly and makes zero HTTP calls (A2/A5). Every
 * call is FAIL-SAFE: a missing/throwing service must never break the activity
 * lifecycle (a save or delete always succeeds), so failures are logged and
 * swallowed.
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
     * The reference consumer key for an activity: 'mod_fastpix:<activityid>'.
     *
     * @param int $activityid mdl_fastpix.id.
     * @return string The consumer key.
     */
    private function consumer_key(int $activityid): string {
        return 'mod_fastpix:' . $activityid;
    }

    /**
     * Register this activity's reference to a (now-linked) FastPix asset.
     * Idempotent — add_reference dedups, so calling on every resolve is safe and
     * self-heals activities linked before ref-counting existed.
     *
     * @param int $activityid mdl_fastpix.id.
     * @param string $fastpixid The FastPix asset id (UUID) the activity now uses.
     */
    public function register_reference(int $activityid, string $fastpixid): void {
        if ($fastpixid === '') {
            return;
        }
        try {
            \local_fastpix\service\asset_service::add_reference($fastpixid, $this->consumer_key($activityid));
        } catch (\Throwable $e) {
            // Never let reference bookkeeping break the activity lifecycle.
            debugging('mod_fastpix: add_reference failed for activity ' . $activityid
                . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Release this activity's reference to its linked asset. local_fastpix
     * decides whether the asset is now unreferenced and soft-deletes it. Resolves
     * the FastPix id from the activity's currently-stored fastpix_asset_id, so it
     * must be called BEFORE the activity row (or its asset link) is removed.
     *
     * @param int $activityid mdl_fastpix.id.
     */
    public function release_reference(int $activityid): void {
        global $DB;
        try {
            $activity = $DB->get_record('fastpix', ['id' => $activityid], 'id, fastpix_asset_id');
            if (!$activity || empty($activity->fastpix_asset_id)) {
                // No asset linked (upload webhook never arrived) — nothing to release.
                return;
            }
            $asset = \local_fastpix\service\asset_service::get_by_id((int)$activity->fastpix_asset_id);
            if ($asset === null || empty($asset->fastpix_id)) {
                return;
            }
            \local_fastpix\service\asset_service::release_reference(
                (string)$asset->fastpix_id,
                $this->consumer_key($activityid)
            );
        } catch (\Throwable $e) {
            debugging('mod_fastpix: release_reference failed for activity ' . $activityid
                . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
