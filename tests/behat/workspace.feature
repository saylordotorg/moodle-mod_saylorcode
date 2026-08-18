@mod @mod_saylorcode
Feature: Working in a Saylor Code Studio activity
  In order to practise programming inside my course
  As a student
  I need a workspace that opens on my own code and does not lose it

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity   | name            | course | idnumber | stableid      | startercode          |
      | saylorcode | Double a number | C1     | scs1     | CS101-U01-E01 | public class Main {} |

  Scenario: A student opens the workspace and sees the starter code
    Given I am on the "Double a number" "saylorcode activity" page logged in as "student1"
    Then I should see "Instructions"
    And I should see "Console"
    And the field with xpath "//textarea[@data-region='editor']" matches value "public class Main {}"

  Scenario: The workspace offers the student actions
    Given I am on the "Double a number" "saylorcode activity" page logged in as "student1"
    Then "Run" "button" should exist
    And "Check" "button" should exist
    And "Submit" "button" should exist
    And "Reset" "button" should exist

  Scenario: A teacher can see the activity in the course
    Given I am on "Course 1" course homepage logged in as "teacher1"
    Then I should see "Double a number"

  Scenario: An exercise reference must be well formed
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I add a "Saylor Code Studio" activity to course "Course 1" section "1" and I fill the form with:
      | Name               | Broken reference |
      | Exercise stable ID | NOTANID          |
    Then I should see "This is not a valid exercise ID"

  Scenario: Test cases must be valid JSON
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I add a "Saylor Code Studio" activity to course "Course 1" section "1" and I fill the form with:
      | Name               | Broken tests    |
      | Exercise stable ID | CS101-U01-E02   |
      | Test cases         | not json at all |
    Then I should see "Test cases must be a JSON array"

  @javascript
  Scenario: The workspace meets accessibility standards
    Given I am on the "Double a number" "saylorcode activity" page logged in as "student1"
    Then the page should meet accessibility standards
