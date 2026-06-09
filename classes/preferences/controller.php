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
 * Controller for the preferences page.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\preferences;

use local_resourcestats\hook_listener;
use moodle_url;

/**
 * Controller for the display preferences page.
 *
 * @package local_resourcestats
 */
class controller {
    /** @var moodle_url URL to redirect to after saving. */
    private moodle_url $returnurl;

    /**
     * Constructor.
     *
     * @param moodle_url $returnurl URL to redirect to after saving.
     */
    public function __construct(moodle_url $returnurl) {
        $this->returnurl = $returnurl;
    }

    /**
     * Processes a POST request: reads the three badge checkboxes and saves preferences.
     */
    public function handle_post(): void {
        $showtotal = optional_param('show_total', 0, PARAM_BOOL);
        $showunique = optional_param('show_unique', 0, PARAM_BOOL);
        $showlastuser = optional_param('show_lastuser', 0, PARAM_BOOL);

        set_user_preference(hook_listener::PREF_SHOW_TOTAL, $showtotal ? '1' : '0');
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, $showunique ? '1' : '0');
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, $showlastuser ? '1' : '0');

        redirect($this->returnurl);
    }

    /**
     * Builds the template context array for the preferences form.
     *
     * @return array
     * @throws \coding_exception
     */
    public function get_template_context(): array {
        return [
            'show_total'    => hook_listener::get_pref_show_total(),
            'show_unique'   => hook_listener::get_pref_show_unique(),
            'show_lastuser' => hook_listener::get_pref_show_lastuser(),
            'actionurl'     => (new moodle_url('/local/resourcestats/preferences.php'))->out(false),
            'returnurl'     => $this->returnurl->out(false),
            'sesskey'       => sesskey(),
        ];
    }

    /**
     * Returns the page URL.
     *
     * @return moodle_url
     */
    public function get_page_url(): moodle_url {
        return new moodle_url(
            '/local/resourcestats/preferences.php',
            ['returnurl' => $this->returnurl->out(false)]
        );
    }
}
