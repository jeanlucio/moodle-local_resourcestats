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

    $settings->add(new admin_setting_configcheckbox(
        'local_resourcestats/default_show_total',
        get_string('setting_show_total', 'local_resourcestats'),
        get_string('setting_show_total_desc', 'local_resourcestats'),
        '1'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_resourcestats/default_show_unique',
        get_string('setting_show_unique', 'local_resourcestats'),
        get_string('setting_show_unique_desc', 'local_resourcestats'),
        '1'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_resourcestats/default_show_lastuser',
        get_string('setting_show_lastuser', 'local_resourcestats'),
        get_string('setting_show_lastuser_desc', 'local_resourcestats'),
        '0'
    ));

    $ADMIN->add('localplugins', $settings);
}
