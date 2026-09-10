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
use local_resourcestats\hook_listener;

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
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
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
                'totalviews'   => 'privacy:metadata:local_resourcestats_views:totalviews',
                'uniqueviews'  => 'privacy:metadata:local_resourcestats_views:uniqueviews',
                'lastuserid'   => 'privacy:metadata:local_resourcestats_views:lastuserid',
                'lastviewtime' => 'privacy:metadata:local_resourcestats_views:lastviewtime',
                'deletedviews' => 'privacy:metadata:local_resourcestats_views:deletedviews',
                'deletedcount' => 'privacy:metadata:local_resourcestats_views:deletedcount',
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

        $collection->add_user_preference(
            hook_listener::PREF_SHOW_TOTAL,
            'privacy:metadata:preference:show_total'
        );
        $collection->add_user_preference(
            hook_listener::PREF_SHOW_UNIQUE,
            'privacy:metadata:preference:show_unique'
        );
        $collection->add_user_preference(
            hook_listener::PREF_SHOW_LASTUSER,
            'privacy:metadata:preference:show_lastuser'
        );
        $collection->add_user_preference(
            hook_listener::PREF_SHOW_COMPLETED,
            'privacy:metadata:preference:show_completed'
        );
        $collection->add_user_preference(
            hook_listener::PREF_SHOW_PASSED,
            'privacy:metadata:preference:show_passed'
        );

        return $collection;
    }

    /**
     * Exports the user preferences stored by this plugin for the given user.
     *
     * @param int $userid The user whose preferences are exported.
     */
    public static function export_user_preferences(int $userid): void {
        $preferences = [
            hook_listener::PREF_SHOW_TOTAL    => 'privacy:metadata:preference:show_total',
            hook_listener::PREF_SHOW_UNIQUE   => 'privacy:metadata:preference:show_unique',
            hook_listener::PREF_SHOW_LASTUSER => 'privacy:metadata:preference:show_lastuser',
            hook_listener::PREF_SHOW_COMPLETED => 'privacy:metadata:preference:show_completed',
            hook_listener::PREF_SHOW_PASSED   => 'privacy:metadata:preference:show_passed',
        ];

        foreach ($preferences as $name => $description) {
            $value = get_user_preferences($name, null, $userid);
            if ($value !== null) {
                writer::export_user_preference(
                    'local_resourcestats',
                    $name,
                    $value,
                    get_string($description, 'local_resourcestats')
                );
            }
        }
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
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        $contextsbycmid = [];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_MODULE) {
                $contextsbycmid[$context->instanceid] = $context;
            }
        }

        if (empty($contextsbycmid)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($contextsbycmid), SQL_PARAMS_NAMED, 'cm');
        $params = array_merge(['userid' => $userid], $inparams);

        $records = $DB->get_records_select(
            'local_resourcestats_user_views',
            "userid = :userid AND cmid $insql",
            $params
        );

        foreach ($records as $record) {
            writer::with_context($contextsbycmid[$record->cmid])->export_data(
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
     * Deletes the student's rows from local_resourcestats_user_views and transfers
     * each viewcount to the matching module's deletedviews/deletedcount aggregate
     * columns so that statistics totals remain consistent. This avoids storing
     * nullable userids in a unique-indexed column, which would break on SQL Server.
     *
     * @param approved_contextlist $contextlist The list of approved contexts.
     * @throws \dml_exception
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        $cmids = [];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_MODULE) {
                $cmids[] = $context->instanceid;
            }
        }

        if (empty($cmids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        $params = array_merge(['userid' => $userid], $inparams);

        $userrecords = $DB->get_records_select(
            'local_resourcestats_user_views',
            "userid = :userid AND cmid $insql",
            $params
        );

        if (!empty($userrecords)) {
            $matchedcmids = array_unique(array_column($userrecords, 'cmid'));
            [$matchedinsql, $matchedinparams] = $DB->get_in_or_equal($matchedcmids, SQL_PARAMS_NAMED, 'ag');

            $aggregatesbycmid = [];
            foreach ($DB->get_records_select('local_resourcestats_views', "cmid $matchedinsql", $matchedinparams) as $agg) {
                $aggregatesbycmid[$agg->cmid] = $agg;
            }

            foreach ($userrecords as $userrecord) {
                $aggregate = $aggregatesbycmid[$userrecord->cmid] ?? null;
                if ($aggregate) {
                    $aggregate->deletedviews += (int)$userrecord->viewcount;
                    $aggregate->deletedcount += 1;
                    $DB->update_record('local_resourcestats_views', $aggregate);
                }
            }

            $DB->delete_records_select(
                'local_resourcestats_user_views',
                "userid = :userid AND cmid $insql",
                $params
            );
        }

        $DB->set_field_select(
            'local_resourcestats_views',
            'lastuserid',
            null,
            "lastuserid = :userid AND cmid $insql",
            $params
        );
    }

    /**
     * Deletes data for multiple users within a single context.
     *
     * Transfers the combined viewcount of all approved users to the aggregate
     * deletedviews/deletedcount columns and deletes their individual rows.
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
        $where = "cmid = :cmid AND userid $insql";

        $viewcounts = $DB->get_fieldset_select(
            'local_resourcestats_user_views',
            'viewcount',
            $where,
            $params
        );

        if (!empty($viewcounts)) {
            $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $cmid]);
            if ($aggregate) {
                $aggregate->deletedviews += (int)array_sum($viewcounts);
                $aggregate->deletedcount += count($viewcounts);
                $DB->update_record('local_resourcestats_views', $aggregate);
            }
            $DB->delete_records_select('local_resourcestats_user_views', $where, $params);
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
