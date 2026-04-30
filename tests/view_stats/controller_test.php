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
 * PHPUnit tests for the view_stats controller.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\tests\view_stats;

use advanced_testcase;
use local_resourcestats\view_stats\controller;

/**
 * Test cases for local_resourcestats\view_stats\controller.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\view_stats\controller
 */
final class controller_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /** @var \cm_info Course module info object for the test resource. */
    private \cm_info $cm;

    /** @var \context_module Module context. */
    private \context_module $context;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $this->course->id]);
        $cmrecord = get_coursemodule_from_instance(
            'page',
            $page->id,
            $this->course->id,
            false,
            MUST_EXIST
        );
        $modinfo = get_fast_modinfo($this->course);
        $this->cm = $modinfo->get_cm($cmrecord->id);
        $this->context = \context_module::instance($cmrecord->id);
    }

    /**
     * Inserts a row directly into local_resourcestats_user_views for testing.
     *
     * @param int|null $userid     User ID; null only for legacy direct-insert testing.
     * @param int      $viewcount  Number of accesses.
     * @param int|null $firsttime  Timestamp of first access.
     * @param int|null $lasttime   Timestamp of last access.
     */
    private function insert_user_view(
        ?int $userid,
        int $viewcount = 1,
        ?int $firsttime = null,
        ?int $lasttime = null
    ): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid'          => $this->cm->id,
            'userid'        => $userid,
            'viewcount'     => $viewcount,
            'firstviewtime' => $firsttime ?? $now,
            'lastviewtime'  => $lasttime ?? $now,
        ]);
    }

    /**
     * Inserts a row directly into local_resourcestats_views for testing.
     *
     * @param int      $totalviews   Total accesses.
     * @param int      $uniqueviews  Unique student count.
     * @param int|null $lastuserid   ID of the last user.
     * @param int      $deletedviews Views from GDPR-erased students.
     * @param int      $deletedcount Number of GDPR-erased students.
     */
    private function insert_aggregate(
        int $totalviews = 0,
        int $uniqueviews = 0,
        ?int $lastuserid = null,
        int $deletedviews = 0,
        int $deletedcount = 0
    ): void {
        global $DB;
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid'         => $this->cm->id,
            'totalviews'   => $totalviews,
            'uniqueviews'  => $uniqueviews,
            'lastuserid'   => $lastuserid,
            'lastviewtime' => time(),
            'deletedviews' => $deletedviews,
            'deletedcount' => $deletedcount,
        ]);
    }

    /**
     * Returns the template context from a fresh controller instance.
     *
     * @return array
     */
    private function get_context(): array {
        return (new controller($this->cm, $this->context))->get_template_context();
    }

    /**
     * With no access records the template context must represent an empty state.
     */
    public function test_no_views_returns_empty_state(): void {
        $ctx = $this->get_context();
        $this->assertFalse($ctx['hasviews']);
        $this->assertEmpty($ctx['students']);
        $this->assertEquals(0, $ctx['totalviews']);
        $this->assertEquals(0, $ctx['uniqueviews']);
        $this->assertFalse($ctx['hasdeletedrow']);
    }

    /**
     * Students must be ordered by viewcount descending.
     */
    public function test_students_ordered_by_viewcount_desc(): void {
        $generator = $this->getDataGenerator();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $generator->enrol_user($s1->id, $this->course->id, 'student');
        $generator->enrol_user($s2->id, $this->course->id, 'student');

        $this->insert_user_view($s1->id, 2, time() - 100, time() - 50);
        $this->insert_user_view($s2->id, 5, time() - 200, time() - 10);

        $ctx = $this->get_context();
        $this->assertEquals(5, $ctx['students'][0]['viewcount']);
        $this->assertEquals(2, $ctx['students'][1]['viewcount']);
    }

    /**
     * totalviews must equal the sum of individual viewcounts; uniqueviews must
     * equal the number of distinct student rows.
     */
    public function test_totals_sum_correctly(): void {
        $generator = $this->getDataGenerator();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $generator->enrol_user($s1->id, $this->course->id, 'student');
        $generator->enrol_user($s2->id, $this->course->id, 'student');

        $this->insert_user_view($s1->id, 3);
        $this->insert_user_view($s2->id, 7);

        $ctx = $this->get_context();
        $this->assertEquals(10, $ctx['totalviews']);
        $this->assertEquals(2, $ctx['uniqueviews']);
    }

    /**
     * Views from GDPR-erased students are stored in the aggregate columns
     * deletedviews/deletedcount and must appear in the deletedrow.
     */
    public function test_gdpr_erased_views_shown_as_deleted_row(): void {
        // Simulate the aggregate state after one student (4 views) was GDPR-erased:
        // no row in user_views, but deletedviews=4 and deletedcount=1 in aggregate.
        $this->insert_aggregate(4, 1, null, 4, 1);

        $ctx = $this->get_context();
        $this->assertEmpty($ctx['students']);
        $this->assertTrue($ctx['hasdeletedrow']);
        $this->assertEquals(4, $ctx['deletedrow']['viewcount']);
        $this->assertEquals(1, $ctx['uniqueviews']);
        $this->assertEquals(4, $ctx['totalviews']);
    }

    /**
     * A student whose Moodle account has been soft-deleted (deleted = 1) must be
     * detected via the LEFT JOIN and appear only in the deletedrow.
     */
    public function test_moodle_deleted_user_treated_as_deleted(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->insert_user_view($student->id, 3);

        // Soft-delete the Moodle account.
        $DB->set_field('user', 'deleted', 1, ['id' => $student->id]);

        $ctx = $this->get_context();
        $this->assertEmpty($ctx['students']);
        $this->assertTrue($ctx['hasdeletedrow']);
        $this->assertEquals(3, $ctx['deletedrow']['viewcount']);
    }

    /**
     * Admin-deleted and GDPR-erased students must be combined into a single
     * deletedrow showing the sum of their viewcounts.
     */
    public function test_admin_deleted_and_gdpr_erased_combined_in_deleted_row(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->insert_user_view($student->id, 3);

        // Soft-delete the admin-deleted student.
        $DB->set_field('user', 'deleted', 1, ['id' => $student->id]);

        // Aggregate reflects one prior GDPR erasure (5 views) plus the admin-deleted student.
        $this->insert_aggregate(8, 2, null, 5, 1);

        $ctx = $this->get_context();
        $this->assertEmpty($ctx['students']);
        $this->assertTrue($ctx['hasdeletedrow']);
        // Combined: 3 (admin-deleted) + 5 (GDPR) = 8 views, count = 1 + 1 = 2.
        $this->assertEquals(8, $ctx['deletedrow']['viewcount']);
        $this->assertEquals(2, $ctx['uniqueviews']);
        $this->assertEquals(8, $ctx['totalviews']);
    }
}
