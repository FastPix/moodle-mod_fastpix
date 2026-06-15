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
 * Tests for the mod_fastpix privacy provider.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix\privacy;

use core_privacy\local\metadata\collection;

/**
 * Tests for the mod_fastpix privacy provider metadata.
 *
 * @covers \mod_fastpix\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    /**
     * get_metadata() must declare both the per-user attempt table and the
     * external FastPix data flow (video playback streams to FastPix from the
     * learner's browser), and every metadata key must resolve to a real string.
     */
    public function test_metadata_declares_attempt_table_and_external_fastpix_link(): void {
        $collection = provider::get_metadata(new collection('mod_fastpix'));
        $items = $collection->get_collection();

        $names = array_map(static fn($item) => $item->get_name(), $items);
        $this->assertContains('fastpix_attempt', $names, 'Attempt DB table must be declared.');
        $this->assertContains('fastpix', $names, 'External FastPix data flow must be declared.');

        // Every summary + field lang key on every metadata item must resolve.
        $sm = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue(
                $sm->string_exists($item->get_summary(), 'mod_fastpix'),
                "Missing summary string: {$item->get_summary()}"
            );
            foreach ($item->get_privacy_fields() as $key) {
                $this->assertTrue(
                    $sm->string_exists($key, 'mod_fastpix'),
                    "Missing field string: {$key}"
                );
            }
        }
    }
}
