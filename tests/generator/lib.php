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
 * PHPUnit/Behat data generator for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * PHPUnit/Behat data generator for mod_fastpix.
 *
 * Hooks `$this->getDataGenerator()->create_module('fastpix', ...)` into
 * lib.php's fastpix_add_instance so test code can stand up activities
 * without touching install.xml directly.
 *
 * @package mod_fastpix
 * @category test
 */
class mod_fastpix_generator extends testing_module_generator {
    /**
     * Create a new FastPix activity instance for tests.
     *
     * @param \stdClass|array|null $record The activity record overrides.
     * @param array|null $options Generator options.
     * @return \stdClass The created activity instance.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'name'                     => 'FastPix Test',
            'intro'                    => '',
            'introformat'              => FORMAT_HTML,
            'fastpix_asset_id'         => null,
            'upload_session_id'        => null,
            'completion_watch_percent' => 90,
            'no_skip_required'         => 0,
            'default_show_captions'    => 0,
            'grademax'                 => 100,
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($record->{$k})) {
                $record->{$k} = $v;
            }
        }
        return parent::create_instance($record, (array)$options);
    }
}
