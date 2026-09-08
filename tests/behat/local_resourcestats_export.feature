@local @local_resourcestats @local_resourcestats_export
Feature: Export links
  As a teacher
  I want to export the statistics data
  So that I can analyse it outside Moodle

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name          |
      | page     | C1     | Week 1 Slides |
    And "student1" has 2 Resource Stats accesses on "Week 1 Slides" in course "C1"
    And I log in as "teacher1"

  Scenario: Course overview page offers CSV and Excel export links
    When I am on "Course 1" course homepage
    And I select "Course statistics" from secondary navigation
    Then "Export CSV" "link" should exist
    And "Export Excel" "link" should exist

  Scenario: Per-activity detail page offers CSV and Excel export links
    When I am on "Course 1" course homepage
    And I select "Course statistics" from secondary navigation
    And I click on "View details" "link"
    Then "Export CSV" "link" should exist
    And "Export Excel" "link" should exist
