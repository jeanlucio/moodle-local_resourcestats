<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * PHPUnit tests for the course_stats controller.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\course_stats;

use advanced_testcase;
use context_course;

/**
 * Test cases for local_resourcestats\course_stats\controller.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\course_stats\controller
 */
final class controller_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /** @var context_course Course context. */
    private context_course $context;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->context = context_course::instance($this->course->id);
    }

    /**
     * Inserts an aggregate view record directly into local_resourcestats_views.
     *
     * @param int      $cmid        Course module ID.
     * @param int      $totalviews  Total access count.
     * @param int      $uniqueviews Distinct student count.
     * @param int|null $lastviewts  Timestamp of last view; null for no timestamp.
     */
    private function insert_view_aggregate(
        int $cmid,
        int $totalviews,
        int $uniqueviews,
        ?int $lastviewts = null
    ): void {
        global $DB;
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid'         => $cmid,
            'totalviews'   => $totalviews,
            'uniqueviews'  => $uniqueviews,
            'lastuserid'   => null,
            'lastviewtime' => $lastviewts,
            'deletedviews' => 0,
            'deletedcount' => 0,
        ]);
    }

    /**
     * Returns the template context from a fresh controller instance.
     *
     * @param string $sort Sort column (default 'activityname').
     * @param string $dir  Sort direction (default 'asc').
     * @return array
     */
    private function get_context(string $sort = 'activityname', string $dir = 'asc'): array {
        return (new controller($this->course, $this->context, $sort, $dir))->get_template_context();
    }

    /**
     * A course with no trackable activities returns hasactivities = false and an empty list.
     */
    public function test_no_trackable_activities_returns_empty_state(): void {
        $ctx = $this->get_context();
        $this->assertFalse($ctx['hasactivities']);
        $this->assertEmpty($ctx['activities']);
    }

    /**
     * An activity with no aggregate row in the views table has unviewed = true and all
     * numeric counts equal zero.
     */
    public function test_activity_with_no_views_is_marked_unviewed(): void {
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Lesson']);

        $ctx = $this->get_context();
        $this->assertTrue($ctx['hasactivities']);
        $this->assertCount(1, $ctx['activities']);

        $row = $ctx['activities'][0];
        $this->assertTrue($row['unviewed']);
        $this->assertEquals(0, $row['totalviews']);
        $this->assertEquals(0, $row['uniqueviews']);
        $this->assertEquals(0, $row['engagementpct']);
        $this->assertSame('', $row['lastviewtime']);
    }

    /**
     * An activity with a view record shows correct totalviews, uniqueviews, and the
     * engagement percentage computed as round(uniqueviews / totalstudents * 100).
     */
    public function test_activity_row_has_correct_engagement_data(): void {
        $gen = $this->getDataGenerator();
        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $gen->enrol_user($s1->id, $this->course->id, 'student');
        $gen->enrol_user($s2->id, $this->course->id, 'student');

        $page = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Lesson']);
        $cm = get_coursemodule_from_instance('page', $page->id, $this->course->id, false, MUST_EXIST);
        $this->insert_view_aggregate($cm->id, 5, 1);

        $ctx = $this->get_context();
        $row = $ctx['activities'][0];

        $this->assertFalse($row['unviewed']);
        $this->assertEquals(5, $row['totalviews']);
        $this->assertEquals(1, $row['uniqueviews']);
        // 2 students enrolled, 1 accessed: 50%.
        $this->assertEquals(50, $row['engagementpct']);
    }

    /**
     * When no students are enrolled, engagement percentage is always zero (prevents
     * division-by-zero).
     */
    public function test_engagement_is_zero_when_no_students_enrolled(): void {
        $gen = $this->getDataGenerator();
        $page = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Lesson']);
        $cm = get_coursemodule_from_instance('page', $page->id, $this->course->id, false, MUST_EXIST);
        $this->insert_view_aggregate($cm->id, 3, 3);

        $ctx = $this->get_context();
        $this->assertEquals(0, $ctx['activities'][0]['engagementpct']);
        $this->assertEquals(0, $ctx['totalstudents']);
    }

    /**
     * Users enrolled as editing teachers (who have manageactivities) must not be counted
     * in totalstudents.
     */
    public function test_teacher_excluded_from_total_students(): void {
        $gen = $this->getDataGenerator();
        $gen->enrol_user($gen->create_user()->id, $this->course->id, 'editingteacher');
        $gen->enrol_user($gen->create_user()->id, $this->course->id, 'student');

        $gen->create_module('page', ['course' => $this->course->id]);

        $ctx = $this->get_context();
        $this->assertEquals(1, $ctx['totalstudents']);
    }

    /**
     * The section field of each row must be a non-empty string derived from the course
     * format (e.g., "General" for section 0 in topics format).
     */
    public function test_activity_row_has_section_name(): void {
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $ctx = $this->get_context();
        $this->assertNotEmpty($ctx['activities'][0]['section']);
        $this->assertIsString($ctx['activities'][0]['section']);
    }

    /**
     * Sorting by activityname ascending places the alphabetically earlier name first.
     */
    public function test_sort_by_activityname_asc(): void {
        $gen = $this->getDataGenerator();
        $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Zeta resource']);
        $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Alpha resource']);

        $ctx = $this->get_context('activityname', 'asc');
        $this->assertStringContainsString('Alpha', $ctx['activities'][0]['activityname']);
        $this->assertStringContainsString('Zeta', $ctx['activities'][1]['activityname']);
    }

    /**
     * Sorting by activityname descending places the alphabetically later name first.
     */
    public function test_sort_by_activityname_desc(): void {
        $gen = $this->getDataGenerator();
        $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Alpha resource']);
        $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Zeta resource']);

        $ctx = $this->get_context('activityname', 'desc');
        $this->assertStringContainsString('Zeta', $ctx['activities'][0]['activityname']);
        $this->assertStringContainsString('Alpha', $ctx['activities'][1]['activityname']);
    }

    /**
     * Sorting by totalviews descending places the most-viewed activity first.
     */
    public function test_sort_by_totalviews_desc(): void {
        $gen = $this->getDataGenerator();
        $p1 = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Page A']);
        $p2 = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Page B']);
        $cm1 = get_coursemodule_from_instance('page', $p1->id, $this->course->id, false, MUST_EXIST);
        $cm2 = get_coursemodule_from_instance('page', $p2->id, $this->course->id, false, MUST_EXIST);
        $this->insert_view_aggregate($cm1->id, 3, 1);
        $this->insert_view_aggregate($cm2->id, 10, 1);

        $ctx = $this->get_context('totalviews', 'desc');
        $this->assertEquals(10, $ctx['activities'][0]['totalviews']);
        $this->assertEquals(3, $ctx['activities'][1]['totalviews']);
    }

    /**
     * An invalid sort parameter is silently rejected and the list falls back to
     * activityname ascending (default).
     */
    public function test_invalid_sort_falls_back_to_activityname_asc(): void {
        $gen = $this->getDataGenerator();
        $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Zeta resource']);
        $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Alpha resource']);

        $ctx = $this->get_context('notacolumn', 'asc');
        $this->assertStringContainsString('Alpha', $ctx['activities'][0]['activityname']);
        $this->assertStringContainsString('Zeta', $ctx['activities'][1]['activityname']);
    }
}
