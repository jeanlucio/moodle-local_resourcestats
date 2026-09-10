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
 * @covers     \local_resourcestats\local\group_visibility
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
     * Building the template context must not add roughly one query per extra enrolled
     * user.
     *
     * Regression guard for the N+1: a has_capability() call per enrolled user inside a
     * loop would make the query count grow with enrolment size; the batched
     * get_enrolled_users(..., 'moodle/course:manageactivities') approach adds only a
     * small constant number of queries regardless of how many students are enrolled.
     */
    public function test_get_students_does_not_scale_with_enrolment_count(): void {
        global $DB;

        $gen = $this->getDataGenerator();
        $gen->create_module('page', ['course' => $this->course->id]);
        $gen->enrol_user($gen->create_user()->id, $this->course->id, 'student');

        $before = $DB->perf_get_queries();
        $this->get_context();
        $querieswithfew = $DB->perf_get_queries() - $before;

        for ($i = 0; $i < 20; $i++) {
            $gen->enrol_user($gen->create_user()->id, $this->course->id, 'student');
        }

        $before = $DB->perf_get_queries();
        $this->get_context();
        $querieswithmany = $DB->perf_get_queries() - $before;

        // 20 extra enrolled users must not add anywhere near 20 extra queries.
        $this->assertLessThan(10, $querieswithmany - $querieswithfew);
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

    /**
     * A teacher without moodle/site:accessallgroups, restricted to one of two separate
     * groups in a course with separate groups mode, must only count students from their
     * own group in totalstudents.
     */
    public function test_group_restricted_teacher_sees_only_own_group(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS]);
        $context = context_course::instance($course->id);

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        $ingroup = $generator->create_user();
        $outgroup = $generator->create_user();
        $generator->enrol_user($ingroup->id, $course->id, 'student');
        $generator->enrol_user($outgroup->id, $course->id, 'student');
        groups_add_member($group1, $ingroup);
        groups_add_member($group2, $outgroup);

        $restrictedroleid = $generator->create_role(['shortname' => 'grouprestrictedteacher']);
        $generator->create_role_capability(
            $restrictedroleid,
            ['moodle/course:manageactivities' => 'allow'],
            \context_system::instance()
        );
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group1, $teacher);

        $generator->create_module('page', ['course' => $course->id]);

        $this->setUser($teacher);
        $ctx = (new controller($course, $context))->get_template_context();

        $this->assertEquals(1, $ctx['totalstudents']);
    }

    /**
     * A teacher restricted to one group must see per-activity totalviews/uniqueviews
     * recomputed from only their own group's students, never the course-wide aggregate
     * that includes the other group's access.
     */
    public function test_group_restricted_teacher_sees_only_own_group_activity_totals(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS]);
        $context = context_course::instance($course->id);

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        $ingroup = $generator->create_user();
        $outgroup = $generator->create_user();
        $generator->enrol_user($ingroup->id, $course->id, 'student');
        $generator->enrol_user($outgroup->id, $course->id, 'student');
        groups_add_member($group1, $ingroup);
        groups_add_member($group2, $outgroup);

        $restrictedroleid = $generator->create_role(['shortname' => 'grouprestrictedteacher']);
        $generator->create_role_capability(
            $restrictedroleid,
            ['moodle/course:manageactivities' => 'allow'],
            \context_system::instance()
        );
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group1, $teacher);

        // The activity's own groupmode must actually match the course's, mirroring how a
        // new activity inherits the course's group mode by default in the real UI; without
        // groupmodeforce, effectivegroupmode comes from the activity's own setting, not the
        // course's, so leaving it unset here would test an unrelated scenario (see the
        // per-activity-override fix, which handles exactly the case where they diverge).
        $page = $generator->create_module('page', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $now = time();
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm->id, 'userid' => $ingroup->id, 'viewcount' => 3,
            'firstviewtime' => $now - 200, 'lastviewtime' => $now - 100,
        ]);
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm->id, 'userid' => $outgroup->id, 'viewcount' => 9,
            'firstviewtime' => $now - 300, 'lastviewtime' => $now - 10,
        ]);
        // The aggregate is course-wide: 12 views, last visitor is the out-of-group student.
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid' => $cm->id, 'totalviews' => 12, 'uniqueviews' => 2,
            'lastuserid' => $outgroup->id, 'lastviewtime' => $now - 10,
            'deletedviews' => 0, 'deletedcount' => 0,
        ]);

        $this->setUser($teacher);
        $ctx = (new controller($course, $context))->get_template_context();
        $row = $ctx['activities'][0];

        $this->assertEquals(3, $row['totalviews']);
        $this->assertEquals(1, $row['uniqueviews']);
        $this->assertEquals(100, $row['engagementpct']);
    }

    /**
     * A teacher restricted to no group must see zeroed-out activity totals, not the
     * unfiltered course-wide aggregate.
     */
    public function test_group_restricted_teacher_in_no_group_sees_zero_activity_totals(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS]);
        $context = context_course::instance($course->id);

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        groups_add_member($group1, $student);

        $restrictedroleid = $generator->create_role(['shortname' => 'grouprestrictedteacher']);
        $generator->create_role_capability(
            $restrictedroleid,
            ['moodle/course:manageactivities' => 'allow'],
            \context_system::instance()
        );
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        // Deliberately not added to any group.

        // See the comment in the sibling test above: the activity must adopt the course's
        // groupmode explicitly, since effectivegroupmode is not forced here.
        $page = $generator->create_module('page', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm->id, 'userid' => $student->id, 'viewcount' => 4,
            'firstviewtime' => time(), 'lastviewtime' => time(),
        ]);
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid' => $cm->id, 'totalviews' => 4, 'uniqueviews' => 1,
            'lastuserid' => $student->id, 'lastviewtime' => time(),
            'deletedviews' => 0, 'deletedcount' => 0,
        ]);

        $this->setUser($teacher);
        $ctx = (new controller($course, $context))->get_template_context();
        $row = $ctx['activities'][0];

        $this->assertEquals(0, $ctx['totalstudents']);
        $this->assertEquals(0, $row['totalviews']);
        $this->assertEquals(0, $row['uniqueviews']);
        $this->assertSame('', $row['lastviewtime']);
    }

    /**
     * The consolidated export for a course-level controller must also respect separate
     * groups: a group-restricted teacher's export must not contain rows for students
     * outside their own group.
     */
    public function test_export_rows_respect_group_restriction(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS]);
        $context = context_course::instance($course->id);

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        // Explicit distinct names: two independently-generated random users can
        // occasionally share the same fake name from the generator's fixed pool, which
        // would make the fullname()-based assertions below spuriously pass or fail.
        $ingroup = $generator->create_user(['firstname' => 'InGroup', 'lastname' => 'Student']);
        $outgroup = $generator->create_user(['firstname' => 'OutGroup', 'lastname' => 'Student']);
        $generator->enrol_user($ingroup->id, $course->id, 'student');
        $generator->enrol_user($outgroup->id, $course->id, 'student');
        groups_add_member($group1, $ingroup);
        groups_add_member($group2, $outgroup);

        $restrictedroleid = $generator->create_role(['shortname' => 'grouprestrictedteacher']);
        $generator->create_role_capability(
            $restrictedroleid,
            ['moodle/course:manageactivities' => 'allow'],
            \context_system::instance()
        );
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group1, $teacher);

        $generator->create_module('page', ['course' => $course->id]);

        $this->setUser($teacher);
        [, , $rows] = (new controller($course, $context))->get_rows_for_export();

        $studentnames = array_column($rows, 1);
        $this->assertContains(fullname($ingroup), $studentnames);
        $this->assertNotContains(fullname($outgroup), $studentnames);
    }

    /**
     * A teacher holding moodle/site:accessallgroups must still count every student
     * regardless of separate groups mode; the fix must not restrict privileged staff.
     */
    public function test_teacher_with_accessallgroups_sees_every_group(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS]);
        $context = context_course::instance($course->id);

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $generator->enrol_user($s1->id, $course->id, 'student');
        $generator->enrol_user($s2->id, $course->id, 'student');
        groups_add_member($group1, $s1);
        groups_add_member($group2, $s2);

        // The default editingteacher archetype includes moodle/site:accessallgroups.
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setUser($teacher);
        $ctx = (new controller($course, $context))->get_template_context();

        $this->assertEquals(2, $ctx['totalstudents']);
    }

    /**
     * When the course itself is NOT in separate groups but one activity overrides its own
     * group mode to separate groups, that activity's row must be scoped to the caller's own
     * group — using the group's own student count as the engagement denominator, not the
     * unrestricted course-wide totalstudents. The page must also expose the group total so
     * the UI can explain why that row's percentage differs from the course-wide count.
     *
     * Regression guard: group_visibility::get_course_group_restriction() (course-level,
     * via groups_get_course_groupmode()) is not equivalent to
     * get_activity_group_restriction() (activity-level, via groups_get_activity_groupmode(),
     * which is $cm->effectivegroupmode) — a course-level-only check would treat this
     * activity as unrestricted and leak the other group's totals.
     */
    public function test_activity_overriding_course_groupmode_scopes_its_own_row(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        // Course is NOT in separate groups mode — the default.
        $course = $generator->create_course();
        $context = context_course::instance($course->id);

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        $ingroup = $generator->create_user();
        $outgroup = $generator->create_user();
        $generator->enrol_user($ingroup->id, $course->id, 'student');
        $generator->enrol_user($outgroup->id, $course->id, 'student');
        groups_add_member($group1, $ingroup);
        groups_add_member($group2, $outgroup);

        $restrictedroleid = $generator->create_role(['shortname' => 'grouprestrictedteacher']);
        $generator->create_role_capability(
            $restrictedroleid,
            ['moodle/course:manageactivities' => 'allow'],
            \context_system::instance()
        );
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group1, $teacher);

        // The course itself is unrestricted; only this specific activity overrides its own
        // group mode to separate groups.
        $page = $generator->create_module('page', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $now = time();
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm->id, 'userid' => $ingroup->id, 'viewcount' => 6,
            'firstviewtime' => $now - 200, 'lastviewtime' => $now - 100,
        ]);
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm->id, 'userid' => $outgroup->id, 'viewcount' => 9,
            'firstviewtime' => $now - 300, 'lastviewtime' => $now - 10,
        ]);
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid' => $cm->id, 'totalviews' => 15, 'uniqueviews' => 2,
            'lastuserid' => $outgroup->id, 'lastviewtime' => $now - 10,
            'deletedviews' => 0, 'deletedcount' => 0,
        ]);

        $this->setUser($teacher);
        $ctx = (new controller($course, $context))->get_template_context();
        $row = $ctx['activities'][0];

        // Course-wide totalstudents is unaffected (the course itself is not restricted).
        $this->assertEquals(2, $ctx['totalstudents']);
        // But the row itself is scoped to the caller's own group (1 student, 6 views), with
        // engagement computed against that group's own size (1), not the course-wide 2.
        $this->assertEquals(6, $row['totalviews']);
        $this->assertEquals(1, $row['uniqueviews']);
        $this->assertEquals(100, $row['engagementpct']);

        // The page must expose the group total so the discrepancy is explained in the UI.
        $this->assertTrue($ctx['showgrouptotal']);
        $this->assertEquals(1, $ctx['grouptotalstudents']);
    }

    /**
     * The consolidated export must scope an individually-overridden activity's rows to the
     * caller's own group even when the course itself is not in separate groups mode.
     */
    public function test_export_scopes_activity_overriding_course_groupmode(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = context_course::instance($course->id);

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        // Explicit distinct names: two independently-generated random users can
        // occasionally share the same fake name from the generator's fixed pool, which
        // would make the fullname()-based assertions below spuriously pass or fail.
        $ingroup = $generator->create_user(['firstname' => 'InGroup', 'lastname' => 'Student']);
        $outgroup = $generator->create_user(['firstname' => 'OutGroup', 'lastname' => 'Student']);
        $generator->enrol_user($ingroup->id, $course->id, 'student');
        $generator->enrol_user($outgroup->id, $course->id, 'student');
        groups_add_member($group1, $ingroup);
        groups_add_member($group2, $outgroup);

        $restrictedroleid = $generator->create_role(['shortname' => 'grouprestrictedteacher']);
        $generator->create_role_capability(
            $restrictedroleid,
            ['moodle/course:manageactivities' => 'allow'],
            \context_system::instance()
        );
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group1, $teacher);

        $generator->create_module('page', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);

        $this->setUser($teacher);
        [, , $rows] = (new controller($course, $context))->get_rows_for_export();

        $studentnames = array_column($rows, 1);
        $this->assertContains(fullname($ingroup), $studentnames);
        $this->assertNotContains(fullname($outgroup), $studentnames);
    }

    /**
     * The export must carry each student's real access data (view count and formatted
     * first/last access timestamps), not just their name — every export test until now
     * only asserted on which students appear, never on the row's own view data.
     */
    public function test_export_includes_actual_view_data(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $page = $generator->create_module('page', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $this->course->id, false, MUST_EXIST);

        // Explicit distinct names: two independently-generated random users can
        // occasionally share the same fake name from the generator's fixed pool, which
        // would make the fullname()-keyed lookup below collide between the two rows.
        $viewed = $generator->create_user(['firstname' => 'Viewed', 'lastname' => 'Student']);
        $unviewed = $generator->create_user(['firstname' => 'Unviewed', 'lastname' => 'Student']);
        $generator->enrol_user($viewed->id, $this->course->id, 'student');
        $generator->enrol_user($unviewed->id, $this->course->id, 'student');

        $firstviewtime = time() - 3600;
        $lastviewtime = time();
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm->id, 'userid' => $viewed->id, 'viewcount' => 7,
            'firstviewtime' => $firstviewtime, 'lastviewtime' => $lastviewtime,
        ]);

        [, , $rows] = (new controller($this->course, $this->context))->get_rows_for_export();

        $rowsbyname = [];
        foreach ($rows as $row) {
            $rowsbyname[$row[1]] = $row;
        }

        $never = get_string('never', 'local_resourcestats');
        $viewedrow = $rowsbyname[fullname($viewed)];
        $this->assertSame(7, $viewedrow[2]);
        $this->assertSame(userdate($firstviewtime), $viewedrow[3]);
        $this->assertSame(userdate($lastviewtime), $viewedrow[4]);

        $unviewedrow = $rowsbyname[fullname($unviewed)];
        $this->assertSame(0, $unviewedrow[2]);
        $this->assertSame($never, $unviewedrow[3]);
        $this->assertSame($never, $unviewedrow[4]);
    }

    /**
     * A course with no trackable activities must export an empty (but well-formed) file
     * rather than erroring on a missing activity to iterate.
     */
    public function test_export_with_no_trackable_activities_returns_empty_rows(): void {
        [$filename, $columns, $rows] = (new controller($this->course, $this->context))->get_rows_for_export();

        $this->assertStringStartsWith('resourcestats_course_', $filename);
        $this->assertNotEmpty($columns);
        $this->assertSame([], $rows);
    }

    /**
     * More than one page's worth of activities (PERPAGE = 50) must render a paging bar;
     * every other test in this file stays well under that threshold.
     */
    public function test_pagination_renders_when_activities_exceed_one_page(): void {
        $generator = $this->getDataGenerator();
        for ($i = 0; $i < 51; $i++) {
            $generator->create_module('page', ['course' => $this->course->id]);
        }

        $ctx = $this->get_context();

        $this->assertCount(50, $ctx['activities']);
        $this->assertNotSame('', $ctx['paginationhtml']);
    }

    /**
     * The activity table carries the completion aggregate, and marks an activity without
     * completion tracking as such rather than reporting it as zero completions.
     */
    public function test_template_context_includes_completion_columns(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $tracked = $generator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $untracked = $generator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid'  => $tracked->cmid,
            'userid'          => $student->id,
            'completionstate' => COMPLETION_COMPLETE,
            'overrideby'      => null,
            'timemodified'    => time(),
        ]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $controller = new controller($course, \context_course::instance($course->id));
        $context = $controller->get_template_context();

        $byid = [];
        foreach ($context['activities'] as $activity) {
            $byid[$activity['cmid']] = $activity;
        }

        $this->assertTrue($byid[(int)$tracked->cmid]['hascompletion']);
        $this->assertSame(1, $byid[(int)$tracked->cmid]['completed']);
        $this->assertSame(1, $byid[(int)$tracked->cmid]['trackedtotal']);
        $this->assertFalse($byid[(int)$tracked->cmid]['haspass']);

        $this->assertFalse($byid[(int)$untracked->cmid]['hascompletion']);
        $this->assertSame(-1, $byid[(int)$untracked->cmid]['_completedsort']);
    }

    /**
     * Sorting by the completion column keeps activities where completion does not apply
     * below the ones where it does, instead of mixing them in with zero completions.
     */
    public function test_sorting_by_completed_places_inapplicable_last(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_NONE,
            'name'       => 'AAA no completion',
        ]);
        $tracked = $generator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'name'       => 'BBB tracked',
        ]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $controller = new controller($course, \context_course::instance($course->id), 'completed', 'desc');
        $context = $controller->get_template_context();

        $first = $context['activities'][0];
        $this->assertSame((int)$tracked->cmid, $first['cmid']);
    }

    /**
     * The completion lookup must cost the same whether the course has two activities or
     * twenty: it is fetched in one batched pass, not once per activity.
     *
     * This is the guard for the query cost this feature added to a page teachers open on
     * every course — the count-per-test baseline catches a regression only after the fact,
     * whereas this fails on the spot.
     */
    public function test_completion_lookup_does_not_scale_with_activity_count(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        global $DB;

        // The shared fixture course has completion off; these figures only exist with it on.
        set_config('enablecompletion', 1);
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);
        rebuild_course_cache($this->course->id, true);
        $this->course = $DB->get_record('course', ['id' => $this->course->id], '*', MUST_EXIST);

        $gen = $this->getDataGenerator();
        $gen->enrol_user($gen->create_user()->id, $this->course->id, 'student');

        for ($i = 0; $i < 2; $i++) {
            $gen->create_module('page', [
                'course'     => $this->course->id,
                'completion' => COMPLETION_TRACKING_MANUAL,
            ]);
        }

        $before = $DB->perf_get_queries();
        $this->get_context();
        $querieswithfew = $DB->perf_get_queries() - $before;

        for ($i = 0; $i < 18; $i++) {
            $gen->create_module('page', [
                'course'     => $this->course->id,
                'completion' => COMPLETION_TRACKING_MANUAL,
            ]);
        }

        $before = $DB->perf_get_queries();
        $this->get_context();
        $querieswithmany = $DB->perf_get_queries() - $before;

        // 18 extra activities must not add anywhere near 18 extra queries.
        $this->assertLessThan(10, $querieswithmany - $querieswithfew);
    }
}
