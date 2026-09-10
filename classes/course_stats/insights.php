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
    /** @var int Maximum number of activity pills shown before the rest collapse behind a disclosure. */
    private const MAX_VISIBLE_ITEMS = 5;

    /** @var array[] Activity rows as returned by controller::get_template_context(). */
    private array $activities;

    /** @var int Total enrolled students (excluding teachers). */
    private int $totalstudents;

    /** @var int[] Userids of the students visible to the caller. */
    private array $visibleuserids;

    /** @var int Minimum % of students that must have viewed an activity. */
    private int $lowengpct;

    /** @var int Minimum % of tracked students that must have completed an activity. */
    private int $lowcompletionpct;

    /**
     * Constructor.
     *
     * @param array[] $activities     Activity rows from the course controller.
     * @param int     $totalstudents  Number of enrolled students.
     * @param int[]   $visibleuserids Userids of the students visible to the caller — the
     *                                same set $totalstudents was counted from. Restricts
     *                                count_students_with_no_access() to that same universe,
     *                                so a group-restricted caller's alert never derives
     *                                from another group's access data.
     */
    public function __construct(array $activities, int $totalstudents, array $visibleuserids) {
        $this->activities = $activities;
        $this->totalstudents = $totalstudents;
        $this->visibleuserids = $visibleuserids;
        $this->lowengpct = (int)(get_config('local_resourcestats', 'insight_loweng_pct') ?: 20);
        $this->lowcompletionpct = (int)(get_config('local_resourcestats', 'insight_lowcompletion_pct') ?: 40);
    }

    /**
     * Calculates and returns a list of engagement alerts.
     *
     * The unviewed and low-engagement alerts are each a single alert covering every
     * matching activity (not one alert per activity), with the activity names rendered by
     * the template as a wrapped list of pills rather than baked into the message string —
     * a course with dozens of matching activities would otherwise turn the highlights panel
     * into an unreadable wall of comma-separated text.
     *
     * Each alert has: type (danger|warning), icon (fa class), message (count-based intro,
     * no activity names), hasitems, and — only when hasitems is true — visibleitems,
     * hiddenitems, hashidden, hiddencount, morelabel.
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
                $unviewed[] = [
                    'name'      => $activity['activityname'],
                    'detailurl' => $activity['detailurl'],
                    'suffix'    => '',
                ];
            } else if (
                $this->totalstudents > 0
                && $activity['engagementpct'] < $this->lowengpct
            ) {
                $loweng[] = [
                    'name'      => $activity['activityname'],
                    'detailurl' => $activity['detailurl'],
                    'suffix'    => get_string(
                        'insight_pct_suffix',
                        'local_resourcestats',
                        $activity['engagementpct']
                    ),
                ];
            }
        }

        if (!empty($unviewed)) {
            $stringkey = count($unviewed) === 1 ? 'insight_unviewed_activity' : 'insight_unviewed_activity_plural';
            $alerts[] = $this->build_activity_alert('danger', 'fa-eye-slash', $stringkey, count($unviewed), $unviewed);
        }

        if (!empty($loweng)) {
            $stringkey = count($loweng) === 1 ? 'insight_low_engagement' : 'insight_low_engagement_plural';
            $params = count($loweng) === 1 ? $this->lowengpct : (object)['count' => count($loweng), 'pct' => $this->lowengpct];
            $alerts[] = $this->build_activity_alert('warning', 'fa-exclamation-triangle', $stringkey, $params, $loweng);
        }

        foreach ($this->build_completion_alerts() as $alert) {
            $alerts[] = $alert;
        }

        $zerostudents = $this->count_students_with_no_access();
        if ($zerostudents > 0) {
            $stringkey = $zerostudents === 1 ? 'insight_zero_students' : 'insight_zero_students_plural';
            $alerts[] = [
                'type'     => 'danger',
                'icon'     => 'fa-user-times',
                'message'  => get_string($stringkey, 'local_resourcestats', $zerostudents),
                'hasitems' => false,
            ];
        }

        return $alerts;
    }

    /**
     * Builds the completion alerts: activities nobody completed, and activities completed by
     * fewer than the configured percentage of the students tracked for completion.
     *
     * Only activities that actually track completion are considered. An activity without
     * completion enabled has no completion rate to be low — reporting it as 0% would fill the
     * panel with false alarms in any course that uses completion on only part of its content.
     *
     * @return array[] Zero, one or two alerts.
     * @throws \coding_exception
     */
    private function build_completion_alerts(): array {
        $none = [];
        $low  = [];

        foreach ($this->activities as $activity) {
            if (empty($activity['hascompletion']) || (int)$activity['trackedtotal'] === 0) {
                continue;
            }

            $completed = (int)$activity['completed'];
            $item = [
                'name'      => $activity['activityname'],
                'detailurl' => $activity['detailurl'],
                'suffix'    => '',
            ];

            if ($completed === 0) {
                $none[] = $item;
                continue;
            }

            $pct = (int)round($completed / (int)$activity['trackedtotal'] * 100);
            if ($pct < $this->lowcompletionpct) {
                $item['suffix'] = get_string('insight_pct_suffix', 'local_resourcestats', $pct);
                $low[] = $item;
            }
        }

        $alerts = [];

        if (!empty($none)) {
            $key = count($none) === 1 ? 'insight_nocompletion_activity' : 'insight_nocompletion_activity_plural';
            $alerts[] = $this->build_activity_alert('danger', 'fa-times-circle', $key, count($none), $none);
        }

        if (!empty($low)) {
            $key = count($low) === 1 ? 'insight_low_completion' : 'insight_low_completion_plural';
            $args = count($low) === 1
                ? $this->lowcompletionpct
                : (object)['count' => count($low), 'pct' => $this->lowcompletionpct];
            $alerts[] = $this->build_activity_alert('warning', 'fa-exclamation-triangle', $key, $args, $low);
        }

        return $alerts;
    }

    /**
     * Builds one alert covering a list of activities, splitting the items into the ones
     * shown immediately and the rest collapsed behind a disclosure.
     *
     * @param string     $type       Bootstrap alert type: 'danger' or 'warning'.
     * @param string     $icon       Font Awesome icon class.
     * @param string     $stringkey  Lang string key for the count-based intro message.
     * @param int|object $stringargs Args for $stringkey.
     * @param array[]    $items      Activity items: name, detailurl, suffix.
     * @return array
     * @throws \coding_exception
     */
    private function build_activity_alert(string $type, string $icon, string $stringkey, $stringargs, array $items): array {
        $visibleitems = array_slice($items, 0, self::MAX_VISIBLE_ITEMS);
        $hiddenitems  = array_slice($items, self::MAX_VISIBLE_ITEMS);
        $hiddencount  = count($hiddenitems);

        return [
            'type'         => $type,
            'icon'         => $icon,
            'message'      => get_string($stringkey, 'local_resourcestats', $stringargs),
            'hasitems'     => true,
            'visibleitems' => $visibleitems,
            'hiddenitems'  => $hiddenitems,
            'hashidden'    => $hiddencount > 0,
            'morelabel'    => $hiddencount > 0 ? get_string(
                $hiddencount === 1 ? 'insight_show_more_activity' : 'insight_show_more_activity_plural',
                'local_resourcestats',
                $hiddencount
            ) : '',
        ];
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
        [$userinsql, $userinparams] = $DB->get_in_or_equal($this->visibleuserids, SQL_PARAMS_NAMED, 'u');
        $params = array_merge($inparams, $userinparams);

        $sql = "SELECT DISTINCT userid FROM {local_resourcestats_user_views} WHERE cmid $insql AND userid $userinsql";
        $userswithanaccess = $DB->get_fieldset_sql($sql, $params);

        return max(0, $this->totalstudents - count($userswithanaccess));
    }
}
