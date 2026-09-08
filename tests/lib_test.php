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
 * PHPUnit tests for lib.php's navigation-extension callbacks.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats;

use advanced_testcase;
use navigation_node;

/**
 * Test cases for the two navigation-extension functions in lib.php.
 *
 * @package    local_resourcestats
 * @covers ::local_resourcestats_extend_navigation_course
 * @covers ::local_resourcestats_extend_settings_navigation
 */
final class lib_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/resourcestats/lib.php');
    }

    /**
     * A user holding moodle/course:manageactivities must get a "Course statistics" node
     * pointing at course_stats.php for the given course.
     */
    public function test_extend_navigation_course_adds_node_for_manager(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $navigation = new navigation_node('Root');
        local_resourcestats_extend_navigation_course($navigation, $course, \context_course::instance($course->id));

        $added = $navigation->get('local_resourcestats_course');
        $this->assertNotFalse($added);
        $this->assertStringContainsString("courseid={$course->id}", $added->action->out(false));
    }

    /**
     * A student (no moodle/course:manageactivities) must get no such node.
     */
    public function test_extend_navigation_course_skips_students(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $navigation = new navigation_node('Root');
        local_resourcestats_extend_navigation_course($navigation, $course, \context_course::instance($course->id));

        $this->assertFalse($navigation->get('local_resourcestats_course'));
    }

    /**
     * Builds a real settings_navigation object for the given course module, the only way to
     * exercise local_resourcestats_extend_settings_navigation() as core actually calls it
     * (it looks up the tree's own 'modulesettings' node internally).
     *
     * @param \stdClass $course The owning course.
     * @param \stdClass $cm     The course module record.
     * @return \settings_navigation
     */
    private function build_settings_navigation(\stdClass $course, \stdClass $cm): \settings_navigation {
        global $PAGE;

        // A fresh page per call: once initialised, a moodle_page/settings_navigation pair can
        // carry over cached state from a previous call in the same test process (e.g. a node
        // added by an earlier test bleeding into this one's tree).
        $PAGE = new \moodle_page();
        $PAGE->set_cm($cm, $course);
        $PAGE->set_url('/course/modedit.php', ['update' => $cm->id]);
        $settingsnav = new \settings_navigation($PAGE);
        $settingsnav->initialise();

        return $settingsnav;
    }

    /**
     * A user holding moodle/course:manageactivities must get a "Statistics" node nested
     * under the module's own settings node, pointing at view_stats.php for that module.
     *
     * initialise() already dispatches this plugin's real extend_settings_navigation callback
     * itself (core discovers and calls it via the local-plugin-function cache, the same way it
     * would on a real page render) — calling the function again manually here would add the
     * node a second time and trigger a "node already exists" debugging() notice.
     */
    public function test_extend_settings_navigation_adds_node_for_manager(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $settingsnav = $this->build_settings_navigation($course, $cm);

        $added = $settingsnav->find('local_resourcestats_stats', navigation_node::TYPE_SETTING);
        $this->assertNotFalse($added);
        $this->assertStringContainsString("id={$cm->id}", $added->action->out(false));
    }

    /**
     * A student (no moodle/course:manageactivities) must get no such node.
     */
    public function test_extend_settings_navigation_skips_students(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $settingsnav = $this->build_settings_navigation($course, $cm);

        $this->assertFalse($settingsnav->find('local_resourcestats_stats', navigation_node::TYPE_SETTING));
    }

    /**
     * Labels have no dedicated view page and never fire course_module_viewed, so they must
     * never get a Statistics tab even for a user who otherwise holds the capability.
     */
    public function test_extend_settings_navigation_skips_labels(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $label = $generator->create_module('label', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('label', $label->id, $course->id, false, MUST_EXIST);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $settingsnav = $this->build_settings_navigation($course, $cm);

        $this->assertFalse($settingsnav->find('local_resourcestats_stats', navigation_node::TYPE_SETTING));
    }

    /**
     * A non-module context (e.g. the course context itself) must be a no-op — this plugin
     * only ever adds a settings node for an individual activity.
     */
    public function test_extend_settings_navigation_skips_non_module_context(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        // Initialise() dispatches this plugin's real callback itself with the course context
        // (core's own load_local_plugin_settings() call), so no manual invocation is needed —
        // the guard clause must return before this plugin's node type is ever created.
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_url('/course/edit.php', ['id' => $course->id]);
        $settingsnav = new \settings_navigation($PAGE);
        $settingsnav->initialise();

        $this->assertFalse($settingsnav->find('local_resourcestats_stats', navigation_node::TYPE_SETTING));
    }
}
