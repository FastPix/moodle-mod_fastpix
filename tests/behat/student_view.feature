@mod @mod_fastpix
Feature: View a FastPix Video activity as a student
  In order to watch course videos
  As a student
  I need to open the FastPix Video activity and see its current state

  # NOTE: There is no live FastPix asset or DRM JWT in the Behat environment, so
  # the <fastpix-player> custom element cannot load or play a real video here.
  # These scenarios therefore assert the headless-observable states only:
  #   * the activity opens for an enrolled student,
  #   * with no resolvable asset, the "Video unavailable" error renders.
  # Player playback, watch-progress and fraud callbacks are covered by the
  # PHPUnit suite, not duplicated here.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | course | name        | intro            | idnumber |
      | fastpix  | C1     | Lecture One | Watch this one.  | fp1      |

  Scenario: An enrolled student can open the activity
    When I am on the "fp1" "fastpix activity" page logged in as "student1"
    Then I should see "Lecture One"

  Scenario: A student opening an activity with no ready asset sees the video-unavailable state
    # The generator creates the activity with fastpix_asset_id and
    # upload_session_id both NULL. playback_service::resolve_for_view() returns
    # view_state_error('videounavailable') for that combination (ADR-010),
    # which renders the error_videounavailable lang string.
    When I am on the "fp1" "fastpix activity" page logged in as "student1"
    Then I should see "This video is currently unavailable."
