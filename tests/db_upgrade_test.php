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
 * PHPUnit tests for the local_resourcestats upgrade step that carries real data-migration
 * logic, as opposed to plain schema DDL (which install.xml already exercises on every test
 * run).
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats;

use advanced_testcase;

/**
 * Test cases for xmldb_local_resourcestats_upgrade().
 *
 * observer_test.php already covers observer::purge_orphaned_rows() itself in isolation; these
 * tests cover the upgrade step's own version gate — that it actually calls that method (and
 * records the savepoint) when crossing 2026090700, and does neither when already past it.
 *
 * @package    local_resourcestats
 * @covers ::xmldb_local_resourcestats_upgrade
 */
final class db_upgrade_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();

        // The savepoint helper lives in upgradelib.php, only autoloaded by the real upgrade
        // runner — calling the upgrade function directly in a test needs it pulled in by hand.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
    }

    /**
     * Lowers the plugin's own recorded version, exactly as it would be on a real site partway
     * through an upgrade, so the call below is a genuine step forward instead of a no-op
     * "downgrade" — upgrade_plugin_savepoint() refuses anything else.
     *
     * @param int $version Version to record as the plugin's current one.
     */
    private function set_current_version(int $version): void {
        set_config('version', $version, 'local_resourcestats');
    }

    /**
     * Inserts a statistics row for a cmid that does not exist in course_modules.
     *
     * @return int The orphaned cmid used.
     */
    private function insert_orphaned_row(): int {
        global $DB;

        $cmid = 999999;
        $DB->insert_record('local_resourcestats_views', (object) [
            'cmid' => $cmid, 'totalviews' => 3, 'uniqueviews' => 1,
            'lastuserid' => null, 'lastviewtime' => time(),
            'deletedviews' => 0, 'deletedcount' => 0,
        ]);

        return $cmid;
    }

    /**
     * Crossing the 2026090700 savepoint must purge orphaned rows and record the savepoint.
     */
    public function test_upgrade_crossing_the_savepoint_purges_orphans(): void {
        global $DB;

        $this->set_current_version(2026090699);
        $cmid = $this->insert_orphaned_row();

        $result = xmldb_local_resourcestats_upgrade(2026090699);

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('local_resourcestats_views', ['cmid' => $cmid]));
        $this->assertEquals(
            2026090700,
            $DB->get_field('config_plugins', 'value', ['plugin' => 'local_resourcestats', 'name' => 'version'])
        );
    }

    /**
     * An $oldversion already at the savepoint must be a no-op: no purge, and the plugin's
     * recorded version left exactly as it was.
     */
    public function test_upgrade_already_past_the_savepoint_is_a_noop(): void {
        global $DB;

        $before = $DB->get_field(
            'config_plugins',
            'value',
            ['plugin' => 'local_resourcestats', 'name' => 'version']
        );
        $cmid = $this->insert_orphaned_row();

        $result = xmldb_local_resourcestats_upgrade(2026090700);

        $this->assertTrue($result);
        $this->assertTrue($DB->record_exists('local_resourcestats_views', ['cmid' => $cmid]));
        $this->assertEquals(
            $before,
            $DB->get_field('config_plugins', 'value', ['plugin' => 'local_resourcestats', 'name' => 'version'])
        );
    }
}
