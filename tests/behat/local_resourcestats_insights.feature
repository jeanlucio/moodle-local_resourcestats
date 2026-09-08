@local @local_resourcestats @local_resourcestats_insights @javascript
Feature: Engagement alerts panel
  As a teacher
  I want the engagement alerts panel to stay readable even with many activities
  So that a wall of activity names does not bury the useful signal

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | course | name      |
      | page     | C1     | Reading 1 |
      | page     | C1     | Reading 2 |
      | page     | C1     | Reading 3 |
      | page     | C1     | Reading 4 |
      | page     | C1     | Reading 5 |
      | page     | C1     | Reading 6 |
      | page     | C1     | Reading 7 |

  Scenario: More than five unviewed activities collapse behind a "show more" disclosure
    When I log in as "teacher1"
    And I open the course statistics page for course "C1"
    Then I should see "7 activities not yet viewed by any student:"
    And I should see "Reading 1" in the ".local_resourcestats-insights" "css_element"
    And I should see "Reading 5" in the ".local_resourcestats-insights" "css_element"
    And I should not see "Reading 6" in the ".local_resourcestats-insights" "css_element"
    And I should see "Show 2 more activities"
    When I click on "Show 2 more activities" "text"
    Then I should see "Reading 6" in the ".local_resourcestats-insights" "css_element"
    And I should see "Reading 7" in the ".local_resourcestats-insights" "css_element"
