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
 * PHPUnit tests for the course-view badges hook listener.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats;

use advanced_testcase;
use context_course;
use core\hook\output\before_standard_footer_html_generation;

/**
 * Test cases for local_resourcestats\hook_listener.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\hook_listener
 */
final class hook_listener_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds a course forced into separate groups mode, with a role that has
     * moodle/course:manageactivities but not moodle/site:accessallgroups.
     *
     * @return array Four-element array: [$course, $cm, $context, $restrictedroleid].
     */
    private function create_separategroups_scenario(): array {
        $generator = $this->getDataGenerator();

        // The choice module supports FEATURE_GROUPS; the page module does not, so a
        // course-forced group mode would have no effect on a page's effectivegroupmode.
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $choice = $generator->create_module('choice', ['course' => $course->id]);
        $cmrecord = get_coursemodule_from_instance('choice', $choice->id, $course->id, false, MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($cmrecord->id);
        $context = \context_module::instance($cmrecord->id);

        $restrictedroleid = $generator->create_role(['shortname' => 'grouprestrictedteacher']);
        $generator->create_role_capability(
            $restrictedroleid,
            ['moodle/course:manageactivities' => 'allow'],
            \context_system::instance()
        );

        return [$course, $cm, $context, $restrictedroleid];
    }

    /**
     * Inserts a row directly into local_resourcestats_user_views for testing.
     *
     * @param int $cmid       Course module ID.
     * @param int $userid     User ID.
     * @param int $viewcount  Number of accesses.
     * @param int $lastviewat Timestamp of last access.
     */
    private function insert_user_view(int $cmid, int $userid, int $viewcount, int $lastviewat): void {
        global $DB;
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cmid, 'userid' => $userid, 'viewcount' => $viewcount,
            'firstviewtime' => $lastviewat, 'lastviewtime' => $lastviewat,
        ]);

        // The badges query reads local_resourcestats_views, not local_resourcestats_user_views,
        // to decide which cmids to include; the real observer always keeps both in sync.
        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $cmid]);
        if ($aggregate) {
            $aggregate->totalviews += $viewcount;
            $aggregate->uniqueviews += 1;
            if ($lastviewat >= (int)$aggregate->lastviewtime) {
                $aggregate->lastuserid = $userid;
                $aggregate->lastviewtime = $lastviewat;
            }
            $DB->update_record('local_resourcestats_views', $aggregate);
        } else {
            $DB->insert_record('local_resourcestats_views', (object) [
                'cmid' => $cmid, 'totalviews' => $viewcount, 'uniqueviews' => 1,
                'lastuserid' => $userid, 'lastviewtime' => $lastviewat,
                'deletedviews' => 0, 'deletedcount' => 0,
            ]);
        }
    }

    /**
     * Enables all three badge preferences for the given user and runs the hook,
     * returning the raw AMD bootstrap JS emitted for the page footer.
     *
     * @param \stdClass $course  The course being viewed.
     * @param \stdClass $teacher The user viewing the course page.
     * @return string
     */
    private function get_badge_js(\stdClass $course, \stdClass $teacher): string {
        global $PAGE;

        $this->setUser($teacher);
        set_user_preference(hook_listener::PREF_SHOW_TOTAL, 1);
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, 1);
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, 1);

        // A fresh page per call: once get_renderer() sets up the theme, moodle_page
        // refuses any further set_course()/set_pagetype() call on that same instance.
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_pagetype('course-view-topics');

        $hook = new before_standard_footer_html_generation($PAGE->get_renderer('core'));
        hook_listener::inject_course_badges($hook);

        return $PAGE->requires->get_end_code();
    }

    /**
     * A teacher holding moodle/site:accessallgroups must see the course-wide totals and
     * the true last viewer's name, regardless of separate groups mode.
     */
    public function test_unrestricted_teacher_sees_combined_totals_and_lastuser(): void {
        [$course, $cm] = $this->create_separategroups_scenario();
        $generator = $this->getDataGenerator();

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        // Explicit ASCII names: the AMD call JSON-encodes lastusername, which escapes
        // non-ASCII characters as \uXXXX — a randomly generated non-ASCII name would
        // never literally match a plain-string assertion against the raw JS output.
        $ingroup = $generator->create_user(['firstname' => 'InGroup', 'lastname' => 'Student']);
        $outgroup = $generator->create_user(['firstname' => 'OutGroup', 'lastname' => 'Student']);
        $generator->enrol_user($ingroup->id, $course->id, 'student');
        $generator->enrol_user($outgroup->id, $course->id, 'student');
        groups_add_member($group1, $ingroup);
        groups_add_member($group2, $outgroup);

        $now = time();
        $this->insert_user_view($cm->id, $ingroup->id, 3, $now - 100);
        $this->insert_user_view($cm->id, $outgroup->id, 5, $now - 10);

        // The default editingteacher archetype includes moodle/site:accessallgroups.
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $js = $this->get_badge_js($course, $teacher);

        $this->assertStringContainsString('"totalviews":8', $js);
        $this->assertStringContainsString('"uniqueviews":2', $js);
        $this->assertStringContainsString(fullname($outgroup), $js);
    }

    /**
     * A teacher without moodle/site:accessallgroups, restricted to one of two separate
     * groups, must see totals recomputed from only their own group, and must never see
     * the name of a student from a group they cannot access.
     */
    public function test_group_restricted_teacher_never_sees_other_group_name_or_totals(): void {
        [$course, $cm, , $restrictedroleid] = $this->create_separategroups_scenario();
        $generator = $this->getDataGenerator();

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        // Explicit ASCII names: the AMD call JSON-encodes lastusername, which escapes
        // non-ASCII characters as \uXXXX — a randomly generated non-ASCII name would
        // never literally match a plain-string assertion against the raw JS output.
        $ingroup = $generator->create_user(['firstname' => 'InGroup', 'lastname' => 'Student']);
        $outgroup = $generator->create_user(['firstname' => 'OutGroup', 'lastname' => 'Student']);
        $generator->enrol_user($ingroup->id, $course->id, 'student');
        $generator->enrol_user($outgroup->id, $course->id, 'student');
        groups_add_member($group1, $ingroup);
        groups_add_member($group2, $outgroup);

        $now = time();
        // The out-of-group student is the most recent visitor overall, so an unfiltered
        // badge would show their name — this is exactly the leak being fixed.
        $this->insert_user_view($cm->id, $ingroup->id, 3, $now - 100);
        $this->insert_user_view($cm->id, $outgroup->id, 5, $now - 10);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group1, $teacher);

        $js = $this->get_badge_js($course, $teacher);

        $this->assertStringNotContainsString(fullname($outgroup), $js);
        $this->assertStringContainsString(fullname($ingroup), $js);
        $this->assertStringContainsString('"totalviews":3', $js);
        $this->assertStringContainsString('"uniqueviews":1', $js);
    }

    /**
     * A teacher without moodle/site:accessallgroups who belongs to none of the course's
     * groups must see a zeroed-out badge, not the unfiltered totals.
     */
    public function test_group_restricted_teacher_in_no_group_sees_zeroed_badge(): void {
        [$course, $cm, , $restrictedroleid] = $this->create_separategroups_scenario();
        $generator = $this->getDataGenerator();

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        groups_add_member($group1, $student);

        $this->insert_user_view($cm->id, $student->id, 4, time());

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        // Deliberately not added to any group.

        $js = $this->get_badge_js($course, $teacher);

        $this->assertStringNotContainsString(fullname($student), $js);
        $this->assertStringContainsString('"totalviews":0', $js);
        $this->assertStringContainsString('"uniqueviews":0', $js);
    }

    /**
     * Badges for two activities sharing the same grouping must use the memoisation cache
     * threaded through inject_course_badges(): confirmed indirectly here by checking that
     * both activities' badges render correctly from a single pass. The query-count
     * regression guard for the underlying memoisation itself lives in
     * tests/local/group_visibility_test.php, isolated from page-render noise (module
     * cache rebuilds, capability warm-up) that made measuring it at this level unreliable.
     */
    public function test_group_restricted_badges_render_correctly_across_multiple_activities(): void {
        [$course, $cm, , $restrictedroleid] = $this->create_separategroups_scenario();
        $generator = $this->getDataGenerator();

        $group1 = $generator->create_group(['courseid' => $course->id]);
        $student = $generator->create_user(['firstname' => 'InGroup', 'lastname' => 'Student']);
        $generator->enrol_user($student->id, $course->id, 'student');
        groups_add_member($group1, $student);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $restrictedroleid);
        groups_add_member($group1, $teacher);

        $choice2 = $generator->create_module('choice', ['course' => $course->id]);
        $cm2 = get_coursemodule_from_instance('choice', $choice2->id, $course->id, false, MUST_EXIST);

        $this->insert_user_view($cm->id, $student->id, 2, time());
        $this->insert_user_view($cm2->id, $student->id, 5, time());

        $js = $this->get_badge_js($course, $teacher);

        $this->assertStringContainsString('"totalviews":2', $js);
        $this->assertStringContainsString('"totalviews":5', $js);
        $this->assertStringContainsString(fullname($student), $js);
    }
}
