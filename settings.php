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
 * Admin settings for local_resourcestats.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_resourcestats',
        get_string('pluginname', 'local_resourcestats')
    );

    $settings->add(new admin_setting_configselect(
        'local_resourcestats/defaultmode',
        get_string('setting_defaultmode', 'local_resourcestats'),
        get_string('setting_defaultmode_desc', 'local_resourcestats'),
        'none',
        [
            'both'   => get_string('mode_both', 'local_resourcestats'),
            'total'  => get_string('mode_total', 'local_resourcestats'),
            'unique' => get_string('mode_unique', 'local_resourcestats'),
            'none'   => get_string('mode_none', 'local_resourcestats'),
        ]
    ));

    $ADMIN->add('localplugins', $settings);
}
