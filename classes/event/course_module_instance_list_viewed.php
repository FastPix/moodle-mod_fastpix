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

namespace mod_fastpix\event;

/**
 * The course_module_instance_list_viewed event for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Standard "instance list viewed" event, fired from the activity index page.
 *
 * \core\event\course_module_instance_list_viewed is abstract and must be
 * subclassed per plugin (so the event resolves to this component). All
 * behaviour is inherited; no overrides are needed. Pattern follows mod_url.
 */
class course_module_instance_list_viewed extends \core\event\course_module_instance_list_viewed {
}
