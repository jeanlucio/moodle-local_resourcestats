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
 * Event observer for module views.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats;

use context_course;
use stdClass;

/**
 * Class observer
 *
 * @package local_resourcestats
 */
class observer {
    /**
     * Handles the course_module_viewed event to update view statistics.
     *
     * Skips guest users and any user who holds the manageactivities
     * capability at the course level (teachers, managers, admins).
     * Tracks both total accesses and distinct-student (unique) views.
     *
     * @param \core\event\base $event The triggered event.
     * @throws \dml_exception
     */
    public static function module_viewed(\core\event\base $event): void {
        global $DB;

        $cmid = $event->contextinstanceid;
        $userid = $event->userid;
        $courseid = $event->courseid;
        $time = $event->timecreated;

        if (empty($cmid) || empty($userid) || empty($courseid) || isguestuser($userid)) {
            return;
        }

        $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            return;
        }

        if (has_capability('moodle/course:manageactivities', $coursecontext, $userid)) {
            return;
        }

        $userviewrecord = $DB->get_record('local_resourcestats_user_views', ['cmid' => $cmid, 'userid' => $userid]);
        $isunique = ($userviewrecord === false);

        if ($isunique) {
            $newuserrecord = new stdClass();
            $newuserrecord->cmid = $cmid;
            $newuserrecord->userid = $userid;
            $newuserrecord->viewcount = 1;
            $newuserrecord->firstviewtime = $time;
            $newuserrecord->lastviewtime = $time;
            $DB->insert_record('local_resourcestats_user_views', $newuserrecord);
        } else {
            $userviewrecord->viewcount++;
            $userviewrecord->lastviewtime = $time;
            $DB->update_record('local_resourcestats_user_views', $userviewrecord);
        }

        $record = $DB->get_record('local_resourcestats_views', ['cmid' => $cmid]);

        if ($record) {
            $record->totalviews++;
            if ($isunique) {
                $record->uniqueviews++;
            }
            $record->lastuserid = $userid;
            $record->lastviewtime = $time;
            $DB->update_record('local_resourcestats_views', $record);
        } else {
            $newrecord = new stdClass();
            $newrecord->cmid = $cmid;
            $newrecord->totalviews = 1;
            $newrecord->uniqueviews = 1;
            $newrecord->lastuserid = $userid;
            $newrecord->lastviewtime = $time;
            $DB->insert_record('local_resourcestats_views', $newrecord);
        }
    }

    /**
     * Handles the course_module_deleted event by removing that module's statistics.
     *
     * Without this, rows in both tables become orphaned once the module (and its context)
     * are gone, which also makes them unreachable from the Privacy API: get_contexts_for_userid()
     * joins on {context} by cmid, and a deleted module has no context row left to match.
     *
     * @param \core\event\course_module_deleted $event The triggered event.
     * @throws \dml_exception
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        $cmid = $event->objectid;
        $DB->delete_records('local_resourcestats_user_views', ['cmid' => $cmid]);
        $DB->delete_records('local_resourcestats_views', ['cmid' => $cmid]);
    }

    /**
     * Handles the course_deleted event as a safety net against orphaned statistics.
     *
     * Deleting a course normally deletes each of its modules first, which already triggers
     * course_module_deleted for every cmid. This purges any row left behind regardless of
     * cause (a partially-failed course deletion, or data that predates these observers).
     *
     * @param \core\event\course_deleted $event The triggered event.
     * @throws \dml_exception
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        self::purge_orphaned_rows();
    }

    /**
     * Deletes statistics rows whose course module no longer exists.
     *
     * Shared between the course_deleted observer and the upgrade step that cleans up rows
     * orphaned before these observers existed.
     *
     * @throws \dml_exception
     */
    public static function purge_orphaned_rows(): void {
        global $DB;

        $orphansql = 'cmid NOT IN (SELECT cm.id FROM {course_modules} cm)';
        $DB->delete_records_select('local_resourcestats_user_views', $orphansql);
        $DB->delete_records_select('local_resourcestats_views', $orphansql);
    }
}
