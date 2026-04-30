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
     * @param \cm_info     $cm      The course module.
     * @param context_module $context The module context.
     */
    public function __construct(\cm_info $cm, context_module $context) {
        $this->cm = $cm;
        $this->context = $context;
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

        $sql = "SELECT uv.id, uv.userid, uv.viewcount, uv.firstviewtime, uv.lastviewtime,
                       u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                  FROM {local_resourcestats_user_views} uv
             LEFT JOIN {user} u ON u.id = uv.userid AND u.deleted = 0
                 WHERE uv.cmid = :cmid
              ORDER BY uv.viewcount DESC, uv.lastviewtime DESC";

        $rows = $DB->get_records_sql($sql, ['cmid' => $this->cm->id]);

        $students = [];
        $totalviews = 0;
        $deletedcount = 0;
        $deletedviews = 0;

        foreach ($rows as $row) {
            $totalviews += (int)$row->viewcount;

            if (empty($row->userid) || empty($row->firstname)) {
                $deletedcount++;
                $deletedviews += (int)$row->viewcount;
                continue;
            }

            $fakeuser = (object)[
                'firstname'         => $row->firstname ?? '',
                'lastname'          => $row->lastname ?? '',
                'firstnamephonetic' => $row->firstnamephonetic ?? '',
                'lastnamephonetic'  => $row->lastnamephonetic ?? '',
                'middlename'        => $row->middlename ?? '',
                'alternatename'     => $row->alternatename ?? '',
            ];

            $students[] = [
                'fullname'      => format_string(fullname($fakeuser), true, ['context' => $this->context]),
                'viewcount'     => (int)$row->viewcount,
                'firstviewtime' => !empty($row->firstviewtime) ? userdate($row->firstviewtime) : '',
                'lastviewtime'  => !empty($row->lastviewtime) ? userdate($row->lastviewtime) : '',
            ];
        }

        $deletedrow = null;
        if ($deletedcount > 0) {
            $deletedrow = [
                'label'     => get_string('deleted_students', 'local_resourcestats', $deletedcount),
                'viewcount' => $deletedviews,
            ];
        }

        return [
            'cmname'        => format_string($this->cm->name, true, ['context' => $this->context]),
            'students'      => $students,
            'hasviews'      => !empty($students) || $deletedcount > 0,
            'totalviews'    => $totalviews,
            'uniqueviews'   => count($students) + $deletedcount,
            'hasdeletedrow' => $deletedcount > 0,
            'deletedrow'    => $deletedrow,
        ];
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
