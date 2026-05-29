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
 * Version metadata for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'mod_fastpix';
$plugin->version      = 2026052901;
$plugin->requires     = 2024100100;
$plugin->maturity     = MATURITY_STABLE;
$plugin->release      = '1.0.0';
$plugin->dependencies = [
    // The local_fastpix v1.0.0 production release (>= 2026052100) consolidates
    // the verified-against-FastPix DRM playback chain: both playbacktoken
    // and drmtoken use aud="drm:<playback_id>" for DRM assets, matching
    // FastPix's reference player. End-to-end manifest fetch verified at
    // 200 for public / private / drm policies.
    'local_fastpix' => 2026052100,
];
