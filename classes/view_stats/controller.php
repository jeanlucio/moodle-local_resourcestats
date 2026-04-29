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

        $stats = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);

        $totalviews = 0;
        $lastusername = get_string('neverviewed', 'local_resourcestats');
        $lastviewtime = '';

        if ($stats) {
            $totalviews = (int)$stats->totalviews;
            if (!empty($stats->lastuserid)) {
                $lastuser = \core_user::get_user($stats->lastuserid, '*', MUST_EXIST);
                $lastusername = format_string(fullname($lastuser), true, ['context' => $this->context]);
            }
            if (!empty($stats->lastviewtime)) {
                $lastviewtime = userdate($stats->lastviewtime);
            }
        }

        return [
            'totalviews'   => $totalviews,
            'lastusername' => $lastusername,
            'lastviewtime' => $lastviewtime,
            'hasviews'     => $totalviews > 0,
            'cmname'       => format_string($this->cm->name, true, ['context' => $this->context]),
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
