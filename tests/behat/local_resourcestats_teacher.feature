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
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name          |
      | page     | C1     | Week 1 Slides |
    And "student1" has 3 Resource Stats accesses on "Week 1 Slides" in course "C1"
    And "student2" has 1 Resource Stats accesses on "Week 1 Slides" in course "C1"

  Scenario: Teacher enables display badges and sees them on the course page
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I select "Course statistics" from secondary navigation
    And I click on "Configure display" "link"
    And I set the field "Total accesses" to "1"
    And I set the field "Students who accessed" to "1"
    And I press "Save changes"
    And I am on "Course 1" course homepage
    Then I should see "4" in the "[data-activityname='Week 1 Slides']" "css_element"
    And I should see "2" in the "[data-activityname='Week 1 Slides']" "css_element"

  Scenario: Teacher opens the course statistics overview
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I select "Course statistics" from secondary navigation
    Then I should see "Week 1 Slides"
    And I should see "2 enrolled student(s)"
    And I should see "100%"
