@mod @mod_fastpix
Feature: Teacher watch reports for FastPix video
  In order to understand how students engage with a video
  As a teacher
  I need to see per-video and per-user watch reports, gated by capability

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name       | course | idnumber |
      | fastpix  | Test video | C1     | fpx1     |

  @javascript
  Scenario: A teacher can open the watch report and sees the summary
    When I am on the "Test video" "fastpix activity" page logged in as teacher1
    And I navigate to "Watch report" in current page administration
    Then I should see "Unique viewers"
    And I should see "Completion rate"
    And I should see "No students have watched this video yet."

  Scenario: A student cannot reach the watch report
    When I am on the "Test video" "fastpix activity" page logged in as student1
    Then I should not see "Watch report"

  @javascript
  Scenario: The watch report offers a CSV download
    When I am on the "Test video" "fastpix activity" page logged in as teacher1
    And I navigate to "Watch report" in current page administration
    Then "Download (CSV)" "link" should exist
