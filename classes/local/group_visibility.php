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
 * Shared logic for scoping statistics data to the caller's visible groups.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\local;

use cm_info;
use context_course;
use context_module;

/**
 * Restricts statistics data to the caller's own groups whenever the effective group mode
 * is separate groups and the caller lacks moodle/site:accessallgroups.
 *
 * Every display surface (course_stats, view_stats, export, and the course-view badges)
 * must apply this identically; extracting it here is what stops a new surface from
 * shipping without the same restriction that the others already enforce.
 *
 * @package local_resourcestats
 */
class group_visibility {
    /**
     * Restricts a list of students to the groups the current user may access within a
     * course. No-op unless the course's effective group mode is separate groups and the
     * caller lacks moodle/site:accessallgroups.
     *
     * @param \stdClass[]     $students Candidate students, indexed by userid.
     * @param \stdClass       $course   The course record.
     * @param context_course $context  The course context.
     * @return \stdClass[] Same shape as $students, filtered to the caller's visible groups.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function restrict_students_by_course(array $students, \stdClass $course, context_course $context): array {
        if ((int)groups_get_course_groupmode($course) !== SEPARATEGROUPS) {
            return $students;
        }

        if (has_capability('moodle/site:accessallgroups', $context)) {
            return $students;
        }

        $mygroupids = self::get_my_groupids($course->id, $course->defaultgroupingid);
        if (empty($mygroupids)) {
            return [];
        }

        $visible = get_enrolled_users($context, '', $mygroupids, 'u.id');

        return array_intersect_key($students, $visible);
    }

    /**
     * Restricts a list of students to the groups the current user may access within a
     * specific activity. No-op unless the activity's effective group mode is separate
     * groups and the caller lacks moodle/site:accessallgroups.
     *
     * @param \stdClass[]     $students      Candidate students, indexed by userid.
     * @param cm_info         $cm            The course module.
     * @param context_module  $modcontext    The module context.
     * @param context_course $coursecontext The course context (for the enrolment query).
     * @return \stdClass[] Same shape as $students, filtered to the caller's visible groups.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function restrict_students_by_activity(
        array $students,
        cm_info $cm,
        context_module $modcontext,
        context_course $coursecontext
    ): array {
        $mygroupids = self::get_activity_group_restriction($cm, $modcontext);
        if ($mygroupids === null) {
            return $students;
        }

        if (empty($mygroupids)) {
            return [];
        }

        $visible = get_enrolled_users($coursecontext, '', $mygroupids, 'u.id');

        return array_intersect_key($students, $visible);
    }

    /**
     * Returns whether the current user is restricted to specific groups for an activity,
     * and if so, which group IDs.
     *
     * @param cm_info        $cm         The course module.
     * @param context_module $modcontext The module context.
     * @return int[]|null Null when unrestricted (not separate groups, or the caller holds
     *                     moodle/site:accessallgroups); otherwise the caller's own group
     *                     IDs (an empty array means the caller belongs to no group and
     *                     must see nothing).
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_activity_group_restriction(cm_info $cm, context_module $modcontext): ?array {
        if ((int)groups_get_activity_groupmode($cm) !== SEPARATEGROUPS) {
            return null;
        }

        if (has_capability('moodle/site:accessallgroups', $modcontext)) {
            return null;
        }

        return self::get_my_groupids($cm->course, $cm->groupingid);
    }

    /**
     * Returns the current user's own group IDs within a grouping.
     *
     * @param int $courseid   Course ID.
     * @param int $groupingid Grouping ID (0 = default grouping).
     * @return int[]
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private static function get_my_groupids(int $courseid, int $groupingid): array {
        global $USER;

        return array_keys(groups_get_all_groups($courseid, $USER->id, $groupingid, 'g.id'));
    }
}
