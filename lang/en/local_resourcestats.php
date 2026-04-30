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
 * English strings for local_resourcestats.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['badge_totalviews'] = 'Total accesses';
$string['badge_uniqueviews'] = 'Students who accessed';
$string['col_accesses'] = 'Accesses';
$string['col_firstaccess'] = 'First access';
$string['col_lastaccess'] = 'Last access';
$string['col_student'] = 'Student';
$string['col_total'] = 'Total';
$string['default'] = 'Default';
$string['deleted_students'] = '{$a} deleted student(s)';
$string['invalidmode'] = 'Invalid display mode.';
$string['lastviewedby'] = 'Last viewed by';
$string['lastviewtime'] = 'Last view date';
$string['mode_both'] = 'Show both badges — total accesses and unique students';
$string['mode_none'] = 'Show nothing';
$string['mode_total'] = 'Total accesses only (counts repeated visits by the same student)';
$string['mode_unique'] = 'Number of students who accessed at least once';
$string['neverviewed'] = 'Never viewed';
$string['pluginname'] = 'Resource Statistics';
$string['preferences_desc'] = 'Choose which information to display below each resource or activity on the course page.';
$string['preferences_title'] = 'Resource statistics display';
$string['privacy:metadata:local_resourcestats_user_views'] = 'Stores per-student access counts and timestamps for each course module.';
$string['privacy:metadata:local_resourcestats_user_views:cmid'] = 'The course module ID.';
$string['privacy:metadata:local_resourcestats_user_views:firstviewtime'] = 'The timestamp of the student\'s first access.';
$string['privacy:metadata:local_resourcestats_user_views:lastviewtime'] = 'The timestamp of the student\'s most recent access.';
$string['privacy:metadata:local_resourcestats_user_views:userid'] = 'The ID of the student who accessed the module.';
$string['privacy:metadata:local_resourcestats_user_views:viewcount'] = 'The number of times this student accessed the module.';
$string['privacy:metadata:local_resourcestats_views'] = 'Stores the total views and the last user who accessed a specific course module.';
$string['privacy:metadata:local_resourcestats_views:cmid'] = 'The course module ID.';
$string['privacy:metadata:local_resourcestats_views:lastuserid'] = 'The ID of the last user to view the module.';
$string['privacy:metadata:local_resourcestats_views:lastviewtime'] = 'The timestamp of the last view.';
$string['setting_defaultmode'] = 'Default display mode';
$string['setting_defaultmode_desc'] = 'Site-wide default display mode for teachers who have not yet set a personal preference. Individual teachers can override this on the Statistics preferences page.';
$string['statistics'] = 'Statistics';
$string['unique_students'] = '{$a} unique student(s)';
$string['views'] = 'Views';
