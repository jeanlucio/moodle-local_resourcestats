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
 * Step definitions for local_resourcestats Behat tests.
 *
 * @package    local_resourcestats
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

/**
 * Custom Behat step definitions for local_resourcestats.
 */
class behat_local_resourcestats extends behat_base {
    /**
     * Navigates directly to the course statistics overview page.
     *
     * The "Course statistics" link lives in the course's secondary navigation, which
     * collapses into a "More" dropdown once the tab bar is full — whether that happens
     * depends on the exact set of tabs and the browser's viewport width, neither of which
     * this plugin controls or is trying to test here. Navigating straight to the URL keeps
     * these scenarios focused on what this plugin actually renders.
     *
     * @param string $shortname Course shortname.
     * @When I open the course statistics page for course :shortname
     */
    public function i_open_the_course_statistics_page(string $shortname): void {
        global $DB, $CFG;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);

        $this->getSession()->visit($CFG->wwwroot . '/local/resourcestats/course_stats.php?courseid=' . $course->id);
    }

    /**
     * Records the given number of accesses by a student against an activity, writing
     * directly to this plugin's own statistics tables — the same shape of write the real
     * observer performs on a course_module_viewed event, without needing a real page view
     * per access. Given (setup) steps must not drive the UI.
     *
     * @param string $username     Moodle username of the student.
     * @param int    $count        Number of accesses to record.
     * @param string $activityname Display name of the activity, as configured in the course.
     * @param string $shortname    Course shortname.
     * @Given :username has :count Resource Stats accesses on :activityname in course :shortname
     */
    public function user_has_accessed_activity(
        string $username,
        int $count,
        string $activityname,
        string $shortname
    ): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);

        $modinfo = get_fast_modinfo($course);
        $cm = null;
        foreach ($modinfo->get_cms() as $candidate) {
            if ($candidate->name === $activityname) {
                $cm = $candidate;
                break;
            }
        }
        if ($cm === null) {
            throw new \Exception("Activity \"{$activityname}\" not found in course \"{$shortname}\".");
        }

        $now = time();
        $userrow = $DB->get_record('local_resourcestats_user_views', ['cmid' => $cm->id, 'userid' => $user->id]);
        $isnewuser = $userrow === false;

        if ($isnewuser) {
            $DB->insert_record('local_resourcestats_user_views', (object) [
                'cmid' => $cm->id, 'userid' => $user->id, 'viewcount' => $count,
                'firstviewtime' => $now, 'lastviewtime' => $now,
            ]);
        } else {
            $userrow->viewcount += $count;
            $userrow->lastviewtime = $now;
            $DB->update_record('local_resourcestats_user_views', $userrow);
        }

        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $cm->id]);
        if ($aggregate) {
            $aggregate->totalviews += $count;
            if ($isnewuser) {
                $aggregate->uniqueviews += 1;
            }
            $aggregate->lastuserid = $user->id;
            $aggregate->lastviewtime = $now;
            $DB->update_record('local_resourcestats_views', $aggregate);
        } else {
            $DB->insert_record('local_resourcestats_views', (object) [
                'cmid' => $cm->id, 'totalviews' => $count, 'uniqueviews' => 1,
                'lastuserid' => $user->id, 'lastviewtime' => $now,
                'deletedviews' => 0, 'deletedcount' => 0,
            ]);
        }
    }
}
