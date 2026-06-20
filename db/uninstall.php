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
 * Pre-uninstallation hook: removes plugin data that core does not clean up.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Removes the plugin's user preferences before core uninstalls the plugin.
 *
 * This hook only handles cleanup that core does NOT perform automatically.
 * During uninstall, core (uninstall_plugin() in lib/adminlib.php) already
 * drops every table declared in db/install.xml and removes all admin settings,
 * so neither is repeated here. User preferences live in the core
 * {user_preferences} table, which core never touches, so the plugin must clean
 * its own here (the per-user column display choices).
 *
 * @return bool
 */
function xmldb_local_resourcestats_uninstall(): bool {
    global $DB;

    $DB->delete_records_select(
        'user_preferences',
        $DB->sql_like('name', ':prefix'),
        ['prefix' => 'local_resourcestats_%']
    );

    return true;
}
