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
 * Upgrade script for local_resourcestats.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function for local_resourcestats.
 *
 * @param int $oldversion Previous installed version.
 * @return bool
 */
function xmldb_local_resourcestats_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026042900) {
        $table = new xmldb_table('local_resourcestats_views');

        $field = new xmldb_field('uniqueviews', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'totalviews');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $usertable = new xmldb_table('local_resourcestats_user_views');
        if (!$dbman->table_exists($usertable)) {
            $usertable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $usertable->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $usertable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $usertable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $usertable->add_key('cmid_userid', XMLDB_KEY_UNIQUE, ['cmid', 'userid']);
            $dbman->create_table($usertable);
        }

        upgrade_plugin_savepoint(true, 2026042900, 'local', 'resourcestats');
    }

    if ($oldversion < 2026042901) {
        $table = new xmldb_table('local_resourcestats_user_views');

        $viewcount = new xmldb_field('viewcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'userid');
        if (!$dbman->field_exists($table, $viewcount)) {
            $dbman->add_field($table, $viewcount);
        }

        $firstviewtime = new xmldb_field('firstviewtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'viewcount');
        if (!$dbman->field_exists($table, $firstviewtime)) {
            $dbman->add_field($table, $firstviewtime);
        }

        $lastviewtime = new xmldb_field('lastviewtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'firstviewtime');
        if (!$dbman->field_exists($table, $lastviewtime)) {
            $dbman->add_field($table, $lastviewtime);
        }

        upgrade_plugin_savepoint(true, 2026042901, 'local', 'resourcestats');
    }

    if ($oldversion < 2026043000) {
        $table = new xmldb_table('local_resourcestats_user_views');

        // The unique key depends on userid and must be dropped before changing the field.
        $key = new xmldb_key('cmid_userid', XMLDB_KEY_UNIQUE, ['cmid', 'userid']);
        $dbman->drop_key($table, $key);

        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'cmid');
        $dbman->change_field_notnull($table, $field);

        $dbman->add_key($table, $key);

        upgrade_plugin_savepoint(true, 2026043000, 'local', 'resourcestats');
    }

    return true;
}
