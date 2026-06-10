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
    /** @var int Rows per page for the activity list. */
    private const PERPAGE = 50;

    /** @var string[] Allowed values for the sort URL parameter. */
    private const SORT_ALLOWLIST = [
        'activityname', 'modtype', 'totalviews', 'uniqueviews', 'engagementpct', 'lastviewtime',
    ];

    /** @var array<string,string> Maps sort param to the row key used for comparison. */
    private const SORT_MAP = [
        'activityname'  => 'activityname',
        'modtype'       => 'modtype',
        'totalviews'    => 'totalviews',
        'uniqueviews'   => 'uniqueviews',
        'engagementpct' => 'engagementpct',
        'lastviewtime'  => '_lastviewts',
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
            'totalviews'    => $this->sort_header('totalviews', get_string('col_accesses', 'local_resourcestats')),
            'uniqueviews'   => $this->sort_header('uniqueviews', get_string('col_unique_students', 'local_resourcestats')),
            'engagementpct' => $this->sort_header('engagementpct', get_string('col_engagement', 'local_resourcestats')),
            'lastviewtime'  => $this->sort_header('lastviewtime', get_string('col_lastaccess', 'local_resourcestats')),
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
        global $DB, $OUTPUT;

        $students      = $this->get_students();
        $totalstudents = count($students);
        $cmids         = $this->get_trackable_cmids();

        $statsurl = new moodle_url('/local/resourcestats/course_stats.php', ['courseid' => $this->course->id]);
        $prefsurl = new moodle_url('/local/resourcestats/preferences.php', ['returnurl' => $statsurl->out(false)]);

        if (empty($cmids)) {
            return [
                'coursename'     => format_string($this->course->fullname, true, ['context' => $this->context]),
                'activities'     => [],
                'hasactivities'  => false,
                'totalstudents'  => $totalstudents,
                'exporturlcsv'   => '',
                'exporturlexcel' => '',
                'prefsurl'       => $prefsurl->out(false),
                'headers'        => $this->build_activity_headers(),
                'paginationhtml' => '',
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

        $rows    = $DB->get_records_sql($sql, $inparams);
        $modinfo = get_fast_modinfo($this->course);

        $activities = [];
        foreach ($rows as $row) {
            $cm            = $modinfo->get_cm($row->cmid);
            $engagementpct = $totalstudents > 0 ? round($row->uniqueviews / $totalstudents * 100) : 0;
            $detailurl     = new moodle_url('/local/resourcestats/view_stats.php', ['id' => $row->cmid]);

            $activities[] = [
                'cmid'          => (int)$row->cmid,
                'activityname'  => format_string($cm->name, true, ['context' => $this->context]),
                'modtype'       => $row->modtype,
                'totalviews'    => (int)$row->totalviews,
                'uniqueviews'   => (int)$row->uniqueviews,
                'engagementpct' => $engagementpct,
                'lastviewtime'  => $row->lastviewtime ? userdate($row->lastviewtime) : '',
                '_lastviewts'   => (int)($row->lastviewtime ?? 0),
                'detailurl'     => $detailurl->out(false),
                'unviewed'      => ((int)$row->uniqueviews === 0),
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

        $insightengine = new insights($activities, $totalstudents);
        $alerts        = $insightengine->get_alerts();

        return [
            'coursename'     => format_string($this->course->fullname, true, ['context' => $this->context]),
            'activities'     => $paged,
            'hasactivities'  => !empty($activities),
            'totalstudents'  => $totalstudents,
            'exporturlcsv'   => $exporturlcsv->out(false),
            'exporturlexcel' => $exporturlexcel->out(false),
            'prefsurl'       => $prefsurl->out(false),
            'headers'        => $this->build_activity_headers(),
            'paginationhtml' => $paginationhtml,
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
        $cmids    = $this->get_trackable_cmids();

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
        $never   = get_string('never', 'local_resourcestats');

        $rows = [];
        foreach ($cmids as $cmid) {
            $cm           = $modinfo->get_cm($cmid);
            $activityname = format_string($cm->name, true, ['context' => $this->context]);

            foreach ($students as $userid => $user) {
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
