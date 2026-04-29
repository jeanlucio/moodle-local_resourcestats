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
 * avoiding additional AJAX requests. The display mode controls which
 * badges are rendered. The gear icon appears on every item when the
 * page is in edit mode (gearurl is non-empty).
 *
 * @module     local_resourcestats/course_badges
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';

/**
 * Initialise the badge injection for all visible course module items.
 *
 * @param {Object} statsmap  Plain object keyed by cmid with stat objects.
 * @param {string} mode      Display mode: 'both', 'total', 'unique', or 'none'.
 * @param {string} gearurl   URL for the preferences page (empty = no gear icon).
 */
export const init = (statsmap, mode, gearurl) => {
    const items = document.querySelectorAll('[data-for="cmitem"][data-id]');
    const showtotal = mode === 'both' || mode === 'total';
    const showunique = mode === 'both' || mode === 'unique';

    items.forEach((item) => {
        const cmid = parseInt(item.dataset.id, 10);
        const stat = statsmap[cmid] || null;

        const context = {
            totalviews:   stat ? stat.totalviews : 0,
            uniqueviews:  stat ? stat.uniqueviews : 0,
            lastusername: stat ? stat.lastusername : '',
            hasviews:     mode === 'both' && !!(stat && stat.hasviews),
            showtotal:    showtotal,
            showunique:   showunique,
            gearurl:      gearurl || '',
        };

        // Nothing to render: no badges and no gear.
        if (!showtotal && !showunique && !context.gearurl) {
            return;
        }

        Templates.renderForPromise('local_resourcestats/stats_tags', context)
            .then(({html}) => {
                const card = item.querySelector('[data-region="activity-card"]');
                if (card) {
                    card.insertAdjacentHTML('beforeend', html);
                }
                return html;
            })
            .catch(() => {
                // Silently ignore individual render errors.
            });
    });
};
