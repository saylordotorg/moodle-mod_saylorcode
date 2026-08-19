@mod @mod_saylorcode
Feature: Finding exercises in the catalogue
  In order to reuse what already exists instead of writing it twice
  As a teacher
  I need one list of every exercise on the site

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
      | Course 2 | C2        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity   | name            | course | idnumber | stableid      | referencesolution    | testcases                                               |
      | saylorcode | Double a number | C1     | sc1      | CS101-U01-E01 | public class Main {} | [{"name":"Two","expected":"4","ispublic":1,"weight":1}] |
      | saylorcode | Greet the user  | C2     | sc2      | CS102-U01-E01 |                      |                                                         |

  Scenario: The catalogue lists exercises from every course
    Given I log in as "admin"
    When I visit "/mod/saylorcode/library.php"
    Then I should see "Double a number"
    And I should see "Greet the user"
    And I should see "CS101-U01-E01"

  Scenario: An exercise missing its tests is marked as unfinished
    Given I log in as "admin"
    When I visit "/mod/saylorcode/library.php"
    Then "Ready" "text" should exist in the "Double a number" "table_row"
    And "No tests" "text" should exist in the "Greet the user" "table_row"

  Scenario: Searching narrows the catalogue to one exercise
    Given I log in as "admin"
    And I visit "/mod/saylorcode/library.php"
    When I set the field "Search" to "Double"
    And I press "Filter"
    Then I should see "Double a number"
    And I should not see "Greet the user"

  Scenario: Previewing shows the workspace with its controls switched off
    Given I am on the "Double a number" "saylorcode activity" page logged in as "teacher1"
    When I follow "Preview as a student"
    Then I should see "This is a preview of what a student sees"
    And the "Run" "button" should be disabled
