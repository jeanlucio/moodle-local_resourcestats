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
 * Course-level statistics overview page.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_resourcestats\course_stats\controller;

$courseid = required_param('courseid', PARAM_INT);
$sort     = optional_param('sort', 'activityname', PARAM_ALPHA);
$dir      = optional_param('dir', 'asc', PARAM_ALPHA);
$page     = optional_param('page', 0, PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('moodle/course:manageactivities', $context);

$controller = new controller($course, $context, $sort, $dir, $page);

$PAGE->set_url(new moodle_url('/local/resourcestats/course_stats.php', [
    'courseid' => $courseid,
    'sort'     => $sort,
    'dir'      => $dir,
    'page'     => $page,
]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('course_statistics', 'local_resourcestats'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_resourcestats/course_stats_page', $controller->get_template_context());
echo $OUTPUT->footer();
