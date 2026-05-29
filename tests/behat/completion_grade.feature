@mod @mod_fastpix
Feature: Completion rule and grade item for a FastPix Video activity
  In order to track that students have watched a video
  As a teacher
  I need the watched-percentage completion rule to persist and a grade item to be created

  # The completion rule (completionwatchedpercent) is satisfied at runtime by
  # accumulated watch progress, which needs a live asset and is PHPUnit-covered.
  # Behat verifies headlessly that the rule and its threshold persist through the
  # edit form, and that creating a graded activity produces a grade item in the
  # gradebook.

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

  Scenario: The watched-percentage completion rule and its threshold persist on the edit form
    # completion = 2 is COMPLETION_TRACKING_AUTOMATIC; the two custom-completion
    # columns set our single rule and its threshold. data_preprocessing()
    # repopulates completionwatchedpercent from completion_watch_percent when the
    # form is re-opened.
    Given the following "activities" exist:
      | activity | course | name             | idnumber | completion | completionwatchedpercentenabled | completionwatchedpercent |
      | fastpix  | C1     | Graded Lecture   | fpc1     | 2          | 1                               | 80                       |
    When I am on the "fpc1" "fastpix activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then I should see "Students must watch the video"
    And I should see "Watched percentage"
    And the field "completionwatchedpercent" matches value "80"

  Scenario: A graded FastPix Video activity creates a grade item in the gradebook
    # grade = 100 makes add_moduleinfo() call fastpix_grade_item_update(), which
    # registers a grade item named after the activity (itemname = activity name).
    Given the following "activities" exist:
      | activity | course | name           | idnumber | grade |
      | fastpix  | C1     | Assessed Video | fpg1     | 100   |
    When I am on the "Course 1" "grades > gradebook setup" page logged in as "teacher1"
    Then I should see "Assessed Video"
