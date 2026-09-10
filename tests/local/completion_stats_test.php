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
 * PHPUnit tests for the completion statistics helper.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\local;

use advanced_testcase;
use cm_info;
use context_course;

/**
 * Test cases for local_resourcestats\local\completion_stats.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\local\completion_stats
 */
final class completion_stats_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
    }

    /**
     * Creates a course with completion enabled.
     *
     * @return \stdClass
     */
    private function create_course(): \stdClass {
        return $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
    }

    /**
     * Enrols a user in a course with the given role.
     *
     * @param \stdClass $course   The course.
     * @param string    $rolename Role shortname.
     * @return \stdClass The user.
     */
    private function enrol(\stdClass $course, string $rolename = 'student'): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $rolename);

        return $user;
    }

    /**
     * Stores a completion state for a user on an activity.
     *
     * Written directly rather than through completion_info::update_state() so a specific
     * stored state can be asserted against, including states core would only produce from
     * a particular grade configuration.
     *
     * @param int $cmid   Course module ID.
     * @param int $userid User ID.
     * @param int $state  Completion state constant.
     */
    private function set_state(int $cmid, int $userid, int $state): void {
        global $DB;

        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid' => $cmid,
            'userid'         => $userid,
            'completionstate' => $state,
            'overrideby'     => null,
            'timemodified'   => time(),
        ]);
    }

    /**
     * Returns the cm_info for a module, indexed by course module ID as the helper expects.
     *
     * @param \stdClass $course     The course.
     * @param \stdClass ...$modules Module records from the generator.
     * @return cm_info[]
     */
    private function cms(\stdClass $course, \stdClass ...$modules): array {
        $modinfo = get_fast_modinfo($course);
        $cms = [];
        foreach ($modules as $module) {
            $cms[(int)$module->cmid] = $modinfo->get_cm($module->cmid);
        }

        return $cms;
    }

    /**
     * Manual completion counts only COMPLETION_COMPLETE: there is no notion of passing.
     */
    public function test_complete_states_for_manual_completion(): void {
        $course = $this->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);

        $this->assertSame([COMPLETION_COMPLETE], completion_stats::get_complete_states($cm));
        $this->assertFalse(completion_stats::has_pass_criterion($cm));
    }

    /**
     * Automatic completion that requires a passing grade must not count a failing state as
     * completed — mirroring cm_completion_details::is_overall_complete().
     */
    public function test_complete_states_when_pass_grade_required(): void {
        $course = $this->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'              => $course->id,
            'completion'          => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade'  => 1,
            'completionpassgrade' => 1,
            'gradepass'           => 50,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($assign->cmid);

        $states = completion_stats::get_complete_states($cm);

        $this->assertTrue(completion_stats::has_pass_criterion($cm));
        $this->assertContains(COMPLETION_COMPLETE_PASS, $states);
        $this->assertNotContains(COMPLETION_COMPLETE_FAIL, $states);
    }

    /**
     * Automatic completion that only requires a grade counts a failing state as completed:
     * the student did the activity, which is what the condition asks for.
     */
    public function test_complete_states_when_any_grade_suffices(): void {
        $course = $this->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'             => $course->id,
            'completion'         => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($assign->cmid);

        $states = completion_stats::get_complete_states($cm);

        $this->assertFalse(completion_stats::has_pass_criterion($cm));
        $this->assertContains(COMPLETION_COMPLETE_FAIL, $states);
    }

    /**
     * An activity without completion tracking is absent from the result entirely, rather
     * than present with a zero count — a zero would read as "nobody completed it".
     */
    public function test_activity_without_completion_is_absent(): void {
        $course = $this->create_course();
        $tracked = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $untracked = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $stats = completion_stats::get_stats_for_modules(
            context_course::instance($course->id),
            $this->cms($course, $tracked, $untracked)
        );

        $this->assertArrayHasKey((int)$tracked->cmid, $stats);
        $this->assertArrayNotHasKey((int)$untracked->cmid, $stats);
    }

    /**
     * The denominator counts the students the course tracks for completion, and no one else:
     * a teacher is enrolled but never appears in completion reports.
     */
    public function test_denominator_counts_only_tracked_students(): void {
        $course = $this->create_course();
        $student1 = $this->enrol($course);
        $student2 = $this->enrol($course);
        $this->enrol($course, 'editingteacher');

        $page = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $this->set_state($page->cmid, $student1->id, COMPLETION_COMPLETE);
        $this->set_state($page->cmid, $student2->id, COMPLETION_INCOMPLETE);

        $stats = completion_stats::get_stats_for_modules(
            context_course::instance($course->id),
            $this->cms($course, $page)
        );

        $this->assertSame(2, $stats[(int)$page->cmid]->total);
        $this->assertSame(1, $stats[(int)$page->cmid]->completed);
    }

    /**
     * Counting a graded activity: passes count as completed and are also reported on their
     * own, while a failing state counts as neither when a pass grade is required.
     */
    public function test_counts_pass_and_fail_when_pass_grade_required(): void {
        $course = $this->create_course();
        $passed = $this->enrol($course);
        $failed = $this->enrol($course);
        $this->enrol($course);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'              => $course->id,
            'completion'          => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade'  => 1,
            'completionpassgrade' => 1,
            'gradepass'           => 50,
        ]);
        $this->set_state($assign->cmid, $passed->id, COMPLETION_COMPLETE_PASS);
        $this->set_state($assign->cmid, $failed->id, COMPLETION_COMPLETE_FAIL);

        $stats = completion_stats::get_stats_for_modules(
            context_course::instance($course->id),
            $this->cms($course, $assign)
        );
        $stat = $stats[(int)$assign->cmid];

        $this->assertSame(3, $stat->total);
        $this->assertSame(1, $stat->completed);
        $this->assertSame(1, $stat->passed);
        $this->assertTrue($stat->haspass);
    }

    /**
     * The same failing state counts as completed once the activity stops requiring a pass.
     */
    public function test_failed_state_counts_when_pass_grade_not_required(): void {
        $course = $this->create_course();
        $failed = $this->enrol($course);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'             => $course->id,
            'completion'         => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $this->set_state($assign->cmid, $failed->id, COMPLETION_COMPLETE_FAIL);

        $stats = completion_stats::get_stats_for_modules(
            context_course::instance($course->id),
            $this->cms($course, $assign)
        );

        $this->assertSame(1, $stats[(int)$assign->cmid]->completed);
        $this->assertFalse($stats[(int)$assign->cmid]->haspass);
    }

    /**
     * The aggregate must agree, student by student, with the API core itself uses to decide
     * whether an activity shows as done — the guarantee that the badge never contradicts the
     * completion ticks rendered on the same course page.
     */
    public function test_completed_count_agrees_with_core_per_user(): void {
        $course = $this->create_course();
        $students = [$this->enrol($course), $this->enrol($course), $this->enrol($course)];

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'             => $course->id,
            'completion'         => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $this->set_state($assign->cmid, $students[0]->id, COMPLETION_COMPLETE_PASS);
        $this->set_state($assign->cmid, $students[1]->id, COMPLETION_COMPLETE_FAIL);
        $this->set_state($assign->cmid, $students[2]->id, COMPLETION_INCOMPLETE);

        $cm = get_fast_modinfo($course)->get_cm($assign->cmid);
        $expected = 0;
        foreach ($students as $student) {
            $details = \core_completion\cm_completion_details::get_instance($cm, (int)$student->id);
            if ($details->is_overall_complete()) {
                $expected++;
            }
        }

        // Anchored so the comparison below cannot pass with both sides at zero: with no pass
        // grade required, core treats both the passing and the failing student as complete.
        $this->assertSame(2, $expected);

        $stats = completion_stats::get_stats_for_modules(
            context_course::instance($course->id),
            [(int)$assign->cmid => $cm]
        );

        $this->assertSame($expected, $stats[(int)$assign->cmid]->completed);
    }

    /**
     * A teacher restricted to separate groups sees only their own group's numbers, in both
     * the count and the denominator — the same rule every other surface of the plugin applies.
     */
    public function test_group_restriction_scopes_counts_and_denominator(): void {
        global $DB;

        $course = $this->create_course();
        $teacher = $this->enrol($course, 'teacher');
        $mine = $this->enrol($course);
        $theirs = $this->enrol($course);

        $mygroup = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $othergroup = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $mygroup->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $mygroup->id, 'userid' => $mine->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $othergroup->id, 'userid' => $theirs->id]);

        $page = $this->getDataGenerator()->create_module('page', [
            'course'      => $course->id,
            'completion'  => COMPLETION_TRACKING_MANUAL,
            'groupmode'   => SEPARATEGROUPS,
        ]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $this->set_state($page->cmid, $mine->id, COMPLETION_COMPLETE);
        $this->set_state($page->cmid, $theirs->id, COMPLETION_COMPLETE);

        $this->setUser($teacher);
        $stats = completion_stats::get_stats_for_modules(
            context_course::instance($course->id),
            $this->cms($course, $page)
        );

        $this->assertSame(1, $stats[(int)$page->cmid]->completed);
        $this->assertSame(1, $stats[(int)$page->cmid]->total);
    }

    /**
     * Completion switched off at course level hides the figures even though the activities
     * still carry their old completion setting: core reports them as untracked, and so must
     * this. Reading the module's own field alone would report completion for a course that
     * no longer tracks any.
     */
    public function test_completion_disabled_on_the_course_hides_everything(): void {
        global $DB;

        $course = $this->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->enrol($course);
        $this->set_state($page->cmid, $student->id, COMPLETION_COMPLETE);

        // Turn completion off for the course after the fact, as an teacher would in the UI.
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);
        $course = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);

        $cm = get_fast_modinfo($course)->get_cm($page->cmid);

        $this->assertFalse(completion_stats::is_enabled($cm));
        $this->assertSame([], completion_stats::get_stats_for_modules(
            context_course::instance($course->id),
            [(int)$page->cmid => $cm]
        ));
    }

    /**
     * The per-user lookup returns each student's stored state, keyed by activity and user,
     * and simply omits students who have no row.
     */
    public function test_get_user_states_returns_stored_states(): void {
        $course = $this->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $done = $this->enrol($course);
        $notdone = $this->enrol($course);
        $this->set_state($page->cmid, $done->id, COMPLETION_COMPLETE);

        $states = completion_stats::get_user_states([(int)$page->cmid]);

        $this->assertSame(COMPLETION_COMPLETE, $states[(int)$page->cmid][(int)$done->id]);
        $this->assertArrayNotHasKey((int)$notdone->id, $states[(int)$page->cmid]);
        $this->assertSame([], completion_stats::get_user_states([]));
    }

    /**
     * The tracked-user set is the population the denominator counts: students, not teachers.
     */
    public function test_get_tracked_userids_covers_students_only(): void {
        $course = $this->create_course();
        $student = $this->enrol($course);
        $teacher = $this->enrol($course, 'editingteacher');

        $tracked = completion_stats::get_tracked_userids(context_course::instance($course->id));

        $this->assertArrayHasKey((int)$student->id, $tracked);
        $this->assertArrayNotHasKey((int)$teacher->id, $tracked);
    }

    /**
     * The per-student label reports what actually happened to that student, which is not the
     * same question as whether it counted towards the completion total: on an activity that
     * does not require a passing grade, a failing grade still counts as completed, and the
     * student is still told they did not pass.
     */
    public function test_describe_state_labels_each_situation(): void {
        $course = $this->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'             => $course->id,
            'completion'         => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($assign->cmid);

        $this->assertSame(
            'completion_state_nottracked',
            completion_stats::describe_state($cm, COMPLETION_COMPLETE, false)
        );
        $this->assertSame(
            'completion_state_passed',
            completion_stats::describe_state($cm, COMPLETION_COMPLETE_PASS, true)
        );
        $this->assertSame(
            'completion_state_failed',
            completion_stats::describe_state($cm, COMPLETION_COMPLETE_FAIL, true)
        );
        $this->assertSame(
            'completion_state_completed',
            completion_stats::describe_state($cm, COMPLETION_COMPLETE, true)
        );
        $this->assertSame(
            'completion_state_notcompleted',
            completion_stats::describe_state($cm, null, true)
        );
        $this->assertSame(
            'completion_state_notcompleted',
            completion_stats::describe_state($cm, COMPLETION_INCOMPLETE, true)
        );
    }

    /**
     * A teacher restricted to a group sees a tracked-user set scoped to that group, which is
     * what keeps the denominator from leaking the size of another group.
     */
    public function test_get_tracked_userids_honours_group_restriction(): void {
        $course = $this->create_course();
        $mine = $this->enrol($course);
        $theirs = $this->enrol($course);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $mine->id]);

        $tracked = completion_stats::get_tracked_userids(
            context_course::instance($course->id),
            [(int)$group->id]
        );

        $this->assertArrayHasKey((int)$mine->id, $tracked);
        $this->assertArrayNotHasKey((int)$theirs->id, $tracked);
    }

    /**
     * An activity with its own custom completion rules excludes the failing state, the same
     * way core does: a failed custom rule is not a completed activity.
     */
    public function test_custom_completion_rules_exclude_the_failing_state(): void {
        $course = $this->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'            => $course->id,
            'completion'        => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit'  => 1,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($assign->cmid);

        $states = completion_stats::get_complete_states($cm);

        $this->assertContains(COMPLETION_COMPLETE, $states);
        $this->assertContains(COMPLETION_COMPLETE_PASS, $states);
        $this->assertNotContains(COMPLETION_COMPLETE_FAIL, $states);
    }
}
