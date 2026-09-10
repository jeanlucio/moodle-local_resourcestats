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
 * Shared logic for counting activity completion, read from core's completion data.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\local;

use cm_info;
use context_course;
use core_completion\activity_custom_completion;

/**
 * Counts how many students completed (and passed) each activity.
 *
 * Unlike the view statistics, which the plugin collects itself from its own event observer,
 * completion is core's data: it is read live from {course_modules_completion} and therefore
 * already covers the whole history of the course, including before this plugin was installed.
 *
 * Two rules from core are deliberately mirrored rather than reinvented, because the numbers
 * shown here are the ones a teacher will compare against core's own "Activity completion"
 * report (report_progress) and against the completion ticks on the course page itself:
 *
 * - Who counts (the denominator): users the course tracks for completion, i.e. holders of
 *   moodle/course:isincompletionreports with an active enrolment — the same population
 *   completion_info::get_num_tracked_users() counts. That method takes a single group id,
 *   while a teacher may belong to several groups, so the enrolment SQL it builds internally
 *   is used directly here instead.
 * - What counts as completed: see get_complete_states(), which mirrors
 *   \core_completion\cm_completion_details::is_overall_complete(). A plain
 *   "completionstate > 0" would overcount every activity that requires a passing grade.
 *
 * @package local_resourcestats
 */
class completion_stats {
    /** @var string Capability that defines whose completion the course tracks. */
    const TRACKED_CAPABILITY = 'moodle/course:isincompletionreports';

    /**
     * Returns whether completion tracking is enabled for an activity.
     *
     * Delegates to core rather than reading $cm->completion directly: completion can be
     * switched off for the whole site or for the course while the activities keep whatever
     * value they had, and in that state core reports them as not tracked. Reading the module
     * field alone would show completion figures for a course that no longer tracks any.
     *
     * @param cm_info $cm The course module.
     * @return bool
     */
    public static function is_enabled(cm_info $cm): bool {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $completion = new \completion_info($cm->get_course());

        return (int)$completion->is_enabled($cm) !== COMPLETION_TRACKING_NONE;
    }

    /**
     * Returns whether an activity requires a passing grade in order to be completed.
     *
     * This is the only condition under which "passed" is a meaningful number: without it,
     * core never records COMPLETION_COMPLETE_PASS for the activity at all.
     *
     * @param cm_info $cm The course module.
     * @return bool
     */
    public static function has_pass_criterion(cm_info $cm): bool {
        return !empty($cm->completionpassgrade);
    }

    /**
     * Returns the completion states that mean "this student completed the activity".
     *
     * Mirrors \core_completion\cm_completion_details::is_overall_complete(): the set depends
     * on how the activity is configured, so it cannot be decided once for the whole course.
     * COMPLETION_COMPLETE_FAIL means "did the activity but failed it", which counts as
     * completed only where completion does not require a passing grade.
     *
     * COMPLETION_COMPLETE_FAIL_HIDDEN is deliberately absent: core only produces it when the
     * activity requires a passing grade, and completion_info::update_state() rewrites it to
     * COMPLETION_INCOMPLETE before storing, so it never reaches the database.
     *
     * @param cm_info $cm The course module.
     * @return int[] Completion state constants.
     */
    public static function get_complete_states(cm_info $cm): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if ((int)$cm->completion === COMPLETION_TRACKING_MANUAL) {
            return [COMPLETION_COMPLETE];
        }

        if (self::has_custom_rules($cm) || self::has_pass_criterion($cm)) {
            return [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS];
        }

        return [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL];
    }

    /**
     * Returns completion counts for a set of course modules.
     *
     * Activities are bucketed by the caller's group restriction and fetched with one pair of
     * queries per bucket, never one per activity: a course can be "No groups" while a single
     * activity overrides that to "Separate groups", so each activity's own effective group
     * mode is resolved independently (the same rule every other surface of this plugin
     * applies via group_visibility).
     *
     * @param context_course $coursecontext The course context.
     * @param cm_info[]      $cms           Course modules to count, indexed by course module ID.
     * @param array          $groupidscache Memoisation cache for the group restriction lookup,
     *                                      passed by reference; see
     *                                      group_visibility::get_activity_group_restriction().
     * @return \stdClass[] Indexed by course module ID, one entry per activity that has
     *                      completion enabled (activities without it are absent, not zeroed).
     *                      Each entry has completed, passed, total (int) and haspass (bool).
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_stats_for_modules(
        context_course $coursecontext,
        array $cms,
        array &$groupidscache = []
    ): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $stats = [];
        $buckets = [];

        foreach ($cms as $cm) {
            if (!self::is_enabled($cm)) {
                continue;
            }

            $cmid = (int)$cm->id;
            $stats[$cmid] = (object)[
                'completed' => 0,
                'passed'    => 0,
                'total'     => 0,
                'haspass'   => self::has_pass_criterion($cm),
            ];

            $restriction = group_visibility::get_activity_group_restriction($cm, $cm->context, $groupidscache);
            if (is_array($restriction) && empty($restriction)) {
                // Caller belongs to no group here, so there is nothing visible and nothing to query.
                continue;
            }

            $key = $restriction === null ? '' : implode(',', $restriction);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['groupids' => $restriction ?? 0, 'cmids' => []];
            }
            $buckets[$key]['cmids'][] = $cmid;
        }

        foreach ($buckets as $bucket) {
            [$enrolledsql, $enrolledparams] = get_enrolled_sql(
                $coursecontext,
                self::TRACKED_CAPABILITY,
                $bucket['groupids'],
                true
            );

            $total = $DB->count_records_sql("SELECT COUNT(1) FROM ($enrolledsql) eu", $enrolledparams);
            $counts = self::fetch_state_counts($bucket['cmids'], $enrolledsql, $enrolledparams);

            foreach ($bucket['cmids'] as $cmid) {
                $bystate = $counts[$cmid] ?? [];
                $stats[$cmid]->total = $total;
                $stats[$cmid]->passed = $bystate[COMPLETION_COMPLETE_PASS] ?? 0;

                foreach (self::get_complete_states($cms[$cmid]) as $state) {
                    $stats[$cmid]->completed += $bystate[$state] ?? 0;
                }
            }
        }

        return $stats;
    }

    /**
     * Returns the stored completion state of each user on each of the given activities.
     *
     * @param int[] $cmids Course module IDs.
     * @return array Completion states keyed by course module ID, then by user ID. A user with
     *                no stored row is simply absent, which means incomplete.
     * @throws \dml_exception
     */
    public static function get_user_states(array $cmids): array {
        global $DB;

        if (empty($cmids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');

        $states = [];
        $rows = $DB->get_recordset_select(
            'course_modules_completion',
            "coursemoduleid $insql",
            $inparams,
            '',
            'id, coursemoduleid, userid, completionstate'
        );
        foreach ($rows as $row) {
            $states[(int)$row->coursemoduleid][(int)$row->userid] = (int)$row->completionstate;
        }
        $rows->close();

        return $states;
    }

    /**
     * Returns the IDs of the users the course tracks for completion.
     *
     * Needed to tell "has not completed it" apart from "was never tracked in the first
     * place": the two look identical in the completion table, where both are simply the
     * absence of a row, but only the first is a statement about the student.
     *
     * @param context_course $coursecontext The course context.
     * @param int[]|int      $groupids      Group IDs to restrict to, or 0 for no restriction.
     * @return array User IDs as keys, for direct lookup.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_tracked_userids(context_course $coursecontext, $groupids = 0): array {
        $users = get_enrolled_users($coursecontext, self::TRACKED_CAPABILITY, $groupids, 'u.id');

        return array_fill_keys(array_keys($users), true);
    }

    /**
     * Returns the language string key describing one student's situation on one activity.
     *
     * @param cm_info  $cm        The course module.
     * @param int|null $state     The stored completion state, or null when there is no row.
     * @param bool     $istracked Whether the course tracks this student for completion.
     * @return string A local_resourcestats string key.
     */
    public static function describe_state(cm_info $cm, ?int $state, bool $istracked): string {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if (!$istracked) {
            return 'completion_state_nottracked';
        }

        if ($state === COMPLETION_COMPLETE_PASS) {
            return 'completion_state_passed';
        }

        if ($state === COMPLETION_COMPLETE_FAIL) {
            return 'completion_state_failed';
        }

        if ($state !== null && in_array($state, self::get_complete_states($cm), true)) {
            return 'completion_state_completed';
        }

        return 'completion_state_notcompleted';
    }

    /**
     * Fetches completion state counts for a set of course modules in a single query.
     *
     * @param int[]  $cmids           Course module IDs.
     * @param string $enrolledsql     Subquery returning the IDs of the users who count.
     * @param array  $enrolledparams  Parameters for $enrolledsql.
     * @return array Counts keyed by course module ID, then by completion state.
     * @throws \dml_exception
     */
    private static function fetch_state_counts(array $cmids, string $enrolledsql, array $enrolledparams): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');

        $sql = "SELECT cmc.coursemoduleid, cmc.completionstate, COUNT(cmc.userid) AS num
                  FROM {course_modules_completion} cmc
                  JOIN ($enrolledsql) eu ON eu.id = cmc.userid
                 WHERE cmc.coursemoduleid $insql
              GROUP BY cmc.coursemoduleid, cmc.completionstate";

        $counts = [];
        $rows = $DB->get_recordset_sql($sql, array_merge($enrolledparams, $inparams));
        foreach ($rows as $row) {
            $counts[(int)$row->coursemoduleid][(int)$row->completionstate] = (int)$row->num;
        }
        $rows->close();

        return $counts;
    }

    /**
     * Returns whether an activity defines at least one enabled custom completion rule.
     *
     * Mirrors the check completion_info::get_data() makes before evaluating custom rules:
     * the module must both declare enabled rules and ship the class that evaluates them.
     *
     * @param cm_info $cm The course module.
     * @return bool
     */
    private static function has_custom_rules(cm_info $cm): bool {
        $customdata = (array)$cm->get_custom_data();
        $rules = $customdata['customcompletionrules'] ?? [];

        if (empty($rules) || !is_array($rules)) {
            return false;
        }

        if (!activity_custom_completion::get_cm_completion_class($cm->modname)) {
            return false;
        }

        foreach ($rules as $enabled) {
            if (!empty($enabled)) {
                return true;
            }
        }

        return false;
    }
}
