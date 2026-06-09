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
        $cmids = [];
        $excludedcmids = [];
        $inlinemodules = ['label', 'subsection'];
        foreach ($modinfo->get_cms() as $cm) {
            // Labels and subsections never fire course_module_viewed.
            if (in_array($cm->modname, $inlinemodules, true)) {
                $excludedcmids[] = (int)$cm->id;
            } else {
                $cmids[] = (int)$cm->id;
            }
        }

        if (empty($cmids)) {
            return;
        }

        $statsmap = new \stdClass();

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');

        $sql = "SELECT v.cmid, v.totalviews, v.uniqueviews, v.lastviewtime,
                       u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {local_resourcestats_views} v
             LEFT JOIN {user} u ON u.id = v.lastuserid
                 WHERE v.cmid $insql";

        $rows = $DB->get_records_sql($sql, $inparams);

        foreach ($rows as $row) {
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
            $statsmap->{$row->cmid} = $stat;
        }

        $PAGE->requires->js_call_amd(
            'local_resourcestats/course_badges',
            'init',
            [$statsmap, $showtotal, $showunique, $showlastuser, $excludedcmids]
        );
    }
}
