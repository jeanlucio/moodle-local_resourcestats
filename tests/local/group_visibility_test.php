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
 * PHPUnit tests for the shared group-visibility helper.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\local;

use advanced_testcase;
use context_course;
use context_module;

/**
 * Test cases for local_resourcestats\local\group_visibility.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\local\group_visibility
 */
final class group_visibility_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds a course forced into separate groups mode, with two choice activities
     * (choice supports FEATURE_GROUPS; page does not), a group, and a restricted role.
     *
     * @return array Four-element array: [$course, $cm1, $cm2, $restrictedroleid].
     */
    private function create_scenario(): array {
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);

        $choice1 = $generator->create_module('choice', ['course' => $course->id]);
        $cm1record = get_coursemodule_from_instance('choice', $choice1->id, $course->id, false, MUST_EXIST);
        $choice2 = $generator->create_module('choice', ['course' => $course->id]);
        $cm2record = get_coursemodule_from_instance('choice', $choice2->id, $course->id, false, MUST_EXIST);

        $modinfo = get_fast_modinfo($course);
        $cm1 = $modinfo->get_cm($cm1record->id);
        $cm2 = $modinfo->get_cm($cm2record->id);

        $restrictedroleid = $generator->create_role(['shortname' => 'grouprestrictedteacher']);
        $generator->create_role_capability(
            $restrictedroleid,
            ['moodle/course:manageactivities' => 'allow'],
            \context_system::instance()
        );

        return [$course, $cm1, $cm2, $restrictedroleid];
    }

    /**
     * Two calls to get_activity_group_restriction() for different activities that share
     * the same (course, grouping) must reuse a caller-supplied cache instead of querying
     * groups again.
     *
     * Regression guard: without threading the cache through by reference, this would cost
     * one groups_get_all_groups() query per activity — the N+1 this fix removes.
     */
    public function test_get_activity_group_restriction_reuses_shared_cache(): void {
        global $DB;

        [$course, $cm1, $cm2, $restrictedroleid] = $this->create_scenario();
        $generator = $this->getDataGenerator();

        $group = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group, $teacher);
        $this->setUser($teacher);

        $modcontext1 = context_module::instance($cm1->id);
        $modcontext2 = context_module::instance($cm2->id);

        $cache = [];

        $before = $DB->perf_get_queries();
        $restriction1 = group_visibility::get_activity_group_restriction($cm1, $modcontext1, $cache);
        $firstcallqueries = $DB->perf_get_queries() - $before;
        $this->assertGreaterThan(0, $firstcallqueries, 'The first lookup for a grouping must hit the database.');

        $before = $DB->perf_get_queries();
        $restriction2 = group_visibility::get_activity_group_restriction($cm2, $modcontext2, $cache);
        $secondcallqueries = $DB->perf_get_queries() - $before;

        $this->assertEquals(0, $secondcallqueries, 'A second activity sharing the same grouping must not re-query.');
        $this->assertEquals($restriction1, $restriction2);
    }

    /**
     * Without a shared cache (the default), each call queries independently — this is the
     * pre-fix behaviour, kept working for a caller that only checks a single activity.
     */
    public function test_get_activity_group_restriction_without_shared_cache_queries_each_time(): void {
        global $DB;

        [$course, $cm1, $cm2, $restrictedroleid] = $this->create_scenario();
        $generator = $this->getDataGenerator();

        $group = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group, $teacher);
        $this->setUser($teacher);

        $modcontext1 = context_module::instance($cm1->id);
        $modcontext2 = context_module::instance($cm2->id);

        $before = $DB->perf_get_queries();
        group_visibility::get_activity_group_restriction($cm1, $modcontext1);
        $firstcallqueries = $DB->perf_get_queries() - $before;

        $before = $DB->perf_get_queries();
        group_visibility::get_activity_group_restriction($cm2, $modcontext2);
        $secondcallqueries = $DB->perf_get_queries() - $before;

        $this->assertGreaterThan(0, $firstcallqueries);
        $this->assertGreaterThan(0, $secondcallqueries);
    }

    /**
     * Two calls to restrict_students_by_activity() for different activities that resolve
     * to the same group IDs must reuse a caller-supplied enrolment cache instead of
     * re-running the group-scoped get_enrolled_users() query.
     *
     * Regression guard: get_activity_group_restriction()'s own cache only memoises the
     * group ID lookup, not the enrolment query built from those IDs — a course export
     * iterating many activities would otherwise cost one get_enrolled_users() call per
     * activity even when every activity shares the same restriction.
     */
    public function test_restrict_students_by_activity_reuses_shared_enrolled_cache(): void {
        global $DB;

        [$course, $cm1, $cm2, $restrictedroleid] = $this->create_scenario();
        $generator = $this->getDataGenerator();

        $group = $generator->create_group(['courseid' => $course->id]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        groups_add_member($group, $student);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group, $teacher);
        $this->setUser($teacher);

        $modcontext1 = context_module::instance($cm1->id);
        $modcontext2 = context_module::instance($cm2->id);
        $coursecontext = context_course::instance($course->id);
        $students = [$student->id => $student];

        $groupidscache = [];
        $enrolledcache = [];

        $before = $DB->perf_get_queries();
        $result1 = group_visibility::restrict_students_by_activity(
            $students,
            $cm1,
            $modcontext1,
            $coursecontext,
            $groupidscache,
            $enrolledcache
        );
        $firstcallqueries = $DB->perf_get_queries() - $before;
        $this->assertGreaterThan(0, $firstcallqueries, 'The first lookup for a group must hit the database.');

        $before = $DB->perf_get_queries();
        $result2 = group_visibility::restrict_students_by_activity(
            $students,
            $cm2,
            $modcontext2,
            $coursecontext,
            $groupidscache,
            $enrolledcache
        );
        $secondcallqueries = $DB->perf_get_queries() - $before;

        $this->assertEquals(0, $secondcallqueries, 'A second activity sharing the same group must not re-query enrolment.');
        $this->assertEquals(array_keys($result1), array_keys($result2));
        $this->assertArrayHasKey($student->id, $result1);
    }
}
