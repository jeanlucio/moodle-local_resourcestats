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
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_resourcestats.
 *
 * Two tables store personal data:
 *  - local_resourcestats_views: records the last user who accessed each module.
 *  - local_resourcestats_user_views: records per-student access counts and timestamps.
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
                'cmid'         => 'privacy:metadata:local_resourcestats_views:cmid',
                'lastuserid'   => 'privacy:metadata:local_resourcestats_views:lastuserid',
                'lastviewtime' => 'privacy:metadata:local_resourcestats_views:lastviewtime',
            ],
            'privacy:metadata:local_resourcestats_views'
        );

        $collection->add_database_table(
            'local_resourcestats_user_views',
            [
                'cmid'          => 'privacy:metadata:local_resourcestats_user_views:cmid',
                'userid'        => 'privacy:metadata:local_resourcestats_user_views:userid',
                'viewcount'     => 'privacy:metadata:local_resourcestats_user_views:viewcount',
                'firstviewtime' => 'privacy:metadata:local_resourcestats_user_views:firstviewtime',
                'lastviewtime'  => 'privacy:metadata:local_resourcestats_user_views:lastviewtime',
            ],
            'privacy:metadata:local_resourcestats_user_views'
        );

        return $collection;
    }

    /**
     * Returns the list of contexts that contain user data for the given user.
     *
     * Uses local_resourcestats_user_views as the authoritative source because
     * every student who accessed a module has a row there, regardless of whether
     * they were the most recent visitor.
     *
     * @param int $userid The user ID to find contexts for.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_resourcestats_user_views} uv ON uv.cmid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextmodule
                   AND uv.userid = :userid";

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

        $sql = "SELECT uv.userid
                  FROM {local_resourcestats_user_views} uv
                 WHERE uv.cmid = :cmid";

        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * Exports all data stored for a given user.
     *
     * Exports per-module access counts and timestamps from
     * local_resourcestats_user_views for every approved context.
     *
     * @param approved_contextlist $contextlist The list of approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $record = $DB->get_record('local_resourcestats_user_views', [
                'cmid'   => $context->instanceid,
                'userid' => $userid,
            ]);

            if (!$record) {
                continue;
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_resourcestats')],
                (object)[
                    'viewcount'     => $record->viewcount,
                    'firstviewtime' => !empty($record->firstviewtime)
                        ? transform::datetime($record->firstviewtime) : null,
                    'lastviewtime'  => !empty($record->lastviewtime)
                        ? transform::datetime($record->lastviewtime) : null,
                ]
            );
        }
    }

    /**
     * Deletes all personal data for all users in a given context.
     *
     * Removes all rows from both tables that reference the module.
     *
     * @param \context $context The context to delete from.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cmid = $context->instanceid;
        $DB->delete_records('local_resourcestats_user_views', ['cmid' => $cmid]);
        $DB->delete_records('local_resourcestats_views', ['cmid' => $cmid]);
    }

    /**
     * Deletes all data for a given user across the given contexts.
     *
     * Removes the student's row from local_resourcestats_user_views and nulls
     * lastuserid in local_resourcestats_views when it points to this user.
     *
     * @param approved_contextlist $contextlist The list of approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cmid = $context->instanceid;

            // Anonymise rather than delete: preserve viewcount for aggregate
            // consistency but remove all identifying fields in one operation.
            $userrecord = $DB->get_record(
                'local_resourcestats_user_views',
                ['cmid' => $cmid, 'userid' => $userid]
            );
            if ($userrecord) {
                $userrecord->userid = null;
                $userrecord->firstviewtime = null;
                $userrecord->lastviewtime = null;
                $DB->update_record('local_resourcestats_user_views', $userrecord);
            }

            $DB->set_field('local_resourcestats_views', 'lastuserid', null, [
                'cmid'       => $cmid,
                'lastuserid' => $userid,
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

        $cmid = $context->instanceid;
        $userids = $userlist->get_userids();

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params = array_merge(['cmid' => $cmid], $inparams);

        // Anonymise each matching row: preserve viewcount, erase identifying fields.
        $records = $DB->get_records_select(
            'local_resourcestats_user_views',
            "cmid = :cmid AND userid $insql",
            $params
        );
        foreach ($records as $record) {
            $record->userid = null;
            $record->firstviewtime = null;
            $record->lastviewtime = null;
            $DB->update_record('local_resourcestats_user_views', $record);
        }

        $DB->set_field_select(
            'local_resourcestats_views',
            'lastuserid',
            null,
            "cmid = :cmid AND lastuserid $insql",
            $params
        );
    }
}
