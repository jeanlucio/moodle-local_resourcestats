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
 * Tests that the names the statistics pages show are escaped exactly once.
 *
 * @package    local_resourcestats
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\local;

use advanced_testcase;
use local_resourcestats\course_stats\controller as course_controller;
use local_resourcestats\view_stats\controller as view_controller;

/**
 * A course, an activity or a person with an ampersand in the name must reach the page escaped once:
 * format_string() already returns HTML, so a double-mustache tag would print "&amp;amp;".
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_resourcestats\local\text
 * @covers     \local_resourcestats\course_stats\controller
 * @covers     \local_resourcestats\view_stats\controller
 */
final class text_test extends advanced_testcase {
    /**
     * Data provider: the two values of formatstringstriptags.
     *
     * @return array
     */
    public static function striptags_provider(): array {
        return ['strip tags on' => [1], 'strip tags off' => [0]];
    }

    /**
     * The plain text does not depend on the site setting.
     *
     * @dataProvider striptags_provider
     * @param int $striptags Value of formatstringstriptags.
     */
    public function test_plain_returns_the_text_as_typed(int $striptags): void {
        $this->resetAfterTest();
        set_config('formatstringstriptags', $striptags);

        $this->assertSame('Q & A > 3', text::plain('Q & A > 3'));
        $this->assertSame('Q & A', text::plain('<b>Q</b> &amp; A'));
        $this->assertSame('', text::plain(null));
    }

    /**
     * The course page shows the course and the activity names escaped once.
     *
     * @dataProvider striptags_provider
     * @param int $striptags Value of formatstringstriptags.
     */
    public function test_course_page_escapes_the_names_once(int $striptags): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        set_config('formatstringstriptags', $striptags);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Q & A > 3']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Tom & Jerry']);
        $sectionid = (int) $DB->get_field('course_modules', 'section', ['id' => $page->cmid], MUST_EXIST);
        $DB->set_field('course_sections', 'name', 'Physics & "Chemistry"', ['id' => $sectionid]);
        rebuild_course_cache($course->id, true);
        $context = \context_course::instance($course->id);
        $this->setAdminUser();

        $data = (new course_controller($course, $context))->get_template_context();
        $html = $PAGE->get_renderer('core')->render_from_template('local_resourcestats/course_stats_page', $data);

        $this->assertSame('Q & A > 3', $data['coursename']);
        $this->assertSame('Tom & Jerry', $data['activities'][0]['activityname']);
        $this->assertStringContainsString('Q &amp; A &gt; 3', $html);
        $this->assertStringContainsString('Tom &amp; Jerry', $html);
        $this->assertSame('Physics & "Chemistry"', $data['activities'][0]['section']);
        $this->assertStringContainsString('Physics &amp; &quot;Chemistry&quot;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * The activity page shows the activity name and the viewers' names escaped once.
     *
     * @dataProvider striptags_provider
     * @param int $striptags Value of formatstringstriptags.
     */
    public function test_activity_page_escapes_the_names_once(int $striptags): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        set_config('formatstringstriptags', $striptags);
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Tom & Jerry']);
        $student = $this->getDataGenerator()->create_and_enrol(
            $course,
            'student',
            ['firstname' => 'Ann', 'lastname' => "O'Brien & Co"]
        );
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);
        $now = time();
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm->id, 'userid' => $student->id, 'viewcount' => 2, 'firstviewtime' => $now, 'lastviewtime' => $now,
        ]);
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid' => $cm->id, 'totalviews' => 2, 'uniqueviews' => 1, 'lastviewtime' => $now,
            'lastuserid' => $student->id, 'deletedviews' => 0, 'deletedcount' => 0,
        ]);
        $this->setAdminUser();

        $data = (new view_controller($cm, \context_module::instance($cm->id)))->get_template_context();
        $html = $PAGE->get_renderer('core')->render_from_template('local_resourcestats/stats_page', $data);

        $this->assertSame('Tom & Jerry', $data['cmname']);
        $this->assertStringContainsString('Tom &amp; Jerry', $html);
        $this->assertStringContainsString('O&#039;Brien &amp; Co', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;#039;', $html);
    }
}
