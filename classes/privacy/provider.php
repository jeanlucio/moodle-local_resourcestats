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
 * Privacy API provider for local_resourcestats.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\dataformat;
use core_privacy\local\request\writer;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;

/**
 * Privacy provider for local_resourcestats.
 *
 * Stores the ID of the last user who accessed each course module, along
 * with a running total of accesses. No content data is stored.
 *
 * @package local_resourcestats
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata about data stored by the plugin.
     *
     * @param collection $collection The metadata collection to populate.
     * @return collection The populated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_resourcestats_views',
            [
                'cmid'          => 'privacy:metadata:local_resourcestats_views:cmid',
                'lastuserid'    => 'privacy:metadata:local_resourcestats_views:lastuserid',
                'lastviewtime'  => 'privacy:metadata:local_resourcestats_views:lastviewtime',
            ],
            'privacy:metadata:local_resourcestats_views'
        );
        return $collection;
    }

    /**
     * Returns the list of contexts that contain user data for the given user.
     *
     * @param int $userid The user ID to find contexts for.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_resourcestats_views} v ON v.cmid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextmodule
                   AND v.lastuserid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextmodule' => CONTEXT_MODULE,
            'userid'        => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Returns the list of users who have data within a given context.
     *
     * @param userlist $userlist The userlist to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $sql = "SELECT v.lastuserid AS userid
                  FROM {local_resourcestats_views} v
                 WHERE v.cmid = :cmid
                   AND v.lastuserid IS NOT NULL";

        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * Exports all data for a given user.
     *
     * @param approved_contextlist $contextlist The list of approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $record = $DB->get_record('local_resourcestats_views', [
                'cmid'       => $context->instanceid,
                'lastuserid' => $contextlist->get_user()->id,
            ]);

            if ($record) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_resourcestats')],
                    (object)['lastviewtime' => transform::datetime($record->lastviewtime)]
                );
            }
        }
    }

    /**
     * Deletes all data for all users in a given context.
     *
     * @param \context $context The context to delete from.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $DB->delete_records('local_resourcestats_views', ['cmid' => $context->instanceid]);
    }

    /**
     * Deletes all data for a given user in the given contexts.
     *
     * @param approved_contextlist $contextlist The list of approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $DB->set_field('local_resourcestats_views', 'lastuserid', null, [
                'cmid'       => $context->instanceid,
                'lastuserid' => $contextlist->get_user()->id,
            ]);
        }
    }

    /**
     * Deletes data for multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved userlist to delete data for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED, 'u');
        $inparams['cmid'] = $context->instanceid;

        $DB->set_field_select(
            'local_resourcestats_views',
            'lastuserid',
            null,
            "cmid = :cmid AND lastuserid $insql",
            $inparams
        );
    }
}
