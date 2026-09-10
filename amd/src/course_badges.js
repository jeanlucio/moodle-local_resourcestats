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
 * AMD module that injects resource statistics badges into course module items.
 *
 * The PHP hook listener leaves the payload in a hidden element's data attribute, avoiding
 * both an extra AJAX request and the size limit that applies to js_call_amd() arguments —
 * the payload grows with the number of activities in the course.
 *
 * @module     local_resourcestats/course_badges
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';

const DATA_ELEMENT_ID = 'local-resourcestats-badge-data';

/**
 * Reads the payload left by the PHP hook listener.
 *
 * @returns {Object|null} The payload, or null when it is absent or unreadable.
 */
const readPayload = () => {
    const element = document.getElementById(DATA_ELEMENT_ID);

    if (!element || !element.dataset.payload) {
        return null;
    }

    try {
        return JSON.parse(element.dataset.payload);
    } catch (error) {
        return null;
    }
};

/**
 * Initialise the badge injection for all visible course module items.
 */
export const init = () => {
    const payload = readPayload();

    if (!payload) {
        return;
    }

    const {stats, excluded: excludedcmids, show} = payload;
    const items = document.querySelectorAll('[data-for="cmitem"][data-id]');
    const excluded = new Set(excludedcmids || []);

    items.forEach((item) => {
        const cmid = parseInt(item.dataset.id, 10);

        if (excluded.has(cmid)) {
            return;
        }
        const stat = stats[cmid] || null;
        // A missing trackedtotal means completion is not enabled for this activity, which is
        // not the same as nobody having completed it — no badge at all is the honest render.
        const hascompletion = !!(stat && stat.trackedtotal !== undefined);

        const context = {
            totalviews:    stat ? stat.totalviews : 0,
            uniqueviews:   stat ? stat.uniqueviews : 0,
            lastusername:  stat ? stat.lastusername : '',
            completed:     hascompletion ? stat.completed : 0,
            passed:        hascompletion ? stat.passed : 0,
            trackedtotal:  hascompletion ? stat.trackedtotal : 0,
            showlastuser:  show.lastuser && !!(stat && stat.lastusername),
            showtotal:     show.total,
            showunique:    show.unique,
            showcompleted: show.completed && hascompletion,
            showpassed:    show.passed && hascompletion && !!stat.haspass,
        };

        Templates.renderForPromise('local_resourcestats/stats_tags', context)
            .then(({html}) => {
                const card = item.querySelector('[data-region="activity-card"]');
                const grid = item.querySelector('.activity-grid');
                if (card) {
                    card.insertAdjacentHTML('beforeend', html);
                } else if (grid) {
                    grid.insertAdjacentHTML('afterend', html);
                } else {
                    item.insertAdjacentHTML('beforeend', html);
                }
                return html;
            })
            .catch(() => {
                // Silently ignore individual render errors.
            });
    });
};
