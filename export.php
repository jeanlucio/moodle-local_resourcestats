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
 * Data export entry point for resource statistics.
 *
 * Supports two modes:
 *   - id (cmid): exports the per-student table for a single activity.
 *   - courseid:  exports the consolidated table for all activities in a course.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_resourcestats\course_stats\controller as course_controller;
use local_resourcestats\view_stats\controller as activity_controller;

$cmid     = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$format   = optional_param('format', 'csv', PARAM_ALPHANUMEXT);

// Export is a read-only GET request; sesskey is intentionally omitted.

if ($cmid > 0) {
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    $context = context_module::instance($cm->id);

    require_login($course, true, $cm);
    require_capability('moodle/course:manageactivities', $context);

    $controller = new activity_controller($cm, $context);
} else if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($course->id);

    require_login($course);
    require_capability('moodle/course:manageactivities', $context);

    $controller = new course_controller($course, $context);
} else {
    throw new \moodle_exception('invalidparameter', 'error');
}

[$filename, $columns, $rows] = $controller->get_rows_for_export();

\core\dataformat::download_data($filename, $format, $columns, $rows);
die();
