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
 * Controller for the view_stats page.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\view_stats;

use context_course;
use context_module;
use moodle_url;

/**
 * Controller for the module statistics page.
 *
 * @package local_resourcestats
 */
class controller {
    /** @var \cm_info The course module info object. */
    private \cm_info $cm;

    /** @var context_module The module context. */
    private context_module $context;

    /**
     * Constructor.
     *
     * @param \cm_info       $cm      The course module.
     * @param context_module $context The module context.
     */
    public function __construct(\cm_info $cm, context_module $context) {
        $this->cm = $cm;
        $this->context = $context;
    }

    /**
     * Builds the list of student rows by crossing enrolled users with stored view data.
     *
     * Students with no access appear with viewcount = 0. Teachers and managers
     * (anyone holding moodle/course:manageactivities) are excluded, mirroring
     * the same filter applied in observer.php when recording views.
     *
     * View records for users no longer enrolled (e.g. soft-deleted accounts) are
     * returned as orphan totals so the caller can include them in the deleted row.
     *
     * @return array Three-element array [$rows, $orphanviews, $orphancount]:
     *               $rows is the indexed array of active student rows; $orphanviews
     *               and $orphancount accumulate views from soft-deleted/unenrolled users.
     * @throws \dml_exception
     * @throws \coding_exception
     */
    private function build_student_rows(): array {
        global $DB;

        $coursecontext = context_course::instance($this->cm->course);

        $enrolledusers = get_enrolled_users(
            $coursecontext,
            '',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
            'u.lastname ASC, u.firstname ASC'
        );

        $studentids = [];
        foreach ($enrolledusers as $user) {
            if (!has_capability('moodle/course:manageactivities', $coursecontext, $user->id)) {
                $studentids[$user->id] = $user;
            }
        }

        $allviewrows = $DB->get_records('local_resourcestats_user_views', ['cmid' => $this->cm->id]);

        $viewsbyuser = [];
        $orphanviews = 0;
        $orphancount = 0;
        foreach ($allviewrows as $vrow) {
            if (isset($studentids[$vrow->userid])) {
                $viewsbyuser[$vrow->userid] = $vrow;
            } else {
                $orphanviews += (int)$vrow->viewcount;
                $orphancount++;
            }
        }

        $rows = [];
        foreach ($studentids as $userid => $user) {
            $vrow = $viewsbyuser[$userid] ?? null;
            $rows[] = [
                'fullname'      => format_string(fullname($user), true, ['context' => $this->context]),
                'viewcount'     => $vrow ? (int)$vrow->viewcount : 0,
                'firstviewtime' => ($vrow && $vrow->firstviewtime) ? userdate($vrow->firstviewtime) : '',
                'lastviewtime'  => ($vrow && $vrow->lastviewtime) ? userdate($vrow->lastviewtime) : '',
                'neveraccessed' => ($vrow === null),
            ];
        }

        usort($rows, fn($a, $b) => $b['viewcount'] <=> $a['viewcount'] ?: strcmp($a['fullname'], $b['fullname']));

        return [$rows, $orphanviews, $orphancount];
    }

    /**
     * Returns the template context array for the stats page.
     *
     * @return array Context array ready for render_from_template.
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public function get_template_context(): array {
        global $DB;

        [$students, $orphanviews, $orphancount] = $this->build_student_rows();

        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);
        $gdprdeletedviews = ($aggregate ? (int)$aggregate->deletedviews : 0) + $orphanviews;
        $gdprdeletedcount = ($aggregate ? (int)$aggregate->deletedcount : 0) + $orphancount;

        $totalviews = $gdprdeletedviews;
        $uniquecount = $gdprdeletedcount;
        foreach ($students as $s) {
            $totalviews += $s['viewcount'];
            if (!$s['neveraccessed']) {
                $uniquecount++;
            }
        }

        $deletedrow = null;
        if ($gdprdeletedcount > 0) {
            $deletedrow = [
                'label'     => get_string('deleted_students', 'local_resourcestats', $gdprdeletedcount),
                'viewcount' => $gdprdeletedviews,
            ];
        }

        $exporturlcsv = new moodle_url('/local/resourcestats/export.php', ['id' => $this->cm->id, 'format' => 'csv']);
        $exporturlexcel = new moodle_url('/local/resourcestats/export.php', ['id' => $this->cm->id, 'format' => 'excel']);

        return [
            'cmname'        => format_string($this->cm->name, true, ['context' => $this->context]),
            'students'      => $students,
            'hasviews'      => !empty($students) || $gdprdeletedcount > 0,
            'totalviews'    => $totalviews,
            'uniqueviews'   => $uniquecount,
            'hasdeletedrow' => $gdprdeletedcount > 0,
            'deletedrow'    => $deletedrow,
            'exporturlcsv'   => $exporturlcsv->out(false),
            'exporturlexcel' => $exporturlexcel->out(false),
        ];
    }

    /**
     * Returns the data needed for a file download export.
     *
     * @return array Three-element array: [filename, columns, datarows].
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public function get_rows_for_export(): array {
        [$students] = $this->build_student_rows();

        $columns = [
            get_string('col_student', 'local_resourcestats'),
            get_string('col_accesses', 'local_resourcestats'),
            get_string('col_firstaccess', 'local_resourcestats'),
            get_string('col_lastaccess', 'local_resourcestats'),
        ];

        $never = get_string('never', 'local_resourcestats');

        $rows = [];
        foreach ($students as $s) {
            $rows[] = [
                $s['fullname'],
                $s['viewcount'],
                $s['firstviewtime'] ?: $never,
                $s['lastviewtime'] ?: $never,
            ];
        }

        $filename = 'resourcestats_' . clean_filename($this->cm->name) . '_' . date('Ymd');

        return [$filename, $columns, $rows];
    }

    /**
     * Returns the page URL for this statistics view.
     *
     * @return moodle_url
     */
    public function get_page_url(): moodle_url {
        return new moodle_url('/local/resourcestats/view_stats.php', ['id' => $this->cm->id]);
    }
}
