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
 * Stats data is passed as init arguments by the PHP hook listener,
 * avoiding additional AJAX requests. The three boolean flags control which
 * badges are rendered per the teacher's personal preferences.
 *
 * @module     local_resourcestats/course_badges
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';

/**
 * Initialise the badge injection for all visible course module items.
 *
 * @param {Object}   statsmap      Plain object keyed by cmid with stat objects.
 * @param {boolean}  showtotal     Whether to render the total-accesses badge.
 * @param {boolean}  showunique    Whether to render the unique-students badge.
 * @param {boolean}  showlastuser  Whether to render the last-user badge.
 * @param {number[]} excludedcmids CMIDs of modules that never track views (e.g. labels).
 */
export const init = (statsmap, showtotal, showunique, showlastuser, excludedcmids) => {
    const items = document.querySelectorAll('[data-for="cmitem"][data-id]');
    const excluded = new Set(excludedcmids || []);

    items.forEach((item) => {
        const cmid = parseInt(item.dataset.id, 10);

        if (excluded.has(cmid)) {
            return;
        }
        const stat = statsmap[cmid] || null;

        const context = {
            totalviews:   stat ? stat.totalviews : 0,
            uniqueviews:  stat ? stat.uniqueviews : 0,
            lastusername: stat ? stat.lastusername : '',
            showlastuser: showlastuser && !!(stat && stat.lastusername),
            showtotal:    showtotal,
            showunique:   showunique,
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
