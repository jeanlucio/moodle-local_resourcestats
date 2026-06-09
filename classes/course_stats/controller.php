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
 * Controller for the course statistics overview page.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\course_stats;

use context_course;
use moodle_url;

/**
 * Controller for the course-level statistics page.
 *
 * @package local_resourcestats
 */
class controller {
    /** @var \stdClass The course record. */
    private \stdClass $course;

    /** @var context_course The course context. */
    private context_course $context;

    /**
     * Constructor.
     *
     * @param \stdClass      $course  The course record.
     * @param context_course $context The course context.
     */
    public function __construct(\stdClass $course, context_course $context) {
        $this->course = $course;
        $this->context = $context;
    }

    /**
     * Returns the list of student user objects enrolled in this course,
     * excluding anyone with the manageactivities capability.
     *
     * @return \stdClass[] Indexed by userid.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private function get_students(): array {
        $enrolled = get_enrolled_users(
            $this->context,
            '',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
            'u.lastname ASC, u.firstname ASC'
        );

        $students = [];
        foreach ($enrolled as $user) {
            if (!has_capability('moodle/course:manageactivities', $this->context, $user->id)) {
                $students[$user->id] = $user;
            }
        }

        return $students;
    }

    /**
     * Returns the list of trackable course module IDs (excludes labels, subsections).
     *
     * @return int[]
     */
    private function get_trackable_cmids(): array {
        $modinfo = get_fast_modinfo($this->course);
        $excluded = ['label', 'subsection'];
        $cmids = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!in_array($cm->modname, $excluded, true)) {
                $cmids[] = (int)$cm->id;
            }
        }
        return $cmids;
    }

    /**
     * Returns the template context array for the course statistics page.
     *
     * Each row contains summary data for one course module. Rows with
     * zero unique views are highlighted as warnings.
     *
     * @return array
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public function get_template_context(): array {
        global $DB;

        $students = $this->get_students();
        $totalstudents = count($students);
        $cmids = $this->get_trackable_cmids();

        if (empty($cmids)) {
            return [
                'coursename'       => format_string($this->course->fullname, true, ['context' => $this->context]),
                'activities'       => [],
                'hasactivities'    => false,
                'totalstudents'    => $totalstudents,
                'exporturlcsv'     => '',
                'exporturlexcel'   => '',
            ];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');

        $sql = "SELECT cm.id AS cmid, m.name AS modtype,
                       COALESCE(v.totalviews, 0) AS totalviews,
                       COALESCE(v.uniqueviews, 0) AS uniqueviews,
                       v.lastviewtime
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {local_resourcestats_views} v ON v.cmid = cm.id
                 WHERE cm.id $insql
              ORDER BY cm.section ASC, cm.id ASC";

        $rows = $DB->get_records_sql($sql, $inparams);

        $modinfo = get_fast_modinfo($this->course);

        $activities = [];
        foreach ($rows as $row) {
            $cm = $modinfo->get_cm($row->cmid);
            $engagementpct = $totalstudents > 0 ? round($row->uniqueviews / $totalstudents * 100) : 0;

            $detailurl = new moodle_url('/local/resourcestats/view_stats.php', ['id' => $row->cmid]);

            $activities[] = [
                'cmid'          => (int)$row->cmid,
                'activityname'  => format_string($cm->name, true, ['context' => $this->context]),
                'modtype'       => $row->modtype,
                'totalviews'    => (int)$row->totalviews,
                'uniqueviews'   => (int)$row->uniqueviews,
                'engagementpct' => $engagementpct,
                'lastviewtime'  => $row->lastviewtime ? userdate($row->lastviewtime) : '',
                'detailurl'     => $detailurl->out(false),
                'unviewed'      => ((int)$row->uniqueviews === 0),
            ];
        }

        $exporturlcsv = new moodle_url('/local/resourcestats/export.php', ['courseid' => $this->course->id, 'format' => 'csv']);
        $exporturlexcel = new moodle_url('/local/resourcestats/export.php', ['courseid' => $this->course->id, 'format' => 'excel']);

        $insightengine = new insights($activities, $totalstudents);
        $alerts = $insightengine->get_alerts();

        return [
            'coursename'     => format_string($this->course->fullname, true, ['context' => $this->context]),
            'activities'     => $activities,
            'hasactivities'  => !empty($activities),
            'totalstudents'  => $totalstudents,
            'exporturlcsv'   => $exporturlcsv->out(false),
            'exporturlexcel' => $exporturlexcel->out(false),
            'alerts'         => $alerts,
            'hasalerts'      => !empty($alerts),
        ];
    }

    /**
     * Returns export data covering all enrolled students across all trackable activities.
     *
     * One row per (student, activity) combination. Students with no access have viewcount = 0.
     *
     * @return array Three-element array: [filename, columns, datarows].
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public function get_rows_for_export(): array {
        global $DB;

        $students = $this->get_students();
        $cmids = $this->get_trackable_cmids();

        $columns = [
            get_string('col_activity', 'local_resourcestats'),
            get_string('col_student', 'local_resourcestats'),
            get_string('col_accesses', 'local_resourcestats'),
            get_string('col_firstaccess', 'local_resourcestats'),
            get_string('col_lastaccess', 'local_resourcestats'),
        ];

        if (empty($cmids) || empty($students)) {
            $filename = 'resourcestats_course_' . clean_filename($this->course->shortname) . '_' . date('Ymd');
            return [$filename, $columns, []];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');

        $sql = "SELECT uv.id, uv.cmid, uv.userid, uv.viewcount, uv.firstviewtime, uv.lastviewtime
                  FROM {local_resourcestats_user_views} uv
                 WHERE uv.cmid $insql";

        $viewrows = $DB->get_records_sql($sql, $inparams);

        $viewsindex = [];
        foreach ($viewrows as $vrow) {
            $viewsindex[$vrow->cmid][$vrow->userid] = $vrow;
        }

        $modinfo = get_fast_modinfo($this->course);
        $never = get_string('never', 'local_resourcestats');

        $rows = [];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $activityname = format_string($cm->name, true, ['context' => $this->context]);

            foreach ($students as $userid => $user) {
                $vrow = $viewsindex[$cmid][$userid] ?? null;
                $rows[] = [
                    $activityname,
                    fullname($user),
                    $vrow ? (int)$vrow->viewcount : 0,
                    ($vrow && $vrow->firstviewtime) ? userdate($vrow->firstviewtime) : $never,
                    ($vrow && $vrow->lastviewtime) ? userdate($vrow->lastviewtime) : $never,
                ];
            }
        }

        $filename = 'resourcestats_course_' . clean_filename($this->course->shortname) . '_' . date('Ymd');

        return [$filename, $columns, $rows];
    }

    /**
     * Returns the page URL for the course statistics view.
     *
     * @return moodle_url
     */
    public function get_page_url(): moodle_url {
        return new moodle_url('/local/resourcestats/course_stats.php', ['courseid' => $this->course->id]);
    }
}
