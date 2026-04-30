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
 * Library functions for the plugin.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extends the module settings navigation to add the Statistics tab.
 *
 * @param settings_navigation $settingsnav The settings navigation tree.
 * @param context $context The current context.
 * @throws coding_exception
 */
function local_resourcestats_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    global $PAGE, $USER;

    // Only add the tab if we are inside a course module (activity/resource).
    if ($context->contextlevel != CONTEXT_MODULE) {
        return;
    }

    // Only users capable of managing activities can see the tab.
    if (!has_capability('moodle/course:manageactivities', $context, $USER->id)) {
        return;
    }

    // Labels have no dedicated view page and never fire course_module_viewed.
    if (isset($PAGE->cm) && $PAGE->cm->modname === 'label') {
        return;
    }

    $cmid = $context->instanceid;
    $url = new moodle_url('/local/resourcestats/view_stats.php', ['id' => $cmid]);

    // Create the navigation node for the Tertiary Navigation.
    $node = navigation_node::create(
        get_string('statistics', 'local_resourcestats'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_resourcestats_stats',
        new pix_icon('t/statistics', '')
    );

    // Moodle 4.x/5.x Tertiary Navigation Fix:
    // We must attach our node directly to the module's root settings node.
    $modulesettings = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if ($modulesettings) {
        $modulesettings->add_node($node);
    } else {
        // Fallback for custom formats.
        $settingsnav->add_node($node);
    }
}
