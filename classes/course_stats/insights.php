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
 * Rules-based engagement insights for a course.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\course_stats;

/**
 * Computes engagement alerts for a course based on activity and student data.
 *
 * All analysis is deterministic — no external API required. Thresholds are
 * read from the plugin admin settings.
 *
 * @package local_resourcestats
 */
class insights {
    /** @var array[] Activity rows as returned by controller::get_template_context(). */
    private array $activities;

    /** @var int Total enrolled students (excluding teachers). */
    private int $totalstudents;

    /** @var int Minimum % of students that must have viewed an activity. */
    private int $lowengpct;

    /**
     * Constructor.
     *
     * @param array[] $activities    Activity rows from the course controller.
     * @param int     $totalstudents Number of enrolled students.
     */
    public function __construct(array $activities, int $totalstudents) {
        $this->activities = $activities;
        $this->totalstudents = $totalstudents;
        $this->lowengpct = (int)(get_config('local_resourcestats', 'insight_loweng_pct') ?: 20);
    }

    /**
     * Calculates and returns a list of engagement alerts.
     *
     * Each alert has: type (danger|warning|info), icon (fa class), message.
     *
     * @return array[]
     * @throws \coding_exception
     */
    public function get_alerts(): array {
        $alerts = [];

        $unviewed = [];
        $loweng = [];

        foreach ($this->activities as $activity) {
            if ((int)$activity['uniqueviews'] === 0) {
                $unviewed[] = $activity['activityname'];
            } else if (
                $this->totalstudents > 0
                && $activity['engagementpct'] < $this->lowengpct
            ) {
                $loweng[] = $activity['activityname'];
            }
        }

        foreach ($unviewed as $name) {
            $alerts[] = [
                'type'    => 'danger',
                'icon'    => 'fa-eye-slash',
                'message' => get_string('insight_unviewed_activity', 'local_resourcestats', $name),
            ];
        }

        foreach ($loweng as $name) {
            $alerts[] = [
                'type'    => 'warning',
                'icon'    => 'fa-exclamation-triangle',
                'message' => get_string(
                    'insight_low_engagement',
                    'local_resourcestats',
                    (object)['name' => $name, 'pct' => $this->lowengpct]
                ),
            ];
        }

        $zerostudents = $this->count_students_with_no_access();
        if ($zerostudents > 0) {
            $alerts[] = [
                'type'    => 'danger',
                'icon'    => 'fa-user-times',
                'message' => get_string('insight_zero_students', 'local_resourcestats', $zerostudents),
            ];
        }

        return $alerts;
    }

    /**
     * Counts enrolled students who have not accessed any activity.
     *
     * Uses the uniqueviews data already aggregated in the activities array,
     * so no extra DB query is needed.
     *
     * @return int
     * @throws \dml_exception
     */
    private function count_students_with_no_access(): int {
        global $DB;

        if ($this->totalstudents === 0 || empty($this->activities)) {
            return 0;
        }

        $cmids = array_column($this->activities, 'cmid');

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        $sql = "SELECT DISTINCT userid FROM {local_resourcestats_user_views} WHERE cmid $insql";
        $userswithanaccess = $DB->get_fieldset_sql($sql, $inparams);

        return max(0, $this->totalstudents - count($userswithanaccess));
    }
}
