@local @local_resourcestats @local_resourcestats_teacher @javascript
Feature: Teacher-facing resource statistics
  As a teacher
  I want to see access statistics for my course's activities
  So that I can monitor student engagement

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
      | student2 | Student   | Two      |
    And the following "courses" exist:
      | fullname | shortname | format | enablecompletion |
      | Course 1 | C1        | topics | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name             | completion | completionview |
      | page     | C1     | Week 1 Slides    | 0          | 0              |
      | page     | C1     | Tracked handout  | 1          | 0              |
    And "student1" has 3 Resource Stats accesses on "Week 1 Slides" in course "C1"
    And "student2" has 1 Resource Stats accesses on "Week 1 Slides" in course "C1"

  Scenario: Teacher enables display badges and sees them on the course page
    When I log in as "teacher1"
    And I open the course statistics page for course "C1"
    And I click on "Configure display" "link"
    And I set the field "Total accesses" to "1"
    And I set the field "Students who accessed" to "1"
    And I press "Save changes"
    And I am on "Course 1" course homepage
    Then I should see "4" in the "[data-activityname='Week 1 Slides']" "css_element"
    And I should see "2" in the "[data-activityname='Week 1 Slides']" "css_element"

  Scenario: Teacher opens the course statistics overview
    When I log in as "teacher1"
    And I open the course statistics page for course "C1"
    Then I should see "Week 1 Slides"
    And I should see "2 enrolled student(s)"
    And I should see "100%"

  Scenario: Completion badge counts only students who completed, and only where completion is tracked
    Given I am on the "Tracked handout" "page activity" page logged in as student1
    And I press "Mark as done"
    And I log out
    When I log in as "teacher1"
    And I open the course statistics page for course "C1"
    And I click on "Configure display" "link"
    And I set the field "Students who completed" to "1"
    And I press "Save changes"
    And I am on "Course 1" course homepage
    Then I should see "1/2" in the "[data-activityname='Tracked handout']" "css_element"
    But I should not see "1/2" in the "[data-activityname='Week 1 Slides']" "css_element"

  Scenario: Course statistics page reports completion per activity
    Given I am on the "Tracked handout" "page activity" page logged in as student1
    And I press "Mark as done"
    And I log out
    When I log in as "teacher1"
    And I open the course statistics page for course "C1"
    Then I should see "Completed"
    And I should see "1/2" in the "//tr[contains(., 'Tracked handout')]" "xpath_element"
