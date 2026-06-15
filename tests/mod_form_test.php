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
 * Tests for mod_form.php — Phase B server-side validation paths.
 *
 * Covers M10 (server-side validation) and the documented rejection paths
 * (error_uploadrequired / error_urlrequired / error_urlnotvalidated /
 * error_thresholdrange).
 *
 * Drives the mod_fastpix-specific rules via validate_fastpix_rules(),
 * the testable extract of validation(). parent::validation chains into
 * grade_item / $COURSE state that isn't easily stubbed in unit context;
 * Phase D will add full-stack integration tests.
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/fastpix/mod_form.php');
require_once($CFG->dirroot . '/mod/fastpix/lib.php');

/**
 * Tests for the listed class.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /**
     * Reflection-stamped form instance — bypasses moodleform_mod's
     * constructor (which needs full course/section context).
     *
     * @param int $instanceid The activity instance id to stamp on the form.
     * @return \mod_fastpix_mod_form The form instance.
     */
    private function make_form(int $instanceid = 0): \mod_fastpix_mod_form {
        $ref = new \ReflectionClass(\mod_fastpix_mod_form::class);
        $form = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue($form, $instanceid);
        return $form;
    }

    public function test_validate_rejects_empty_upload(): void {
        $this->resetAfterTest();
        $errors = $this->make_form()->validate_fastpix_rules(
            ['source_type' => 'upload', 'upload_session_id' => '', 'name' => 'x']
        );
        $this->assertArrayHasKey('name', $errors);
    }

    public function test_validate_rejects_empty_url_in_urlpull_mode(): void {
        $this->resetAfterTest();
        $_POST['source_url'] = '';
        $errors = $this->make_form()->validate_fastpix_rules(
            ['source_type' => 'urlpull', 'upload_session_id' => '', 'name' => 'x']
        );
        $this->assertArrayHasKey('name', $errors);
        unset($_POST['source_url']);
    }

    public function test_validate_rejects_url_with_no_session_id(): void {
        $this->resetAfterTest();
        $_POST['source_url'] = 'https://example.com/video.mp4';
        $errors = $this->make_form()->validate_fastpix_rules(
            ['source_type' => 'urlpull', 'upload_session_id' => '', 'name' => 'x']
        );
        $this->assertArrayHasKey('name', $errors);
        unset($_POST['source_url']);
    }

    public function test_validate_rejects_threshold_out_of_range(): void {
        $this->resetAfterTest();
        $form = $this->make_form();

        $errors = $form->validate_fastpix_rules([
            'source_type'                       => 'upload',
            'upload_session_id'                 => 1,
            'name'                              => 'x',
            'completionwatchedpercentenabled'   => 1,
            'completionwatchedpercent'          => 0,
        ]);
        $this->assertArrayHasKey('completionwatchedpercentgroup', $errors);

        $errors = $form->validate_fastpix_rules([
            'source_type'                       => 'upload',
            'upload_session_id'                 => 1,
            'name'                              => 'x',
            'completionwatchedpercentenabled'   => 1,
            'completionwatchedpercent'          => 101,
        ]);
        $this->assertArrayHasKey('completionwatchedpercentgroup', $errors);
    }

    public function test_validate_accepts_valid_upload_submission(): void {
        $this->resetAfterTest();
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type'                     => 'upload',
            'upload_session_id'               => 1,
            'name'                            => 'x',
            'completionwatchedpercentenabled' => 1,
            'completionwatchedpercent'        => 90,
        ]);
        $this->assertEmpty($errors, 'no errors expected on a clean submission');
    }

    public function test_validate_rejects_drm_when_not_configured(): void {
        $this->resetAfterTest();
        set_config('feature_drm_enabled', 0, 'local_fastpix');
        // The access_policy control is custom HTML; the DRM error surfaces on the
        // visible 'name' element (it has no mform row of its own).
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type' => 'upload', 'upload_session_id' => 1, 'name' => 'x',
            'access_policy' => 'drm',
        ]);
        $this->assertArrayHasKey('name', $errors);
        $this->assertEquals(get_string('error_drmnotconfigured', 'mod_fastpix'), $errors['name']);
    }

    public function test_validate_accepts_drm_when_configured(): void {
        $this->resetAfterTest();
        // The drm_enabled() check double-gates on the flag AND a non-empty config id (W12).
        set_config('feature_drm_enabled', 1, 'local_fastpix');
        set_config('drm_configuration_id', 'cfg_test_123', 'local_fastpix');
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type' => 'upload', 'upload_session_id' => 1, 'name' => 'x',
            'access_policy' => 'drm',
        ]);
        $this->assertArrayNotHasKey('name', $errors);
    }

    public function test_validate_accepts_public_policy_without_drm_config(): void {
        $this->resetAfterTest();
        set_config('feature_drm_enabled', 0, 'local_fastpix');
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type' => 'upload', 'upload_session_id' => 1, 'name' => 'x',
            'access_policy' => 'public',
        ]);
        $this->assertArrayNotHasKey('name', $errors);
    }

    public function test_validate_requires_language_for_auto_captions(): void {
        $this->resetAfterTest();
        // Captions controls are custom HTML; their errors surface on 'name'.
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type' => 'upload', 'upload_session_id' => 1, 'name' => 'x',
            'captionsenabled' => 1, 'captionsmode' => 'auto', 'languagecode' => '',
        ]);
        $this->assertArrayHasKey('name', $errors);
        $this->assertEquals(get_string('error_languagerequired', 'mod_fastpix'), $errors['name']);
    }

    public function test_validate_rejects_unknown_language_for_auto_captions(): void {
        $this->resetAfterTest();
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type' => 'upload', 'upload_session_id' => 1, 'name' => 'x',
            'captionsenabled' => 1, 'captionsmode' => 'auto', 'languagecode' => 'zz',
        ]);
        $this->assertArrayHasKey('name', $errors);
    }

    public function test_validate_accepts_auto_captions_with_language(): void {
        $this->resetAfterTest();
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type' => 'upload', 'upload_session_id' => 1, 'name' => 'x',
            'captionsenabled' => 1, 'captionsmode' => 'auto', 'languagecode' => 'en',
        ]);
        $this->assertArrayNotHasKey('name', $errors);
    }

    public function test_validate_requires_vtt_file_when_vtt_mode(): void {
        $this->resetAfterTest();
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type' => 'upload', 'upload_session_id' => 1, 'name' => 'x',
            'captionsenabled' => 1, 'captionsmode' => 'vtt', 'captionsfile' => 0,
        ]);
        $this->assertArrayHasKey('name', $errors);
        $this->assertEquals(get_string('error_vttrequired', 'mod_fastpix'), $errors['name']);
    }

    public function test_validate_skips_caption_rules_when_toggle_off(): void {
        $this->resetAfterTest();
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type' => 'upload', 'upload_session_id' => 1, 'name' => 'x',
            'captionsenabled' => 0,
        ]);
        $this->assertArrayNotHasKey('name', $errors);
    }

    public function test_add_instance_persists_media_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $_POST['no_skip_required'] = '0';
        $_POST['default_show_captions'] = '0';
        $data = $this->add_instance_data($course->id);
        $data->access_policy   = 'public';
        $data->captionsenabled = 1;
        $data->captionsmode    = 'auto';
        $data->languagecode    = 'fr';
        $id = fastpix_add_instance($data);
        unset($_POST['no_skip_required'], $_POST['default_show_captions']);

        $row = $DB->get_record('fastpix', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals('public', $row->access_policy);
        $this->assertEquals('auto', $row->captions_mode);
        $this->assertEquals('fr', $row->language_code);
    }

    public function test_add_instance_defaults_media_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $_POST['no_skip_required'] = '0';
        $_POST['default_show_captions'] = '0';
        $id = fastpix_add_instance($this->add_instance_data($course->id));
        unset($_POST['no_skip_required'], $_POST['default_show_captions']);

        $row = $DB->get_record('fastpix', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals('private', $row->access_policy);
        $this->assertEquals('none', $row->captions_mode);
        $this->assertNull($row->language_code);
    }

    /**
     * Build the $data object Moodle hands to fastpix_add_instance. The two
     * player-behaviour flags are deliberately NOT set on $data — they come
     * from POST, which is the bug this test guards.
     *
     * @param int $courseid The course id.
     * @return \stdClass The form data object for fastpix_add_instance.
     */
    private function add_instance_data(int $courseid): \stdClass {
        return (object) [
            'course' => $courseid,
            'name'   => 'Round-trip activity',
            'intro'  => '',
            'introformat' => FORMAT_HTML,
            'upload_session_id' => 0,
        ];
    }

    public function test_add_instance_persists_player_flags_from_post(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Both ticked: checkbox value=1 reaches POST.
        $_POST['no_skip_required'] = '1';
        $_POST['default_show_captions'] = '1';
        $id = fastpix_add_instance($this->add_instance_data($course->id));
        $row = $DB->get_record('fastpix', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(1, (int)$row->no_skip_required);
        $this->assertEquals(1, (int)$row->default_show_captions);
        unset($_POST['no_skip_required'], $_POST['default_show_captions']);
    }

    public function test_add_instance_persists_zero_when_unchecked(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Unticked: only the preceding hidden value=0 reaches POST.
        $_POST['no_skip_required'] = '0';
        $_POST['default_show_captions'] = '0';
        $id = fastpix_add_instance($this->add_instance_data($course->id));
        $row = $DB->get_record('fastpix', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(0, (int)$row->no_skip_required);
        $this->assertEquals(0, (int)$row->default_show_captions);
        unset($_POST['no_skip_required'], $_POST['default_show_captions']);
    }

    public function test_update_instance_round_trips_player_flags_from_post(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Create with both off.
        $_POST['no_skip_required'] = '0';
        $_POST['default_show_captions'] = '0';
        $id = fastpix_add_instance($this->add_instance_data($course->id));
        unset($_POST['no_skip_required'], $_POST['default_show_captions']);

        $row = $DB->get_record('fastpix', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(0, (int)$row->no_skip_required);
        $this->assertEquals(0, (int)$row->default_show_captions);

        // Edit: teacher ticks both. Update reads from POST.
        $_POST['no_skip_required'] = '1';
        $_POST['default_show_captions'] = '1';
        $update = $this->add_instance_data($course->id);
        $update->instance = $id;
        $update->name = 'Edited activity';
        fastpix_update_instance($update);
        unset($_POST['no_skip_required'], $_POST['default_show_captions']);

        $row = $DB->get_record('fastpix', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(1, (int)$row->no_skip_required);
        $this->assertEquals(1, (int)$row->default_show_captions);

        // Edit again: teacher unticks both — value must drop back to 0.
        $_POST['no_skip_required'] = '0';
        $_POST['default_show_captions'] = '0';
        $update2 = $this->add_instance_data($course->id);
        $update2->instance = $id;
        $update2->name = 'Edited activity';
        fastpix_update_instance($update2);
        unset($_POST['no_skip_required'], $_POST['default_show_captions']);

        $row = $DB->get_record('fastpix', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(0, (int)$row->no_skip_required);
        $this->assertEquals(0, (int)$row->default_show_captions);
    }
}
