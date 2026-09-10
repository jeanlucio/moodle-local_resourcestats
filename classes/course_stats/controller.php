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
use local_resourcestats\local\completion_stats;
use local_resourcestats\local\group_visibility;
use moodle_url;

/**
 * Controller for the course-level statistics page.
 *
 * @package local_resourcestats
 */
class controller {
    /** @var int Rows per page for the activity list. */
    private const PERPAGE = 50;

    /** @var string[] Allowed values for the sort URL parameter. */
    private const SORT_ALLOWLIST = [
        'activityname', 'modtype', 'section', 'totalviews', 'uniqueviews', 'engagementpct', 'lastviewtime',
        'completed', 'passed',
    ];

    /** @var array<string,string> Maps sort param to the row key used for comparison. */
    private const SORT_MAP = [
        'activityname'  => 'activityname',
        'modtype'       => 'modtype',
        'section'       => '_sectionnumber',
        'totalviews'    => 'totalviews',
        'uniqueviews'   => 'uniqueviews',
        'engagementpct' => 'engagementpct',
        'lastviewtime'  => '_lastviewts',
        // Activities without completion sort below zero rather than alongside the ones where
        // nobody completed anything: "does not apply" is not the same as "none yet".
        'completed'     => '_completedsort',
        'passed'        => '_passedsort',
    ];

    /** @var \stdClass The course record. */
    private \stdClass $course;

    /** @var context_course The course context. */
    private context_course $context;

    /** @var string Active sort column; empty string means natural course order. */
    private string $sort;

    /** @var string Sort direction: 'asc' or 'desc'. */
    private string $dir;

    /** @var int Current page (0-based). */
    private int $page;

    /**
     * Constructor.
     *
     * @param \stdClass      $course  The course record.
     * @param context_course $context The course context.
     * @param string         $sort    Sort column name (validated against allowlist).
     * @param string         $dir     Sort direction: 'asc' or 'desc'.
     * @param int            $page    Current page (0-based).
     */
    public function __construct(
        \stdClass $course,
        context_course $context,
        string $sort = 'activityname',
        string $dir = 'asc',
        int $page = 0
    ) {
        $this->course  = $course;
        $this->context = $context;
        $this->sort    = in_array($sort, self::SORT_ALLOWLIST, true) ? $sort : '';
        $this->dir     = $dir === 'desc' ? 'desc' : 'asc';
        $this->page    = max(0, $page);
    }

    /**
     * Returns every enrolled student, excluding anyone with the manageactivities
     * capability. Not scoped by group — callers apply their own group scoping on top.
     *
     * Fetches the manageactivities-holding subset in one batched query rather than
     * calling has_capability() per enrolled user, which would run one role-lookup
     * query per user for a course with N enrolments.
     *
     * @return \stdClass[] Indexed by userid.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private function get_all_students(): array {
        $enrolled = get_enrolled_users(
            $this->context,
            '',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
            'u.lastname ASC, u.firstname ASC'
        );

        $privileged = get_enrolled_users($this->context, 'moodle/course:manageactivities', 0, 'u.id');

        return array_diff_key($enrolled, $privileged);
    }

    /**
     * Returns the students visible to the caller at the course level: every enrolled
     * student, restricted by the course's own group mode (not by any individual
     * activity's override — see fetch_activity_rows() and get_rows_for_export() for that).
     *
     * @param \stdClass[] $allstudents Every enrolled student, from get_all_students().
     * @return \stdClass[] Indexed by userid.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private function get_students(array $allstudents): array {
        return group_visibility::restrict_students_by_course($allstudents, $this->course, $this->context);
    }

    /**
     * Returns the number of the caller's own group's students, among the given students,
     * regardless of the course's own group mode setting.
     *
     * Used as the engagement-percentage denominator for an activity that overrides the
     * course's group mode (e.g. course is "No groups" but the activity is "Separate
     * groups") — the course-wide $totalstudents denominator would not describe the
     * population that activity's row is actually scoped to.
     *
     * @param \stdClass[] $allstudents Every enrolled student, from get_all_students().
     * @return int|null Null when the caller holds moodle/site:accessallgroups (no such
     *                   activity can ever restrict them, so no fallback is needed).
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private function count_my_group_students(array $allstudents): ?int {
        $mygroupids = group_visibility::get_my_group_restriction($this->course, $this->context);
        if ($mygroupids === null) {
            return null;
        }

        if (empty($mygroupids)) {
            return 0;
        }

        $visible = get_enrolled_users($this->context, '', $mygroupids, 'u.id');

        return count(array_intersect_key($allstudents, $visible));
    }

    /**
     * Returns per-activity stat rows: cmid, modtype, totalviews, uniqueviews, lastviewtime,
     * and _denominator (the engagement-percentage denominator to use for that row).
     *
     * Each activity's own effective group mode is checked independently
     * (group_visibility::get_activity_group_restriction()) rather than deciding once for
     * the whole course: a course can be "No groups" while one specific activity overrides
     * that to "Separate groups", and the two checks are not equivalent
     * (groups_get_course_groupmode() vs groups_get_activity_groupmode()). Activities are
     * therefore bucketed by their own restriction and fetched with one batched query per
     * bucket, not one query per activity.
     *
     * An activity whose own group mode does not restrict it reads the course-wide running
     * aggregate in local_resourcestats_views, which also preserves the contribution of
     * GDPR-erased students (their view counted at the time it happened, never later
     * decremented), using $totalstudents as its denominator.
     *
     * An activity restricted to specific groups recomputes from
     * local_resourcestats_user_views instead, scoped to the caller's own group members for
     * that activity — exactly the leak view_stats, export, and the course-view badges
     * already avoid — using that group's own student count as its denominator. The
     * GDPR-erased contribution is necessarily lost on this path, since an erased student's
     * row is gone and can no longer be attributed to any group.
     *
     * @param int[]       $cmids       Trackable course module IDs.
     * @param \stdClass[] $allstudents Every enrolled student, from get_all_students().
     * @param int         $totalstudents Course-level denominator for an unrestricted activity.
     * @return \stdClass[] Rows, in $cmids order.
     * @throws \dml_exception
     * @throws \coding_exception
     */
    private function fetch_activity_rows(array $cmids, array $allstudents, int $totalstudents): array {
        global $DB;

        $modinfo = get_fast_modinfo($this->course);
        $groupidscache = [];

        $unrestrictedcmids = [];
        $buckets = [];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $restriction = group_visibility::get_activity_group_restriction($cm, $cm->context, $groupidscache);
            if ($restriction === null) {
                $unrestrictedcmids[] = $cmid;
                continue;
            }
            $bucketkey = implode(',', $restriction);
            $buckets[$bucketkey]['cmids'][] = $cmid;
            $buckets[$bucketkey]['groupids'] = $restriction;
        }

        $rowsbycmid = [];

        if (!empty($unrestrictedcmids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($unrestrictedcmids, SQL_PARAMS_NAMED, 'cm');
            $sql = "SELECT cm.id AS cmid, m.name AS modtype,
                           COALESCE(v.totalviews, 0) AS totalviews,
                           COALESCE(v.uniqueviews, 0) AS uniqueviews,
                           v.lastviewtime
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                 LEFT JOIN {local_resourcestats_views} v ON v.cmid = cm.id
                     WHERE cm.id $insql";
            foreach ($DB->get_records_sql($sql, $inparams) as $row) {
                $row->_denominator = $totalstudents;
                $rowsbycmid[$row->cmid] = $row;
            }
        }

        foreach ($buckets as $bucket) {
            $bucketcmids = $bucket['cmids'];
            $groupids    = $bucket['groupids'];

            [$insql, $inparams] = $DB->get_in_or_equal($bucketcmids, SQL_PARAMS_NAMED, 'cm');

            $groupmemberids = [];
            if (!empty($groupids)) {
                $groupmembers   = get_enrolled_users($this->context, '', $groupids, 'u.id');
                $groupmemberids = array_keys($groupmembers);
            }

            if (empty($groupmemberids)) {
                $sql = "SELECT cm.id AS cmid, m.name AS modtype,
                               0 AS totalviews, 0 AS uniqueviews, NULL AS lastviewtime
                          FROM {course_modules} cm
                          JOIN {modules} m ON m.id = cm.module
                         WHERE cm.id $insql";
                foreach ($DB->get_records_sql($sql, $inparams) as $row) {
                    $row->_denominator = 0;
                    $rowsbycmid[$row->cmid] = $row;
                }
                continue;
            }

            $bucketdenominator = count(array_intersect_key($allstudents, array_flip($groupmemberids)));

            [$userinsql, $userinparams] = $DB->get_in_or_equal($groupmemberids, SQL_PARAMS_NAMED, 'u');
            $params = array_merge($inparams, $userinparams);

            $sql = "SELECT cm.id AS cmid, m.name AS modtype,
                           COALESCE(SUM(uv.viewcount), 0) AS totalviews,
                           COUNT(uv.userid) AS uniqueviews,
                           MAX(uv.lastviewtime) AS lastviewtime
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                 LEFT JOIN {local_resourcestats_user_views} uv ON uv.cmid = cm.id AND uv.userid $userinsql
                     WHERE cm.id $insql
                  GROUP BY cm.id, m.name";
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $row->_denominator = $bucketdenominator;
                $rowsbycmid[$row->cmid] = $row;
            }
        }

        $rows = [];
        foreach ($cmids as $cmid) {
            if (isset($rowsbycmid[$cmid])) {
                $rows[] = $rowsbycmid[$cmid];
            }
        }

        return $rows;
    }

    /**
     * Returns the list of trackable course module IDs (excludes labels, subsections).
     *
     * @return int[]
     */
    private function get_trackable_cmids(): array {
        $modinfo  = get_fast_modinfo($this->course);
        $excluded = ['label', 'subsection'];
        $cmids    = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!in_array($cm->modname, $excluded, true)) {
                $cmids[] = (int)$cm->id;
            }
        }
        return $cmids;
    }

    /**
     * Builds a sort-header context array for one column.
     *
     * @param string $colname URL sort-param value for this column.
     * @param string $label   Already-translated column label.
     * @return array Keys: url, label, icon_class.
     */
    private function sort_header(string $colname, string $label): array {
        $isactive = $this->sort === $colname;
        $nextdir  = ($isactive && $this->dir === 'asc') ? 'desc' : 'asc';
        if (!$isactive) {
            $iconclass = 'fa-sort text-muted';
        } else if ($this->dir === 'asc') {
            $iconclass = 'fa-sort-asc text-primary';
        } else {
            $iconclass = 'fa-sort-desc text-primary';
        }
        return [
            'url'        => (new moodle_url(
                $this->get_page_url(),
                ['sort' => $colname, 'dir' => $nextdir, 'page' => 0]
            ))->out(false),
            'label'      => $label,
            'icon_class' => $iconclass,
        ];
    }

    /**
     * Builds the sort-header context arrays for all activity columns.
     *
     * @return array Keyed by column name.
     * @throws \coding_exception
     */
    private function build_activity_headers(): array {
        return [
            'activityname'  => $this->sort_header('activityname', get_string('col_activity', 'local_resourcestats')),
            'modtype'       => $this->sort_header('modtype', get_string('col_type', 'local_resourcestats')),
            'section'       => $this->sort_header('section', get_string('col_section', 'local_resourcestats')),
            'totalviews'    => $this->sort_header('totalviews', get_string('col_accesses', 'local_resourcestats')),
            'uniqueviews'   => $this->sort_header('uniqueviews', get_string('col_unique_students', 'local_resourcestats')),
            'engagementpct' => $this->sort_header('engagementpct', get_string('col_engagement', 'local_resourcestats')),
            'lastviewtime'  => $this->sort_header('lastviewtime', get_string('col_lastaccess', 'local_resourcestats')),
            'completed'     => $this->sort_header('completed', get_string('col_completed', 'local_resourcestats')),
            'passed'        => $this->sort_header('passed', get_string('col_passed', 'local_resourcestats')),
        ];
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
        global $CFG, $OUTPUT;
        require_once($CFG->dirroot . '/course/lib.php');

        $allstudents      = $this->get_all_students();
        $students         = $this->get_students($allstudents);
        $totalstudents    = count($students);
        $grouptotalcount  = $this->count_my_group_students($allstudents);
        $showgrouptotal   = $grouptotalcount !== null && $grouptotalcount !== $totalstudents;
        $cmids            = $this->get_trackable_cmids();

        $statsurl = new moodle_url('/local/resourcestats/course_stats.php', ['courseid' => $this->course->id]);
        $prefsurl = new moodle_url('/local/resourcestats/preferences.php', ['returnurl' => $statsurl->out(false)]);

        if (empty($cmids)) {
            return [
                'coursename'         => format_string($this->course->fullname, true, ['context' => $this->context]),
                'activities'         => [],
                'hasactivities'      => false,
                'totalstudents'      => $totalstudents,
                'showgrouptotal'     => $showgrouptotal,
                'grouptotalstudents' => $grouptotalcount ?? 0,
                'exporturlcsv'       => '',
                'exporturlexcel'     => '',
                'prefsurl'           => $prefsurl->out(false),
                'headers'            => $this->build_activity_headers(),
                'paginationhtml'     => '',
            ];
        }

        $rows    = $this->fetch_activity_rows($cmids, $allstudents, $totalstudents);
        $modinfo = get_fast_modinfo($this->course);

        $cms = [];
        foreach ($cmids as $cmid) {
            $cms[(int)$cmid] = $modinfo->get_cm($cmid);
        }
        $completioncache = [];
        $completion = completion_stats::get_stats_for_modules($this->context, $cms, $completioncache);

        $sectionnames = [];
        foreach ($modinfo->get_section_info_all() as $sinfo) {
            $sectionnames[$sinfo->section] = get_section_name($this->course, $sinfo);
        }

        $activities = [];
        foreach ($rows as $row) {
            $cm            = $modinfo->get_cm($row->cmid);
            $denominator   = $row->_denominator;
            $engagementpct = $denominator > 0 ? round($row->uniqueviews / $denominator * 100) : 0;
            $detailurl     = new moodle_url('/local/resourcestats/view_stats.php', ['id' => $row->cmid]);
            $sectionnum    = $cm->sectionnum;
            $sectionname   = $sectionnames[$sectionnum] ?? get_string('section') . ' ' . $sectionnum;

            $activities[] = [
                'cmid'           => (int)$row->cmid,
                'activityname'   => format_string($cm->name, true, ['context' => $this->context]),
                'modtype'        => $row->modtype,
                'section'        => $sectionname,
                '_sectionnumber' => $sectionnum,
                'totalviews'     => (int)$row->totalviews,
                'uniqueviews'    => (int)$row->uniqueviews,
                'engagementpct'  => $engagementpct,
                'lastviewtime'   => $row->lastviewtime ? userdate($row->lastviewtime) : '',
                '_lastviewts'    => (int)($row->lastviewtime ?? 0),
                'detailurl'      => $detailurl->out(false),
                'unviewed'       => ((int)$row->uniqueviews === 0),
                'hascompletion'  => isset($completion[(int)$row->cmid]),
                'completed'      => $completion[(int)$row->cmid]->completed ?? 0,
                'passed'         => $completion[(int)$row->cmid]->passed ?? 0,
                'trackedtotal'   => $completion[(int)$row->cmid]->total ?? 0,
                'haspass'        => $completion[(int)$row->cmid]->haspass ?? false,
                '_completedsort' => isset($completion[(int)$row->cmid]) ? $completion[(int)$row->cmid]->completed : -1,
                '_passedsort'    => ($completion[(int)$row->cmid]->haspass ?? false)
                    ? $completion[(int)$row->cmid]->passed
                    : -1,
            ];
        }

        $sortkey = self::SORT_MAP[$this->sort] ?? 'activityname';
        $dir     = $this->dir === 'asc' ? 1 : -1;
        usort($activities, function (array $a, array $b) use ($sortkey, $dir): int {
            $va   = $a[$sortkey] ?? 0;
            $vb   = $b[$sortkey] ?? 0;
            $diff = is_string($va) ? strcmp($va, $vb) : ($va <=> $vb);
            return $diff * $dir;
        });

        $total    = count($activities);
        $paged    = array_slice($activities, $this->page * self::PERPAGE, self::PERPAGE);

        $paginationhtml = '';
        if ($total > self::PERPAGE) {
            $pagingurl = new moodle_url($this->get_page_url(), ['sort' => $this->sort, 'dir' => $this->dir]);
            $paginationhtml = $OUTPUT->render(new \paging_bar($total, $this->page, self::PERPAGE, $pagingurl));
        }

        $exporturlcsv   = new moodle_url(
            '/local/resourcestats/export.php',
            ['courseid' => $this->course->id, 'format' => 'csv']
        );
        $exporturlexcel = new moodle_url(
            '/local/resourcestats/export.php',
            ['courseid' => $this->course->id, 'format' => 'excel']
        );

        $insightengine = new insights($activities, $totalstudents, array_keys($students));
        $alerts        = $insightengine->get_alerts();

        return [
            'coursename'         => format_string($this->course->fullname, true, ['context' => $this->context]),
            'activities'         => $paged,
            'hasactivities'      => !empty($activities),
            'totalstudents'      => $totalstudents,
            'showgrouptotal'     => $showgrouptotal,
            'grouptotalstudents' => $grouptotalcount ?? 0,
            'exporturlcsv'       => $exporturlcsv->out(false),
            'exporturlexcel'     => $exporturlexcel->out(false),
            'prefsurl'           => $prefsurl->out(false),
            'headers'            => $this->build_activity_headers(),
            'paginationhtml'     => $paginationhtml,
            'alerts'             => $alerts,
            'hasalerts'          => !empty($alerts),
        ];
    }

    /**
     * Returns export data covering all enrolled students across all trackable activities.
     *
     * One row per (student, activity) combination. Students with no access have viewcount = 0.
     *
     * Each activity's own group mode is checked independently, exactly as
     * fetch_activity_rows() does for the on-screen table: a course-level-visible student
     * is further excluded from a specific activity's rows when that activity overrides the
     * course's group mode to separate groups and the student is outside the caller's own
     * group for it.
     *
     * @return array Three-element array: [filename, columns, datarows].
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public function get_rows_for_export(): array {
        global $DB;

        $allstudents = $this->get_all_students();
        $students    = $this->get_students($allstudents);
        $cmids       = $this->get_trackable_cmids();

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

        $modinfo       = get_fast_modinfo($this->course);
        $never         = get_string('never', 'local_resourcestats');
        $groupidscache = [];
        $enrolledcache = [];

        $rows = [];
        foreach ($cmids as $cmid) {
            $cm           = $modinfo->get_cm($cmid);
            $activityname = format_string($cm->name, true, ['context' => $this->context]);

            $cmstudents = group_visibility::restrict_students_by_activity(
                $students,
                $cm,
                $cm->context,
                $this->context,
                $groupidscache,
                $enrolledcache
            );

            foreach ($cmstudents as $userid => $user) {
                $vrow   = $viewsindex[$cmid][$userid] ?? null;
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
     * Returns the base page URL for the course statistics view.
     *
     * @return moodle_url
     */
    public function get_page_url(): moodle_url {
        return new moodle_url('/local/resourcestats/course_stats.php', ['courseid' => $this->course->id]);
    }
}
