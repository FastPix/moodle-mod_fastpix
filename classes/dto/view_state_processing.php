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
 * DTO describing the "processing" player view state.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Asset is not yet ready (uploading or transcoding). The processing template
 * polls local_fastpix_get_upload_status; on status='ready' the page reloads
 * and resolve_for_view returns view_state_player.
 */
class view_state_processing {
    /**
     * Constructor.
     *
     * @param int $activityid The fastpix activity instance id.
     * @param int $cmid The course module id.
     * @param int|null $uploadsessionid The pending upload session id, if any.
     * @param string $activityname The activity display name.
     */
    public function __construct(
        /** @var int The fastpix activity instance id. */
        public readonly int $activityid,
        /** @var int The course module id. */
        public readonly int $cmid,
        /** @var int|null The pending upload session id, if any. */
        public readonly ?int $uploadsessionid,
        /** @var string The activity display name. */
        public readonly string $activityname,
    ) {
    }
}
