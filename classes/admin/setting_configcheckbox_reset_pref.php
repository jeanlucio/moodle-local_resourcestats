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
 * Admin setting checkbox that resets teacher preferences on save.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\admin;

/**
 * Checkbox setting that deletes all user preference overrides when its value actually changes.
 *
 * This ensures that whenever an administrator changes this specific global visibility
 * setting, all teacher-level overrides are cleared and the new admin value takes immediate
 * effect for everyone. Teachers may then set their own preference again afterwards.
 *
 * admin_write_settings() (lib/adminlib.php) calls write_setting() for every setting present
 * in the submitted form, regardless of whether its value changed — saving the settings page
 * to adjust an unrelated field would otherwise wipe every user's preference on every save.
 *
 * @package local_resourcestats
 */
class setting_configcheckbox_reset_pref extends \admin_setting_configcheckbox {
    /** @var string User preference key to clear when this setting is saved. */
    private string $prefkey;

    /**
     * Constructor.
     *
     * @param string $name           Setting name (plugin/key format, e.g. 'local_foo/bar').
     * @param string $visiblename    Label shown in the admin UI.
     * @param string $description    Help text shown in the admin UI.
     * @param string $defaultsetting Default value ('0' or '1').
     * @param string $prefkey        User preference key to wipe on save.
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        string $defaultsetting,
        string $prefkey
    ) {
        parent::__construct($name, $visiblename, $description, $defaultsetting);
        $this->prefkey = $prefkey;
    }

    /**
     * Saves the setting and, only if its value actually changed, clears all teacher
     * overrides for this preference.
     *
     * @param mixed $data The value submitted by the admin form.
     * @return string Empty string on success, error message otherwise.
     */
    public function write_setting($data): string {
        global $DB;

        $oldvalue = $this->get_setting();
        $result = parent::write_setting($data);

        if ($result === '' && $oldvalue !== $this->get_setting()) {
            $DB->delete_records('user_preferences', ['name' => $this->prefkey]);
        }

        return $result;
    }
}
