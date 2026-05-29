@mod @mod_fastpix
Feature: Add a FastPix Video activity to a course
  In order to deliver video to students
  As a teacher
  I need to add a FastPix Video activity and have it appear on the course page

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

  @javascript
  Scenario: The FastPix Video add form renders with the expected sections and fields
    # i_add_to_course_section navigates straight to modedit.php?add=fastpix,
    # which is the add form. We assert the form chrome rather than saving,
    # because saving the real form requires an upload session minted by the
    # gateway, which is not available headlessly (see feature header note).
    Given I am logged in as "teacher1"
    When I add a "fastpix" activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then I should see "Activity name"
    And I should see "Video source"
    And I should see "Playback options"
    And I should see "Disable seeking"
    And the field "Activity name" matches value ""

  Scenario: A FastPix Video activity created via the generator appears on the course page and can be opened
    Given the following "activities" exist:
      | activity | course | name           | intro              | idnumber |
      | fastpix  | C1     | Welcome Lecture | The intro lecture. | fp1      |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    Then I should see "Welcome Lecture"
    When I am on the "fp1" "fastpix activity" page logged in as "teacher1"
    Then I should see "Welcome Lecture"
