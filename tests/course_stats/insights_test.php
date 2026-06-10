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
 * PHPUnit tests for the course_stats engagement insights engine.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\course_stats;

use advanced_testcase;

/**
 * Test cases for local_resourcestats\course_stats\insights.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\course_stats\insights
 */
final class insights_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /** @var int[] IDs of two page-module course modules created in setUp. */
    private array $cmids;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();

        $this->cmids = [];
        for ($i = 0; $i < 2; $i++) {
            $page = $gen->create_module('page', ['course' => $this->course->id]);
            $cm = get_coursemodule_from_instance('page', $page->id, $this->course->id, false, MUST_EXIST);
            $this->cmids[] = (int)$cm->id;
        }
    }

    /**
     * Records one access in local_resourcestats_user_views for the given (cmid, userid) pair.
     *
     * @param int $cmid   Course module ID.
     * @param int $userid Student user ID.
     */
    private function record_access(int $cmid, int $userid): void {
        global $DB;
        $DB->insert_record('local_resourcestats_user_views', (object)[
            'cmid'          => $cmid,
            'userid'        => $userid,
            'viewcount'     => 1,
            'firstviewtime' => time(),
            'lastviewtime'  => time(),
        ]);
    }

    /**
     * Builds a minimal activity row as produced by controller::get_template_context().
     *
     * @param int    $cmid        Course module ID.
     * @param string $name        Activity name.
     * @param int    $uniqueviews Number of distinct students who accessed the activity.
     * @param int    $engpct      Engagement percentage (0–100).
     * @return array
     */
    private function make_row(int $cmid, string $name, int $uniqueviews, int $engpct): array {
        return [
            'cmid'          => $cmid,
            'activityname'  => $name,
            'uniqueviews'   => $uniqueviews,
            'engagementpct' => $engpct,
        ];
    }

    /**
     * An empty activity list produces no alerts regardless of student count.
     */
    public function test_no_activities_returns_no_alerts(): void {
        $alerts = (new insights([], 0))->get_alerts();
        $this->assertEmpty($alerts);
    }

    /**
     * All activities well above threshold and all students with access produce no alerts.
     */
    public function test_all_well_engaged_returns_no_alerts(): void {
        $gen = $this->getDataGenerator();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $this->course->id, 'student');
        $this->record_access($this->cmids[0], $student->id);
        $this->record_access($this->cmids[1], $student->id);

        $activities = [
            $this->make_row($this->cmids[0], 'Activity A', 1, 100),
            $this->make_row($this->cmids[1], 'Activity B', 1, 100),
        ];

        $alerts = (new insights($activities, 1))->get_alerts();
        $this->assertEmpty($alerts);
    }

    /**
     * A single unviewed activity produces exactly one danger alert using the singular
     * string. When another activity covers all enrolled students there is no zero-access
     * alert.
     */
    public function test_single_unviewed_activity_produces_singular_danger_alert(): void {
        $gen = $this->getDataGenerator();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $this->course->id, 'student');
        $this->record_access($this->cmids[0], $student->id);

        $activities = [
            $this->make_row($this->cmids[0], 'Lecture slides', 1, 100),
            $this->make_row($this->cmids[1], 'Bonus reading', 0, 0),
        ];

        $alerts = (new insights($activities, 1))->get_alerts();
        $this->assertCount(1, $alerts);
        $this->assertEquals('danger', $alerts[0]['type']);
        $this->assertEquals('fa-eye-slash', $alerts[0]['icon']);
        $this->assertStringContainsString('Bonus reading', $alerts[0]['message']);
        // Singular form must not start with "Activities".
        $this->assertStringNotContainsString('Activities not yet viewed', $alerts[0]['message']);
    }

    /**
     * Multiple unviewed activities are combined into a single plural danger alert whose
     * message contains all activity names.
     */
    public function test_multiple_unviewed_activities_grouped_into_one_alert(): void {
        $activities = [
            $this->make_row($this->cmids[0], 'Week 1', 0, 0),
            $this->make_row($this->cmids[1], 'Week 2', 0, 0),
        ];

        // Zero total students isolates the unviewed check (no zero-access or low-eng alerts).
        $alerts = (new insights($activities, 0))->get_alerts();

        $unviewed = array_values(array_filter($alerts, fn ($a) => $a['icon'] === 'fa-eye-slash'));
        $this->assertCount(1, $unviewed, 'Multiple unviewed activities must be grouped into one alert.');
        $this->assertStringContainsString('Week 1', $unviewed[0]['message']);
        $this->assertStringContainsString('Week 2', $unviewed[0]['message']);
        $this->assertStringContainsString('Activities not yet viewed', $unviewed[0]['message']);
    }

    /**
     * An activity with zero unique views is not also reported as low-engagement — the two
     * categories are mutually exclusive in the insights engine.
     */
    public function test_unviewed_activity_does_not_also_fire_low_engagement(): void {
        $activities = [
            $this->make_row($this->cmids[0], 'Empty lecture', 0, 0),
        ];

        // Zero total students keeps the scenario isolated.
        $alerts = (new insights($activities, 0))->get_alerts();

        $warnings = array_filter($alerts, fn ($a) => $a['type'] === 'warning');
        $this->assertEmpty($warnings, 'An unviewed activity must not also generate a low-engagement warning.');
    }

    /**
     * An activity whose engagement percentage is strictly below the configured threshold
     * triggers a warning alert containing the activity name.
     */
    public function test_activity_below_threshold_triggers_low_engagement_warning(): void {
        set_config('insight_loweng_pct', '50', 'local_resourcestats');

        $gen = $this->getDataGenerator();
        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $gen->enrol_user($s1->id, $this->course->id, 'student');
        $gen->enrol_user($s2->id, $this->course->id, 'student');

        // Both students have accessed cmids[0]; only s1 accessed cmids[1].
        // This ensures zerostudents = 0 (both users appear in the query).
        $this->record_access($this->cmids[0], $s1->id);
        $this->record_access($this->cmids[0], $s2->id);
        $this->record_access($this->cmids[1], $s1->id);

        $activities = [
            $this->make_row($this->cmids[0], 'Core material', 2, 100),
            $this->make_row($this->cmids[1], 'Optional reading', 1, 33),
        ];

        $alerts = (new insights($activities, 2))->get_alerts();
        $warnings = array_values(array_filter($alerts, fn ($a) => $a['type'] === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Optional reading', $warnings[0]['message']);
        $this->assertStringContainsString('low engagement', $warnings[0]['message']);
    }

    /**
     * An activity exactly at the threshold (not strictly below) must not trigger a warning.
     */
    public function test_activity_at_threshold_does_not_trigger_warning(): void {
        set_config('insight_loweng_pct', '50', 'local_resourcestats');

        $gen = $this->getDataGenerator();
        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $gen->enrol_user($s1->id, $this->course->id, 'student');
        $gen->enrol_user($s2->id, $this->course->id, 'student');
        $this->record_access($this->cmids[0], $s1->id);
        $this->record_access($this->cmids[0], $s2->id);

        $activities = [
            $this->make_row($this->cmids[0], 'Borderline activity', 1, 50),
        ];

        $alerts = (new insights($activities, 2))->get_alerts();
        $warnings = array_filter($alerts, fn ($a) => $a['type'] === 'warning');
        $this->assertEmpty($warnings);
    }

    /**
     * When exactly one student has not accessed any activity the singular alert message
     * is used.
     */
    public function test_single_student_with_no_access_uses_singular_message(): void {
        $gen = $this->getDataGenerator();
        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $gen->enrol_user($s1->id, $this->course->id, 'student');
        $gen->enrol_user($s2->id, $this->course->id, 'student');

        // Only s1 has a view record — s2 has never accessed anything.
        $this->record_access($this->cmids[0], $s1->id);

        $activities = [
            $this->make_row($this->cmids[0], 'Activity A', 1, 50),
        ];

        // 2 total students, 1 with access → zerostudents=1.
        $alerts = (new insights($activities, 2))->get_alerts();
        $zeroaccess = array_values(array_filter($alerts, fn ($a) => $a['icon'] === 'fa-user-times'));
        $this->assertCount(1, $zeroaccess);
        $this->assertStringContainsString('1 enrolled student', $zeroaccess[0]['message']);
    }

    /**
     * When two or more students have not accessed any activity the plural alert message
     * is used and the count is embedded in the message.
     */
    public function test_multiple_students_with_no_access_uses_plural_message(): void {
        $gen = $this->getDataGenerator();
        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $s3 = $gen->create_user();
        $gen->enrol_user($s1->id, $this->course->id, 'student');
        $gen->enrol_user($s2->id, $this->course->id, 'student');
        $gen->enrol_user($s3->id, $this->course->id, 'student');

        // Only s1 has a view record — s2 and s3 have never accessed anything.
        $this->record_access($this->cmids[0], $s1->id);

        $activities = [
            $this->make_row($this->cmids[0], 'Activity A', 1, 33),
        ];

        // 3 total students, 1 with access → zerostudents=2.
        $alerts = (new insights($activities, 3))->get_alerts();
        $zeroaccess = array_values(array_filter($alerts, fn ($a) => $a['icon'] === 'fa-user-times'));
        $this->assertCount(1, $zeroaccess);
        $this->assertStringContainsString('2 enrolled students', $zeroaccess[0]['message']);
    }
}
