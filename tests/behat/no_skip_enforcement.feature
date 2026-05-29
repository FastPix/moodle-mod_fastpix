@mod @mod_fastpix
Feature: Disable seeking (no-skip) setting persists on a FastPix Video activity
  In order to stop students skipping ahead
  As a teacher
  I need the "Disable seeking" setting to be saved and shown back on the edit form

  # The no-skip toggle is enforced at playback time by the player + the
  # seek_on_noskip fraud check (PHPUnit-covered). Behat can only verify, without
  # a live asset, that the SETTING persists and round-trips through the edit
  # form. The "Disable seeking" control is a raw HTML checkbox
  # (id="id_no_skip_required", name="no_skip_required") rendered for the styled
  # card look, not an mform-labelled element, so it is asserted by xpath.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: Disable seeking enabled is shown as checked when the activity is re-opened
    Given the following "activities" exist:
      | activity | course | name           | no_skip_required | idnumber |
      | fastpix  | C1     | No-skip Lecture | 1                | fpns1    |
    When I am on the "fpns1" "fastpix activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then the field with xpath "//input[@id='id_no_skip_required']" matches value "1"

  Scenario: Disable seeking disabled is shown as unchecked when the activity is re-opened
    Given the following "activities" exist:
      | activity | course | name              | no_skip_required | idnumber |
      | fastpix  | C1     | Free-seek Lecture | 0                | fpns0    |
    When I am on the "fpns0" "fastpix activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then the field with xpath "//input[@id='id_no_skip_required']" does not match value "1"
