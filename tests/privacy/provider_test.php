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
 * PHPUnit tests for the Privacy API provider.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_resourcestats\privacy\provider;

/**
 * Test cases for local_resourcestats\privacy\provider.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /** @var \stdClass Course module record. */
    private \stdClass $cm;

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
    }

    /**
     * Inserts a row into local_resourcestats_user_views for testing.
     *
     * @param int      $userid     Student user ID.
     * @param int      $viewcount  Number of accesses.
     * @param int|null $firsttime  Timestamp of first access; defaults to now.
     * @param int|null $lasttime   Timestamp of last access; defaults to now.
     */
    private function insert_user_view(
        int $userid,
        int $viewcount = 1,
        ?int $firsttime = null,
        ?int $lasttime = null
    ): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid'          => $this->cm->id,
            'userid'        => $userid,
            'viewcount'     => $viewcount,
            'firstviewtime' => $firsttime ?? $now,
            'lastviewtime'  => $lasttime ?? $now,
        ]);
    }

    /**
     * get_contexts_for_userid must return the module context for a student who accessed it.
     */
    public function test_get_contexts_for_userid(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->insert_user_view($student->id, 3);

        $contextlist = provider::get_contexts_for_userid($student->id);

        $this->assertCount(1, $contextlist);
        // Returned context IDs may be strings on PostgreSQL; cast both sides to int.
        $this->assertContains(
            (int) \context_module::instance($this->cm->id)->id,
            array_map('intval', $contextlist->get_contextids())
        );
    }

    /**
     * get_users_in_context must return every student who has a row in user_views,
     * not only the last user recorded in the aggregate table.
     */
    public function test_get_users_in_context(): void {
        $generator = $this->getDataGenerator();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $this->insert_user_view($s1->id);
        $this->insert_user_view($s2->id);

        $context = \context_module::instance($this->cm->id);
        $userlist = new userlist($context, 'local_resourcestats');
        provider::get_users_in_context($userlist);

        // Get_userids() and user IDs may differ in type across DB drivers; normalise to int.
        $userids = array_map('intval', $userlist->get_userids());
        $this->assertCount(2, $userids);
        $this->assertContains((int) $s1->id, $userids);
        $this->assertContains((int) $s2->id, $userids);
    }

    /**
     * export_user_data must write viewcount and timestamps for the given user.
     */
    public function test_export_user_data(): void {
        $student = $this->getDataGenerator()->create_user();
        $firsttime = mktime(10, 0, 0, 4, 1, 2026);
        $lasttime  = mktime(15, 0, 0, 4, 28, 2026);
        $this->insert_user_view($student->id, 5, $firsttime, $lasttime);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $approvedlist = new approved_contextlist(
            $student,
            'local_resourcestats',
            $contextlist->get_contextids()
        );
        provider::export_user_data($approvedlist);

        $context = \context_module::instance($this->cm->id);
        $data = writer::with_context($context)
            ->get_data([get_string('pluginname', 'local_resourcestats')]);
        $this->assertNotEmpty($data);
        $this->assertEquals(5, $data->viewcount);
    }

    /**
     * Inserts a row into local_resourcestats_views for testing.
     *
     * @param int      $totalviews   Total view count.
     * @param int      $uniqueviews  Unique student count.
     * @param int|null $lastuserid   ID of the last user.
     * @param int      $deletedviews Accumulated views from GDPR-erased students.
     * @param int      $deletedcount Number of GDPR-erased students.
     */
    private function insert_aggregate(
        int $totalviews = 0,
        int $uniqueviews = 0,
        ?int $lastuserid = null,
        int $deletedviews = 0,
        int $deletedcount = 0
    ): void {
        global $DB;
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid'         => $this->cm->id,
            'totalviews'   => $totalviews,
            'uniqueviews'  => $uniqueviews,
            'lastuserid'   => $lastuserid,
            'lastviewtime' => time(),
            'deletedviews' => $deletedviews,
            'deletedcount' => $deletedcount,
        ]);
    }

    /**
     * delete_data_for_user must delete the row and transfer viewcount to the
     * aggregate deletedviews/deletedcount columns.
     */
    public function test_delete_removes_row_and_updates_aggregate(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->insert_user_view($student->id, 7);
        $this->insert_aggregate(7, 1, $student->id);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $approvedlist = new approved_contextlist(
            $student,
            'local_resourcestats',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approvedlist);

        $this->assertEquals(
            0,
            $DB->count_records('local_resourcestats_user_views', ['cmid' => $this->cm->id])
        );

        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);
        $this->assertNotFalse($aggregate);
        $this->assertEquals(7, (int) $aggregate->deletedviews);
        $this->assertEquals(1, (int) $aggregate->deletedcount);
        $this->assertNull($aggregate->lastuserid);
    }

    /**
     * Deleting one student must leave other students' rows untouched and only
     * accumulate the erased student's viewcount in the aggregate.
     */
    public function test_delete_does_not_affect_other_users(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $this->insert_user_view($s1->id, 3);
        $this->insert_user_view($s2->id, 5);
        $this->insert_aggregate(8, 2, $s2->id);

        $contextlist = provider::get_contexts_for_userid($s1->id);
        $approvedlist = new approved_contextlist(
            $s1,
            'local_resourcestats',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approvedlist);

        $s2record = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $s2->id]
        );
        $this->assertNotFalse($s2record);
        $this->assertEquals(5, (int) $s2record->viewcount);

        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);
        $this->assertEquals(3, (int) $aggregate->deletedviews);
        $this->assertEquals(1, (int) $aggregate->deletedcount);
    }

    /**
     * delete_data_for_all_users_in_context must wipe both statistics tables for
     * that module completely.
     */
    public function test_delete_all_users_in_context_wipes_both_tables(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $this->insert_user_view($s1->id);
        $this->insert_user_view($s2->id);
        $this->insert_aggregate(2, 2, $s2->id);

        $context = \context_module::instance($this->cm->id);
        provider::delete_data_for_all_users_in_context($context);

        $this->assertEquals(
            0,
            $DB->count_records('local_resourcestats_user_views', ['cmid' => $this->cm->id])
        );
        $this->assertEquals(
            0,
            $DB->count_records('local_resourcestats_views', ['cmid' => $this->cm->id])
        );
    }

    /**
     * delete_data_for_users must delete only the approved users' rows, accumulate
     * their viewcounts into the aggregate, and leave non-approved users intact.
     */
    public function test_bulk_delete_removes_approved_rows_and_updates_aggregate(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $s3 = $generator->create_user();
        $this->insert_user_view($s1->id, 2);
        $this->insert_user_view($s2->id, 4);
        $this->insert_user_view($s3->id, 1);
        $this->insert_aggregate(7, 3, $s3->id);

        $context = \context_module::instance($this->cm->id);
        $approveduserlist = new approved_userlist(
            $context,
            'local_resourcestats',
            [$s1->id, $s2->id]
        );
        provider::delete_data_for_users($approveduserlist);

        // Rows for s1 and s2 must be gone.
        $this->assertFalse(
            $DB->record_exists('local_resourcestats_user_views', ['cmid' => $this->cm->id, 'userid' => $s1->id])
        );
        $this->assertFalse(
            $DB->record_exists('local_resourcestats_user_views', ['cmid' => $this->cm->id, 'userid' => $s2->id])
        );

        // Student s3 must be untouched.
        $s3record = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $s3->id]
        );
        $this->assertNotFalse($s3record);
        $this->assertEquals(1, (int) $s3record->viewcount);

        // Aggregate must reflect s1+s2 viewcounts (2+4=6) and count=2.
        $aggregate = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);
        $this->assertEquals(6, (int) $aggregate->deletedviews);
        $this->assertEquals(2, (int) $aggregate->deletedcount);
    }

    /**
     * export_user_data must correctly export a student's row for every approved module
     * context, not only the first one.
     */
    public function test_export_user_data_covers_every_approved_context(): void {
        $generator = $this->getDataGenerator();
        $student = $generator->create_user();

        $page2 = $generator->create_module('page', ['course' => $this->course->id]);
        $cm2 = get_coursemodule_from_instance('page', $page2->id, $this->course->id, false, MUST_EXIST);

        $this->insert_user_view($student->id, 5);
        global $DB;
        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm2->id, 'userid' => $student->id, 'viewcount' => 9,
            'firstviewtime' => time(), 'lastviewtime' => time(),
        ]);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $this->assertCount(2, $contextlist);

        $approvedlist = new approved_contextlist(
            $student,
            'local_resourcestats',
            $contextlist->get_contextids()
        );
        provider::export_user_data($approvedlist);

        $data1 = writer::with_context(\context_module::instance($this->cm->id))
            ->get_data([get_string('pluginname', 'local_resourcestats')]);
        $data2 = writer::with_context(\context_module::instance($cm2->id))
            ->get_data([get_string('pluginname', 'local_resourcestats')]);

        $this->assertEquals(5, $data1->viewcount);
        $this->assertEquals(9, $data2->viewcount);
    }

    /**
     * export_user_data must not issue roughly one query per approved context.
     *
     * Regression guard for the N+1: a get_record() call per context inside a loop would
     * make the query count grow with the number of modules the student accessed; the
     * batched get_in_or_equal() approach costs a small constant number of queries.
     */
    public function test_export_user_data_does_not_scale_with_context_count(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $student = $generator->create_user();

        $this->insert_user_view($student->id, 1);
        $contextlist = provider::get_contexts_for_userid($student->id);
        $approvedlist = new approved_contextlist($student, 'local_resourcestats', $contextlist->get_contextids());

        $before = $DB->perf_get_queries();
        provider::export_user_data($approvedlist);
        $querieswithone = $DB->perf_get_queries() - $before;

        for ($i = 0; $i < 20; $i++) {
            $page = $generator->create_module('page', ['course' => $this->course->id]);
            $cm = get_coursemodule_from_instance('page', $page->id, $this->course->id, false, MUST_EXIST);
            $DB->insert_record('local_resourcestats_user_views', (object) [
                'cmid' => $cm->id, 'userid' => $student->id, 'viewcount' => 1,
                'firstviewtime' => time(), 'lastviewtime' => time(),
            ]);
        }

        $contextlist = provider::get_contexts_for_userid($student->id);
        $approvedlist = new approved_contextlist($student, 'local_resourcestats', $contextlist->get_contextids());

        $before = $DB->perf_get_queries();
        provider::export_user_data($approvedlist);
        $querieswithmany = $DB->perf_get_queries() - $before;

        // 20 extra contexts must not add anywhere near 20 extra queries.
        $this->assertLessThan(10, $querieswithmany - $querieswithone);
    }

    /**
     * delete_data_for_user must correctly update each affected module's own aggregate
     * row when the student accessed more than one module.
     */
    public function test_delete_data_for_user_updates_every_affected_aggregate(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $student = $generator->create_user();

        $page2 = $generator->create_module('page', ['course' => $this->course->id]);
        $cm2 = get_coursemodule_from_instance('page', $page2->id, $this->course->id, false, MUST_EXIST);

        $this->insert_user_view($student->id, 3);
        $this->insert_aggregate(3, 1, $student->id);

        $DB->insert_record('local_resourcestats_user_views', (object) [
            'cmid' => $cm2->id, 'userid' => $student->id, 'viewcount' => 6,
            'firstviewtime' => time(), 'lastviewtime' => time(),
        ]);
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid' => $cm2->id, 'totalviews' => 6, 'uniqueviews' => 1,
            'lastuserid' => $student->id, 'lastviewtime' => time(),
            'deletedviews' => 0, 'deletedcount' => 0,
        ]);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $this->assertCount(2, $contextlist);

        $approvedlist = new approved_contextlist(
            $student,
            'local_resourcestats',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approvedlist);

        $this->assertEquals(
            0,
            $DB->count_records('local_resourcestats_user_views', ['userid' => $student->id])
        );

        $aggregate1 = $DB->get_record('local_resourcestats_views', ['cmid' => $this->cm->id]);
        $this->assertEquals(3, (int) $aggregate1->deletedviews);
        $this->assertEquals(1, (int) $aggregate1->deletedcount);
        $this->assertNull($aggregate1->lastuserid);

        $aggregate2 = $DB->get_record('local_resourcestats_views', ['cmid' => $cm2->id]);
        $this->assertEquals(6, (int) $aggregate2->deletedviews);
        $this->assertEquals(1, (int) $aggregate2->deletedcount);
        $this->assertNull($aggregate2->lastuserid);
    }

    /**
     * export_user_preferences must export the per-user column display choices.
     */
    public function test_export_user_preferences(): void {
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('local_resourcestats_show_total', '1', $user);
        set_user_preference('local_resourcestats_show_unique', '0', $user);
        set_user_preference('local_resourcestats_show_lastuser', '1', $user);

        provider::export_user_preferences($user->id);

        // The writer exports user preferences against the system context.
        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());

        $prefs = $writer->get_user_preferences('local_resourcestats');
        $this->assertEquals('1', $prefs->local_resourcestats_show_total->value);
        $this->assertEquals('0', $prefs->local_resourcestats_show_unique->value);
        $this->assertEquals('1', $prefs->local_resourcestats_show_lastuser->value);
    }

    /**
     * get_metadata must declare both plugin tables, with every real column each table
     * actually has, plus all three per-user display preferences.
     *
     * Compares against $DB->get_columns() rather than a hand-picked list of keys: a
     * per-key assertion would not fail if a column were silently added to install.xml
     * without a matching metadata entry, which is exactly the drift this guards against.
     */
    public function test_get_metadata_declares_expected_items(): void {
        global $DB;

        $result = provider::get_metadata(new collection('local_resourcestats'));
        $items = $result->get_collection();

        $itemsbyname = [];
        foreach ($items as $item) {
            $itemsbyname[$item->get_name()] = $item;
        }

        foreach (['local_resourcestats_views', 'local_resourcestats_user_views'] as $table) {
            $this->assertArrayHasKey($table, $itemsbyname);

            $realcolumns = array_keys($DB->get_columns($table));
            $realcolumns = array_values(array_diff($realcolumns, ['id']));

            $this->assertEqualsCanonicalizing(
                $realcolumns,
                array_keys($itemsbyname[$table]->get_privacy_fields()),
                "Declared privacy fields for {$table} must match its real columns."
            );
        }

        $this->assertArrayHasKey('local_resourcestats_show_total', $itemsbyname);
        $this->assertArrayHasKey('local_resourcestats_show_unique', $itemsbyname);
        $this->assertArrayHasKey('local_resourcestats_show_lastuser', $itemsbyname);
    }

    /**
     * get_users_in_context must ignore a userlist scoped to a non-module context — this
     * plugin only ever stores data against module contexts.
     */
    public function test_get_users_in_context_ignores_non_module_context(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->insert_user_view($student->id);

        $coursecontext = \context_course::instance($this->course->id);
        $userlist = new userlist($coursecontext, 'local_resourcestats');
        provider::get_users_in_context($userlist);

        $this->assertCount(0, $userlist->get_userids());
    }

    /**
     * export_user_data must write nothing when the approved contextlist contains only a
     * non-module context.
     */
    public function test_export_user_data_ignores_non_module_contexts(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->insert_user_view($student->id, 5);

        $approvedlist = new approved_contextlist(
            $student,
            'local_resourcestats',
            [\context_course::instance($this->course->id)->id]
        );
        provider::export_user_data($approvedlist);

        $context = \context_module::instance($this->cm->id);
        $data = writer::with_context($context)
            ->get_data([get_string('pluginname', 'local_resourcestats')]);
        $this->assertEmpty($data);
    }

    /**
     * delete_data_for_all_users_in_context must leave the plugin's tables untouched when
     * given a non-module context.
     */
    public function test_delete_data_for_all_users_in_context_ignores_non_module_context(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->insert_user_view($student->id);
        $this->insert_aggregate(1, 1, $student->id);

        provider::delete_data_for_all_users_in_context(\context_course::instance($this->course->id));

        $this->assertEquals(1, $DB->count_records('local_resourcestats_user_views', ['cmid' => $this->cm->id]));
        $this->assertEquals(1, $DB->count_records('local_resourcestats_views', ['cmid' => $this->cm->id]));
    }

    /**
     * delete_data_for_user must leave the student's row untouched when the approved
     * contextlist contains only a non-module context.
     */
    public function test_delete_data_for_user_ignores_non_module_contexts(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->insert_user_view($student->id, 7);

        $approvedlist = new approved_contextlist(
            $student,
            'local_resourcestats',
            [\context_course::instance($this->course->id)->id]
        );
        provider::delete_data_for_user($approvedlist);

        $this->assertEquals(1, $DB->count_records('local_resourcestats_user_views', ['cmid' => $this->cm->id]));
    }

    /**
     * delete_data_for_users must leave every row untouched when the approved userlist is
     * scoped to a non-module context.
     */
    public function test_delete_data_for_users_ignores_non_module_context(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->insert_user_view($student->id, 7);

        $approveduserlist = new approved_userlist(
            \context_course::instance($this->course->id),
            'local_resourcestats',
            [$student->id]
        );
        provider::delete_data_for_users($approveduserlist);

        $this->assertEquals(1, $DB->count_records('local_resourcestats_user_views', ['cmid' => $this->cm->id]));
    }
}
