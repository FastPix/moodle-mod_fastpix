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
 * Tests for the mod_fastpix standard module events.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix\event;

/**
 * Tests for the mod_fastpix standard module events. Mirrors mod_url.
 *
 * @covers \mod_fastpix\event\course_module_viewed
 * @covers \mod_fastpix\event\course_module_instance_list_viewed
 */
final class events_test extends \advanced_testcase {
    /**
     * The course_module_viewed event (view.php) fires with the right context,
     * objectid and url.
     */
    public function test_course_module_viewed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $context = \context_module::instance($activity->cmid);

        $event = \mod_fastpix\event\course_module_viewed::create([
            'objectid' => $activity->id,
            'context'  => $context,
        ]);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('fastpix', $activity);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $fired = reset($events);
        $this->assertInstanceOf(\mod_fastpix\event\course_module_viewed::class, $fired);
        $this->assertEquals($context, $fired->get_context());
        $this->assertEquals((int)$activity->id, $fired->objectid);
        $this->assertEquals('r', $fired->crud);
        $this->assertEquals(\core\event\base::LEVEL_PARTICIPATING, $fired->edulevel);
        $expectedurl = new \moodle_url('/mod/fastpix/view.php', ['id' => $activity->cmid]);
        $this->assertEquals($expectedurl, $fired->get_url());
    }

    /**
     * The course_module_instance_list_viewed event (index.php) fires against
     * the course context.
     */
    public function test_course_module_instance_list_viewed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $event = \mod_fastpix\event\course_module_instance_list_viewed::create(['context' => $context]);
        $event->add_record_snapshot('course', $course);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $fired = reset($events);
        $this->assertInstanceOf(\mod_fastpix\event\course_module_instance_list_viewed::class, $fired);
        $this->assertEquals($context, $fired->get_context());
    }
}
