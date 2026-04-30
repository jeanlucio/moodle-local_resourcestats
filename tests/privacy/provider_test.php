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

namespace local_resourcestats\tests\privacy;

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
     * delete_data_for_user must anonymise the row: preserve viewcount but null
     * userid, firstviewtime and lastviewtime.
     */
    public function test_delete_anonymises_row_not_deletes(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->insert_user_view($student->id, 7);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $approvedlist = new approved_contextlist(
            $student,
            'local_resourcestats',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approvedlist);

        $record = $DB->get_record('local_resourcestats_user_views', ['cmid' => $this->cm->id]);
        $this->assertNotFalse($record);
        $this->assertNull($record->userid);
        $this->assertNull($record->firstviewtime);
        $this->assertNull($record->lastviewtime);
        $this->assertEquals(7, (int) $record->viewcount);
    }

    /**
     * Anonymising one student must leave other students' rows untouched.
     */
    public function test_delete_does_not_affect_other_users(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $this->insert_user_view($s1->id, 3);
        $this->insert_user_view($s2->id, 5);

        $contextlist = provider::get_contexts_for_userid($s1->id);
        $approvedlist = new approved_contextlist(
            $s1,
            'local_resourcestats',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approvedlist);

        $record = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $s2->id]
        );
        $this->assertNotFalse($record);
        $this->assertEquals(5, (int) $record->viewcount);
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
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid'         => $this->cm->id,
            'totalviews'   => 2,
            'uniqueviews'  => 2,
            'lastuserid'   => $s2->id,
            'lastviewtime' => time(),
        ]);

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
     * delete_data_for_users must anonymise only the approved users, leaving any
     * non-approved users' rows intact.
     */
    public function test_bulk_delete_anonymises_only_approved_users(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $s3 = $generator->create_user();
        $this->insert_user_view($s1->id, 2);
        $this->insert_user_view($s2->id, 4);
        $this->insert_user_view($s3->id, 1);

        $context = \context_module::instance($this->cm->id);
        // Approve only s1 and s2 — s3 must survive untouched.
        $approveduserlist = new approved_userlist(
            $context,
            'local_resourcestats',
            [$s1->id, $s2->id]
        );
        provider::delete_data_for_users($approveduserlist);

        $anonymised = $DB->get_records_select(
            'local_resourcestats_user_views',
            'cmid = :cmid AND userid IS NULL',
            ['cmid' => $this->cm->id]
        );
        $this->assertCount(2, $anonymised);

        $s3record = $DB->get_record(
            'local_resourcestats_user_views',
            ['cmid' => $this->cm->id, 'userid' => $s3->id]
        );
        $this->assertNotFalse($s3record);
        $this->assertEquals(1, (int) $s3record->viewcount);
    }
}
