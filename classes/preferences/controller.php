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
 * Controller for the display-mode preferences page.
 *
 * @package local_resourcestats
 */
class controller {
    /** @var string[] Valid preference values. */
    const VALID_MODES = ['both', 'total', 'unique', 'none'];

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
     * Processes a POST request: validates and saves the display mode preference.
     *
     * @throws \moodle_exception If the submitted mode is invalid.
     */
    public function handle_post(): void {
        $mode = required_param('mode', PARAM_ALPHANUMEXT);

        if (!in_array($mode, self::VALID_MODES, true)) {
            throw new \moodle_exception('invalidmode', 'local_resourcestats');
        }

        set_user_preference(hook_listener::PREF_KEY, $mode);
        redirect($this->returnurl);
    }

    /**
     * Builds the template context array for the preferences form.
     *
     * @return array
     * @throws \coding_exception
     */
    public function get_template_context(): array {
        $defaultmode = hook_listener::get_default_mode();
        $current = get_user_preferences(hook_listener::PREF_KEY, $defaultmode);

        $options = [];
        foreach (self::VALID_MODES as $value) {
            $options[] = [
                'value'     => $value,
                'label'     => get_string('mode_' . $value, 'local_resourcestats'),
                'checked'   => $current === $value,
                'isdefault' => $value === $defaultmode,
            ];
        }

        return [
            'options'   => $options,
            'actionurl' => (new moodle_url('/local/resourcestats/preferences.php'))->out(false),
            'returnurl' => $this->returnurl->out(false),
            'sesskey'   => sesskey(),
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
