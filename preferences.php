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
 * Display-mode preferences page for local_resourcestats.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_resourcestats\preferences\controller;

$returnurl = new moodle_url(optional_param('returnurl', '/my', PARAM_LOCALURL));

require_login();

$context = context_system::instance();
$controller = new controller($returnurl);

if (data_submitted()) {
    require_sesskey();
    $controller->handle_post();
}

$PAGE->set_url($controller->get_page_url());
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('preferences_title', 'local_resourcestats'));
$PAGE->set_heading(get_string('preferences_title', 'local_resourcestats'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_resourcestats/preferences', $controller->get_template_context());
echo $OUTPUT->footer();
