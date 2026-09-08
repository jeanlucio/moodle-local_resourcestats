@local @local_resourcestats @local_resourcestats_access
Feature: Access control
  As a site
  I want only staff with the manage activities capability to see resource statistics
  So that students cannot view other students' access data

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

  Scenario: A teacher sees the Course statistics link in the course navigation
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    Then I should see "Course statistics"

  Scenario: A student does not see the Course statistics link
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should not see "Course statistics"
