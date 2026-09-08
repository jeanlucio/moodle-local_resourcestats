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
 * Hook listener for injecting course badges via AMD.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats;

use context_course;
use core\hook\output\before_standard_footer_html_generation;
use local_resourcestats\local\group_visibility;

/**
 * Hook listener class.
 *
 * @package local_resourcestats
 */
class hook_listener {
    /** @var string User preference key for showing the total-accesses badge. */
    const PREF_SHOW_TOTAL = 'local_resourcestats_show_total';

    /** @var string User preference key for showing the unique-students badge. */
    const PREF_SHOW_UNIQUE = 'local_resourcestats_show_unique';

    /** @var string User preference key for showing the last-user badge. */
    const PREF_SHOW_LASTUSER = 'local_resourcestats_show_lastuser';

    /**
     * Returns whether the total-accesses badge should be shown for the current user.
     *
     * Falls back to the admin-configured site default, then to true if unset.
     *
     * @return bool
     */
    public static function get_pref_show_total(): bool {
        $cfgdefault = get_config('local_resourcestats', 'default_show_total');
        $default = ($cfgdefault !== false) ? $cfgdefault : '0';
        return (bool) get_user_preferences(self::PREF_SHOW_TOTAL, $default);
    }

    /**
     * Returns whether the unique-students badge should be shown for the current user.
     *
     * Falls back to the admin-configured site default, then to false if unset.
     *
     * @return bool
     */
    public static function get_pref_show_unique(): bool {
        $cfgdefault = get_config('local_resourcestats', 'default_show_unique');
        $default = ($cfgdefault !== false) ? $cfgdefault : '0';
        return (bool) get_user_preferences(self::PREF_SHOW_UNIQUE, $default);
    }

    /**
     * Returns whether the last-user badge should be shown for the current user.
     *
     * Falls back to the admin-configured site default, then to false if unset.
     *
     * @return bool
     */
    public static function get_pref_show_lastuser(): bool {
        $cfgdefault = get_config('local_resourcestats', 'default_show_lastuser');
        $default = ($cfgdefault !== false) ? $cfgdefault : '0';
        return (bool) get_user_preferences(self::PREF_SHOW_LASTUSER, $default);
    }

    /**
     * Injects course statistics badges by queuing an AMD module call.
     *
     * Reads the teacher's display preferences. If all three are disabled,
     * exits immediately with zero cost. Otherwise loads all module stats
     * in a single query and passes them to the AMD module along with the
     * three boolean flags.
     *
     * @param before_standard_footer_html_generation $hook The hook instance.
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public static function inject_course_badges(before_standard_footer_html_generation $hook): void {
        global $DB, $PAGE, $USER;

        if (!str_starts_with($PAGE->pagetype, 'course-view-')) {
            return;
        }

        $course = $PAGE->course;
        if (!$course || $course->id <= 1) {
            return;
        }

        $coursecontext = context_course::instance($course->id, IGNORE_MISSING);
        if (!$coursecontext) {
            return;
        }

        if (!has_capability('moodle/course:manageactivities', $coursecontext, $USER->id)) {
            return;
        }

        $showtotal = self::get_pref_show_total();
        $showunique = self::get_pref_show_unique();
        $showlastuser = self::get_pref_show_lastuser();

        if (!$showtotal && !$showunique && !$showlastuser) {
            return;
        }

        $modinfo = get_fast_modinfo($course);
        $cmsbyid = [];
        $cmids = [];
        $excludedcmids = [];
        $inlinemodules = ['label', 'subsection'];
        foreach ($modinfo->get_cms() as $cm) {
            // Labels and subsections never fire course_module_viewed.
            if (in_array($cm->modname, $inlinemodules, true)) {
                $excludedcmids[] = (int)$cm->id;
            } else {
                $cmids[] = (int)$cm->id;
                $cmsbyid[(int)$cm->id] = $cm;
            }
        }

        if (empty($cmids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');

        $sql = "SELECT v.cmid, v.totalviews, v.uniqueviews, v.lastviewtime, v.lastuserid,
                       u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {local_resourcestats_views} v
             LEFT JOIN {user} u ON u.id = v.lastuserid
                 WHERE v.cmid $insql";

        $rows = $DB->get_records_sql($sql, $inparams);

        $userviewrows = $DB->get_records_select(
            'local_resourcestats_user_views',
            "cmid $insql",
            $inparams,
            '',
            'id, cmid, userid, viewcount, lastviewtime'
        );
        $userviewsbycmid = [];
        foreach ($userviewrows as $uvrow) {
            $userviewsbycmid[$uvrow->cmid][] = $uvrow;
        }

        $statsmap = new \stdClass();
        $visiblenamesbygroupids = [];
        $groupidscache = [];

        foreach ($rows as $row) {
            $cmid = (int)$row->cmid;
            $cm = $cmsbyid[$cmid] ?? null;
            $restriction = $cm
                ? group_visibility::get_activity_group_restriction($cm, $cm->context, $groupidscache)
                : null;

            if ($restriction === null) {
                $stat = self::build_stat_from_aggregate($row);
            } else {
                $stat = self::build_stat_from_visible_group(
                    $restriction,
                    $userviewsbycmid[$cmid] ?? [],
                    $coursecontext,
                    $visiblenamesbygroupids
                );
            }

            $statsmap->{$row->cmid} = $stat;
        }

        $PAGE->requires->js_call_amd(
            'local_resourcestats/course_badges',
            'init',
            [$statsmap, $showtotal, $showunique, $showlastuser, $excludedcmids]
        );
    }

    /**
     * Builds a badge stat object from the plugin's course-wide aggregate row.
     *
     * Used whenever the caller is not restricted to specific groups for this activity.
     *
     * @param \stdClass $row One row from local_resourcestats_views, left-joined with {user}.
     * @return \stdClass Object with totalviews, uniqueviews, lastusername.
     */
    private static function build_stat_from_aggregate(\stdClass $row): \stdClass {
        $stat = new \stdClass();
        $stat->totalviews = (int)$row->totalviews;
        $stat->uniqueviews = (int)$row->uniqueviews;
        $stat->lastusername = '';

        if (!empty($row->firstname) || !empty($row->lastname)) {
            $fakeuser = (object)[
                'firstname'         => $row->firstname ?? '',
                'lastname'          => $row->lastname ?? '',
                'firstnamephonetic' => $row->firstnamephonetic ?? '',
                'lastnamephonetic'  => $row->lastnamephonetic ?? '',
                'middlename'        => $row->middlename ?? '',
                'alternatename'     => $row->alternatename ?? '',
            ];
            $stat->lastusername = fullname($fakeuser);
        }

        return $stat;
    }

    /**
     * Builds a badge stat object scoped to the caller's own groups.
     *
     * Recomputes totalviews/uniqueviews and the last viewer's name from the per-student
     * rows rather than the course-wide aggregate, so a restricted caller never sees a
     * total, count, or name derived from a group they cannot access.
     *
     * @param int[]           $mygroupids             The caller's own group IDs for this
     *                                                 activity (empty means no visible group).
     * @param \stdClass[]     $userviewrows           This cmid's rows from
     *                                                 local_resourcestats_user_views.
     * @param context_course  $coursecontext          The course context.
     * @param array           $visiblenamesbygroupids Memoisation cache keyed by the
     *                                                 imploded group ID list, populated
     *                                                 across calls for the same request.
     * @return \stdClass Object with totalviews, uniqueviews, lastusername.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private static function build_stat_from_visible_group(
        array $mygroupids,
        array $userviewrows,
        context_course $coursecontext,
        array &$visiblenamesbygroupids
    ): \stdClass {
        $stat = new \stdClass();
        $stat->totalviews = 0;
        $stat->uniqueviews = 0;
        $stat->lastusername = '';

        if (empty($mygroupids)) {
            return $stat;
        }

        $groupkey = implode(',', $mygroupids);
        if (!array_key_exists($groupkey, $visiblenamesbygroupids)) {
            $visiblenamesbygroupids[$groupkey] = get_enrolled_users(
                $coursecontext,
                '',
                $mygroupids,
                'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename'
            );
        }
        $visibleusers = $visiblenamesbygroupids[$groupkey];

        $lastviewtime = 0;
        $lastuserid = 0;
        foreach ($userviewrows as $uvrow) {
            if (!isset($visibleusers[$uvrow->userid])) {
                continue;
            }
            $stat->totalviews += (int)$uvrow->viewcount;
            $stat->uniqueviews++;
            if ((int)$uvrow->lastviewtime > $lastviewtime) {
                $lastviewtime = (int)$uvrow->lastviewtime;
                $lastuserid = (int)$uvrow->userid;
            }
        }

        if ($lastuserid > 0) {
            $stat->lastusername = fullname($visibleusers[$lastuserid]);
        }

        return $stat;
    }
}
