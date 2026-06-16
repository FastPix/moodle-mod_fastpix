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

namespace mod_fastpix\dto;

/**
 * DTO describing the "error" player view state.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * User-facing error state. Reason key is one of:
 *   - videounavailable     (asset missing or soft-deleted; ADR-010)
 *   - drm_unsupported      (drm_required but client cannot play DRM)
 *   - capability_lost      (capability revoked mid-session)
 *   - upload_failed        (asset transcode failed/errored — terminal)
 *
 * Only the reason key crosses the template boundary — never asset IDs,
 * statuses, or other internals (rule S9).
 */
class view_state_error {
    /**
     * Constructor.
     *
     * @param string $reasonkey The lang key identifying the error reason.
     * @param string $activityname The activity display name.
     */
    public function __construct(
        /** @var string The lang key identifying the error reason. */
        public readonly string $reasonkey,
        /** @var string The activity display name. */
        public readonly string $activityname,
    ) {
    }
}
