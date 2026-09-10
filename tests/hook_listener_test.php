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
 * @covers     \local_resourcestats\local\group_visibility
 */
final class hook_listener_test extends advanced_testcase {
    /** @var array|null Badge payload decoded from the most recent run_hook() call. */
    private ?array $lastpayload = null;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Extracts and decodes the badge payload from the hook's HTML output.
     *
     * @param string $html The hook output.
     * @return array|null The decoded payload, or null when no data element was emitted.
     */
    private static function decode_payload(string $html): ?array {
        if (!preg_match('/data-payload="([^"]*)"/', $html, $matches)) {
            return null;
        }

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
    }

    /**
     * Returns the stats entry the most recent run emitted for a course module.
     *
     * @param int $cmid Course module ID.
     * @return array|null
     */
    private function stat_for(int $cmid): ?array {
        return $this->lastpayload['stats'][$cmid] ?? null;
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
     * Sets the given user as current, runs the hook against a course-view page for the
     * given course, and returns the raw AMD bootstrap JS emitted for the page footer.
     *
     * Leaves the three display preferences untouched — callers that need them enabled
     * must set them before calling this, mirroring get_badge_js() below.
     *
     * @param \stdClass $course   The course being viewed.
     * @param \stdClass $user     The user viewing the course page.
     * @param string    $pagetype The page's pagetype; defaults to a real course-view page.
     * @return string
     */
    private function run_hook(\stdClass $course, \stdClass $user, string $pagetype = 'course-view-topics'): string {
        global $PAGE;

        $this->setUser($user);

        // A fresh page per call: once get_renderer() sets up the theme, moodle_page
        // refuses any further set_course()/set_pagetype() call on that same instance.
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_pagetype($pagetype);

        $hook = new before_standard_footer_html_generation($PAGE->get_renderer('core'));
        hook_listener::inject_course_badges($hook);
        $this->lastpayload = self::decode_payload($hook->get_output());

        return $PAGE->requires->get_end_code();
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
        $this->setUser($teacher);
        set_user_preference(hook_listener::PREF_SHOW_TOTAL, 1);
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, 1);
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, 1);

        return $this->run_hook($course, $teacher);
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

        $this->get_badge_js($course, $teacher);
        $stat = $this->stat_for((int)$cm->id);

        $this->assertSame(8, $stat['totalviews']);
        $this->assertSame(2, $stat['uniqueviews']);
        $this->assertSame(fullname($outgroup), $stat['lastusername']);
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

        $this->get_badge_js($course, $teacher);
        $stat = $this->stat_for((int)$cm->id);

        $this->assertNotSame(fullname($outgroup), $stat['lastusername']);
        $this->assertSame(fullname($ingroup), $stat['lastusername']);
        $this->assertSame(3, $stat['totalviews']);
        $this->assertSame(1, $stat['uniqueviews']);
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

        $this->get_badge_js($course, $teacher);
        $stat = $this->stat_for((int)$cm->id);

        $this->assertSame('', $stat['lastusername']);
        $this->assertSame(0, $stat['totalviews']);
        $this->assertSame(0, $stat['uniqueviews']);
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

        $this->get_badge_js($course, $teacher);

        $this->assertSame(2, $this->stat_for((int)$cm->id)['totalviews']);
        $this->assertSame(5, $this->stat_for((int)$cm2->id)['totalviews']);
        $this->assertSame(fullname($student), $this->stat_for((int)$cm->id)['lastusername']);
    }

    /**
     * A page whose pagetype is not a course-view page must never emit the badges
     * bootstrap call, even when every other precondition (capability, preferences,
     * trackable data) is satisfied.
     */
    public function test_wrong_pagetype_is_a_noop(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->insert_user_view($cm->id, $student->id, 1, time());

        set_user_preference(hook_listener::PREF_SHOW_TOTAL, 1, $teacher->id);
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, 1, $teacher->id);
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, 1, $teacher->id);

        $js = $this->run_hook($course, $teacher, 'course-index');

        $this->assertStringNotContainsString('course_badges', $js);
    }

    /**
     * The site course (ID 1) must never emit badges, even for an admin viewing what
     * would otherwise look like a valid course-view page.
     */
    public function test_site_course_is_a_noop(): void {
        $admin = get_admin();
        set_user_preference(hook_listener::PREF_SHOW_TOTAL, 1, $admin->id);
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, 1, $admin->id);
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, 1, $admin->id);

        $js = $this->run_hook(get_site(), $admin, 'course-view-topics');

        $this->assertStringNotContainsString('course_badges', $js);
    }

    /**
     * A user without moodle/course:manageactivities (e.g. a student) must never see
     * badges, regardless of their own preferences.
     */
    public function test_user_without_manageactivities_capability_is_a_noop(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->insert_user_view($cm->id, $student->id, 1, time());

        set_user_preference(hook_listener::PREF_SHOW_TOTAL, 1, $student->id);
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, 1, $student->id);
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, 1, $student->id);

        $js = $this->run_hook($course, $student);

        $this->assertStringNotContainsString('course_badges', $js);
    }

    /**
     * With all three display preferences left at their disabled default, the hook must
     * exit before querying any statistics data.
     */
    public function test_all_preferences_disabled_is_a_noop(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->insert_user_view($cm->id, $student->id, 1, time());

        // Deliberately not calling set_user_preference(): all three default to disabled.
        $js = $this->run_hook($course, $teacher);

        $this->assertStringNotContainsString('course_badges', $js);
    }

    /**
     * A course with no trackable activities (e.g. freshly created, no modules yet) must
     * be a no-op rather than emitting an empty badges call.
     */
    public function test_course_with_no_trackable_activities_is_a_noop(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        set_user_preference(hook_listener::PREF_SHOW_TOTAL, 1, $teacher->id);
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, 1, $teacher->id);
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, 1, $teacher->id);

        $js = $this->run_hook($course, $teacher);

        $this->assertStringNotContainsString('course_badges', $js);
    }

    /**
     * Labels and subsections never fire course_module_viewed, so they must never appear
     * as a badge stat — but they must still be listed in the excludedcmids array passed
     * to the AMD module, which uses it to skip attaching a badge placeholder to them.
     */
    public function test_label_and_subsection_modules_are_excluded_from_tracking(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $pagecm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $label = $generator->create_module('label', ['course' => $course->id]);
        $labelcm = get_coursemodule_from_instance('label', $label->id, $course->id, false, MUST_EXIST);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->insert_user_view($pagecm->id, $student->id, 1, time());

        $this->get_badge_js($course, $teacher);

        $this->assertArrayHasKey((int)$pagecm->id, $this->lastpayload['stats']);
        $this->assertContains((int)$labelcm->id, $this->lastpayload['excluded']);
    }

    /**
     * With the completion preferences on, the payload carries the completion counts and the
     * denominator, alongside (not instead of) the view statistics.
     */
    public function test_completion_data_present_when_preferences_enabled(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $page = $generator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid' => $page->cmid,
            'userid'         => $student->id,
            'completionstate' => COMPLETION_COMPLETE,
            'overrideby'     => null,
            'timemodified'   => time(),
        ]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        set_user_preference(hook_listener::PREF_SHOW_COMPLETED, 1);
        $this->run_hook($course, $teacher);

        $stat = $this->stat_for((int)$page->cmid);

        $this->assertSame(1, $stat['completed']);
        $this->assertSame(1, $stat['trackedtotal']);
        $this->assertFalse($stat['haspass']);
    }

    /**
     * An activity without completion tracking carries no completion fields at all, so the
     * badge can be omitted entirely rather than rendered as a misleading zero.
     */
    public function test_activity_without_completion_carries_no_completion_fields(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $page = $generator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        set_user_preference(hook_listener::PREF_SHOW_COMPLETED, 1);
        $this->run_hook($course, $teacher);

        $this->assertArrayNotHasKey('completed', (array)($this->stat_for((int)$page->cmid) ?? []));
    }

    /**
     * With both completion preferences off, no completion data is gathered at all: the
     * completion queries must not run just because a view badge is enabled.
     */
    public function test_no_completion_data_when_preferences_disabled(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $page = $generator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->insert_user_view($page->cmid, $student->id, 1, time());
        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid' => $page->cmid,
            'userid'         => $student->id,
            'completionstate' => COMPLETION_COMPLETE,
            'overrideby'     => null,
            'timemodified'   => time(),
        ]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        set_user_preference(hook_listener::PREF_SHOW_TOTAL, 1);
        set_user_preference(hook_listener::PREF_SHOW_COMPLETED, 0);
        set_user_preference(hook_listener::PREF_SHOW_PASSED, 0);
        $this->run_hook($course, $teacher);

        $stat = $this->stat_for((int)$page->cmid);

        $this->assertSame(1, $stat['totalviews']);
        $this->assertArrayNotHasKey('completed', $stat);
        $this->assertArrayNotHasKey('trackedtotal', $stat);
    }

    /**
     * The pass badge is flagged only where completion actually requires a passing grade;
     * elsewhere "passed" is not a number that exists.
     */
    public function test_pass_flag_only_when_pass_grade_required(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $graded = $generator->create_module('assign', [
            'course'              => $course->id,
            'completion'          => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade'  => 1,
            'completionpassgrade' => 1,
            'gradepass'           => 50,
        ]);
        $ungraded = $generator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        set_user_preference(hook_listener::PREF_SHOW_PASSED, 1);
        $this->run_hook($course, $teacher);

        $this->assertTrue($this->stat_for((int)$graded->cmid)['haspass']);
        $this->assertFalse($this->stat_for((int)$ungraded->cmid)['haspass']);
    }
}
