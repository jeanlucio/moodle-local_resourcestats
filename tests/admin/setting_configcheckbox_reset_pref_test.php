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
 * PHPUnit tests for setting_configcheckbox_reset_pref.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\admin;

use advanced_testcase;

/**
 * Test cases for local_resourcestats\admin\setting_configcheckbox_reset_pref.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\admin\setting_configcheckbox_reset_pref
 */
final class setting_configcheckbox_reset_pref_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        // The parent class admin_setting_configcheckbox is not autoloaded; it only gets
        // included when the admin settings tree is actually built.
        require_once($CFG->libdir . '/adminlib.php');
    }

    /**
     * Returns a fresh setting instance and ensures a user preference row exists to test
     * against.
     *
     * @return setting_configcheckbox_reset_pref
     */
    private function get_setting(): setting_configcheckbox_reset_pref {
        global $DB;

        $setting = new setting_configcheckbox_reset_pref(
            'local_resourcestats/default_show_total',
            'Show total accesses by default',
            'Description',
            '0',
            'local_resourcestats_show_total'
        );

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_preferences', (object) [
            'userid' => $user->id,
            'name'   => 'local_resourcestats_show_total',
            'value'  => '1',
        ]);

        return $setting;
    }

    /**
     * Saving the setting with the SAME value it already has (the common case: the admin
     * saved the settings page to change an unrelated field) must not touch any user's
     * preference.
     */
    public function test_write_setting_with_unchanged_value_preserves_preferences(): void {
        global $DB;
        $this->resetAfterTest();

        $setting = $this->get_setting();
        $setting->write_setting('0'); // Establish the initial stored value.

        $countbefore = $DB->count_records('user_preferences', ['name' => 'local_resourcestats_show_total']);
        $this->assertGreaterThan(0, $countbefore);

        $result = $setting->write_setting('0'); // Same value again.

        $this->assertSame('', $result);
        $this->assertEquals(
            $countbefore,
            $DB->count_records('user_preferences', ['name' => 'local_resourcestats_show_total'])
        );
    }

    /**
     * Saving the setting with a genuinely different value must still clear every user's
     * preference override, so the new site default takes effect immediately.
     */
    public function test_write_setting_with_changed_value_clears_preferences(): void {
        global $DB;
        $this->resetAfterTest();

        $setting = $this->get_setting();
        $setting->write_setting('0'); // Establish the initial stored value.

        $this->assertGreaterThan(
            0,
            $DB->count_records('user_preferences', ['name' => 'local_resourcestats_show_total'])
        );

        $result = $setting->write_setting('1'); // Genuinely different value.

        $this->assertSame('', $result);
        $this->assertEquals(
            0,
            $DB->count_records('user_preferences', ['name' => 'local_resourcestats_show_total'])
        );
    }

    /**
     * A preference belonging to a different key must never be touched, regardless of
     * whether this setting's own value changed.
     */
    public function test_write_setting_never_touches_other_preference_keys(): void {
        global $DB;
        $this->resetAfterTest();

        $setting = $this->get_setting();
        $setting->write_setting('0');

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_preferences', (object) [
            'userid' => $user->id,
            'name'   => 'local_resourcestats_show_unique',
            'value'  => '1',
        ]);

        $setting->write_setting('1'); // Changes only default_show_total.

        $this->assertTrue($DB->record_exists('user_preferences', ['name' => 'local_resourcestats_show_unique']));
    }
}
