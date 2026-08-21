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
      | activity   | name            | course | idnumber | stableid      | layout | startercode          | testcases                                                       |
      | saylorcode | Double a number | C1     | scs1     | CS101-U01-E01 | split  | public class Main {} | [{"id":"T1","name":"Prints","expected":"ok","ispublic":true}]   |
      | saylorcode | Tabbed exercise | C1     | scs2     | CS101-U01-E02 | tabs   | public class Main {} | [{"id":"T1","name":"Prints","expected":"ok","ispublic":true}]   |

  Scenario: A student opens the workspace and sees the file they are editing
    Given I am on the "Double a number" "saylorcode activity" page logged in as "student1"
    Then I should see "Main.java"
    And I should see "Console"
    # Read from the DOM rather than as a form field: the editor deliberately
    # sits outside a form, and the driver cannot read a field with no form
    # ancestor.
    And "//textarea[@data-region='editor'][contains(text(),'public class Main')]" "xpath_element" should exist

  Scenario: The workspace offers the student actions
    Given I am on the "Double a number" "saylorcode activity" page logged in as "student1"
    Then "Run" "button" should exist
    And "Check" "button" should exist
    And "Submit" "button" should exist
    And "Reset" "button" should exist

  Scenario: The workspace reports its state before anything has run
    Given I am on the "Double a number" "saylorcode activity" page logged in as "student1"
    Then I should see "Ready"
    And I should see "Output from your program appears here when you run it."

  Scenario: A teacher can see the activity in the course
    Given I am on the "C1" "course" page logged in as "teacher1"
    Then I should see "Double a number"

  Scenario: An exercise reference must be well formed
    Given I log in as "teacher1"
    When I add a "saylorcode" activity to course "Course 1" section "1" and I fill the form with:
      | Name               | Broken reference |
      | Exercise stable ID | NOTANID          |
    Then I should see "This is not a valid exercise ID"

  Scenario: A named test case must say what it expects
    Given I log in as "teacher1"
    When I add a "saylorcode" activity to course "Course 1" section "1" and I fill the form with:
      | Name               | Half written case |
      | Exercise stable ID | CS101-U01-E02     |
      | id_tcname_0        | Doubles four      |
    Then I should see "A named test case needs expected output"

  @accessibility @javascript
  Scenario: The workspace meets accessibility standards
    Given I am on the "Double a number" "saylorcode activity" page logged in as "student1"
    Then the page should meet accessibility standards

  @accessibility @javascript
  Scenario: The workspace is accessible once the student has moved around it
    # The tabs layout deliberately, because that is the only layout with a tab
    # strip: the split layout shows every panel at once and has no Input button
    # to click. Pointing this at the split activity meant the click failed and
    # the audit below never ran at all.
    Given I am on the "Tabbed exercise" "saylorcode activity" page logged in as "student1"
    # Auditing only the page as first delivered checks it in the one state a
    # student barely occupies. This moves to another tab panel first, which is
    # a client side change to what is showing.
    #
    # It is not the state after a run: the results region, console output and
    # test list are built from a runner's response, and CI has no runner. That
    # part of the interface stays unaudited here, and saying so is better than
    # a scenario that implies otherwise.
    When I click on "Input" "button"
    And I wait until the page is ready
    Then the page should meet accessibility standards

  @accessibility @javascript
  Scenario: The activity is accessible to the teacher who has to review it
    Given I am on the "Double a number" "saylorcode activity" page logged in as "teacher1"
    Then the page should meet accessibility standards

  @accessibility @javascript
  Scenario: The authoring form is accessible
    Given I log in as "teacher1"
    When I add a "saylorcode" activity to course "Course 1" section "1"
    Then the page should meet accessibility standards

  @accessibility @javascript
  Scenario: The workspace is accessible at a narrow width
    Given I am on the "Double a number" "saylorcode activity" page logged in as "student1"
    # Reflow, in the sense of WCAG 1.4.10: the layout has to survive a viewport
    # this narrow without a second scrolling direction, and the audit has to
    # still pass once it has.
    When I change the viewport size to "320x800"
    And I wait until the page is ready
    Then the page should meet accessibility standards
