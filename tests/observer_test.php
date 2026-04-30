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
 * PHPUnit tests for the event observer.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\tests;

use advanced_testcase;
use local_resourcestats\observer;

/**
 * Test cases for local_resourcestats\observer.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\observer
 */
final class observer_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /** @var \stdClass Course module record for the test page resource. */
    private \stdClass $cm;

    /** @var \stdClass Student enrolled in $course. */
    private \stdClass $student;

    /** @var \stdClass Teacher enrolled with editing role. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance(
            'page',
            $page->id,
            $this->course->id,
            false,
            MUST_EXIST
        );
        $this->student = $generator->create_user();
        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
    }

    /**
     * Fires course_module_viewed directly to the observer for the given user.
     *
     * @param \stdClass      $user User performing the view.
     * @param \stdClass|null $cm   Course module; defaults to $this->cm.
     */
    private function view_module(\stdClass $user, ?\stdClass $cm = null): void {
        $cm = $cm ?? $this->cm;
        $this->setUser($user);
        $context = \context_module::instance($cm->id);
        $event = \mod_page\event\course_module_viewed::create([
            'context'  => $context,
            'objectid' => $cm->instance,
        ]);
        observer::module_viewed($event);
    }

    /**
     * Guest users must never be tracked.
     */
    public function test_guest_user_is_skipped(): void {
        global $DB, $CFG;
        $guest = \core_user::get_user($CFG->siteguest);
        $this->view_module($guest);
        $this->assertFalse(
            $DB->record_exists('local_resourcestats_views', ['cmid' => $this->cm->id])
        );
    }

    /**
     * Users with manageactivities capability (teachers, managers) must never be tracked.
     */
    public function test_teacher_is_skipped(): void {
        global $DB;
        $this->view_module($this->teacher);
        $this->assertFalse(
            $DB->record_exists('local_resourcestats_views', ['cmid' => $this->cm->id])
        );
    }

    /**
     * A student's first view must create one row in each statistics table.
     */
    public function test_first_access_creates_both_records(): void {
        global $DB;
        $this->view_module($this->student);
        $this->assertTrue(
            $DB->record_exists('local_resourcestats_views', ['cmid' => $this->cm->id])
        );
        $this->assertTrue($DB->record_exists(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $this->student->id]
        ));
    }

    /**
     * On first access firstviewtime and lastviewtime must be identical and non-zero.
     */
    public function test_first_access_sets_equal_timestamps(): void {
        global $DB;
        $this->view_module($this->student);
        $record = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $this->student->id]
        );
        $this->assertNotEmpty($record->firstviewtime);
        $this->assertEquals($record->firstviewtime, $record->lastviewtime);
    }

    /**
     * Each subsequent view by the same student must increment viewcount.
     */
    public function test_repeat_access_increments_viewcount(): void {
        global $DB;
        $this->view_module($this->student);
        $this->view_module($this->student);
        $record = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $this->student->id]
        );
        $this->assertEquals(2, (int) $record->viewcount);
    }

    /**
     * firstviewtime must not change on a subsequent access.
     */
    public function test_repeat_access_preserves_first_timestamp(): void {
        global $DB;
        $this->view_module($this->student);

        // Backdate firstviewtime so we can assert it remains unchanged.
        $past = time() - 3600;
        $DB->set_field(
            'local_resourcestats_user_views',
            'firstviewtime',
            $past,
            ['cmid' => $this->cm->id, 'userid' => $this->student->id]
        );

        $this->view_module($this->student);

        $record = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $this->student->id]
        );
        $this->assertEquals($past, (int) $record->firstviewtime);
    }

    /**
     * lastviewtime must be updated on each subsequent access.
     */
    public function test_repeat_access_updates_last_timestamp(): void {
        global $DB;
        $this->view_module($this->student);

        // Backdate lastviewtime to confirm it gets updated on the next view.
        $past = time() - 3600;
        $DB->set_field(
            'local_resourcestats_user_views',
            'lastviewtime',
            $past,
            ['cmid' => $this->cm->id, 'userid' => $this->student->id]
        );

        $this->view_module($this->student);

        $record = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $this->student->id]
        );
        $this->assertGreaterThan($past, (int) $record->lastviewtime);
    }

    /**
     * uniqueviews must only increase on the first view by each distinct student.
     */
    public function test_unique_count_increments_only_on_first_access(): void {
        global $DB;
        $this->view_module($this->student);
        $this->view_module($this->student);
        $this->view_module($this->student);
        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);
        $this->assertEquals(1, (int) $aggregate->uniqueviews);
    }

    /**
     * totalviews must increase on every access, including repeats by the same student.
     */
    public function test_total_count_increments_every_access(): void {
        global $DB;
        $this->view_module($this->student);
        $this->view_module($this->student);
        $this->view_module($this->student);
        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);
        $this->assertEquals(3, (int) $aggregate->totalviews);
    }

    /**
     * Two students must be tracked independently without interfering with each other.
     */
    public function test_two_students_tracked_independently(): void {
        global $DB;
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student2->id, $this->course->id, 'student');

        $this->view_module($this->student);
        $this->view_module($this->student);
        $this->view_module($student2);

        $record1 = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $this->student->id]
        );
        $record2 = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $student2->id]
        );

        $this->assertEquals(2, (int) $record1->viewcount);
        $this->assertEquals(1, (int) $record2->viewcount);

        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);
        $this->assertEquals(3, (int) $aggregate->totalviews);
        $this->assertEquals(2, (int) $aggregate->uniqueviews);
    }
}
