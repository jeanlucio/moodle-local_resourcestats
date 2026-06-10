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
 * Client-side column sorting for statistics tables.
 *
 * Adds click handlers to <th data-sort-col> elements. Rows with
 * data-sort-fixed are excluded from sorting and remain in place.
 * Numeric columns use data-sort-val on <td> for correct ordering.
 *
 * @module     local_resourcestats/sortable_table
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Sorts a table by the given column index.
 *
 * @param {HTMLTableElement} table    The table element.
 * @param {number}           colIndex Zero-based column index.
 * @param {HTMLElement}      th       The header cell that was clicked.
 */
const sortTable = (table, colIndex, th) => {
    const tbody = table.querySelector('tbody');
    const rows = [...tbody.querySelectorAll('tr:not([data-sort-fixed])')];
    const asc = th.dataset.sortDir !== 'asc';

    th.dataset.sortDir = asc ? 'asc' : 'desc';

    table.querySelectorAll('thead th').forEach(h => {
        if (h !== th) {
            delete h.dataset.sortDir;
            h.querySelector('.rs-sort-icon')?.remove();
        }
    });

    const isNumeric = th.dataset.sortType === 'num';

    rows.sort((a, b) => {
        const va = a.cells[colIndex].dataset.sortVal ?? a.cells[colIndex].textContent.trim();
        const vb = b.cells[colIndex].dataset.sortVal ?? b.cells[colIndex].textContent.trim();
        const diff = isNumeric
            ? (parseFloat(va) || 0) - (parseFloat(vb) || 0)
            : va.localeCompare(vb, undefined, {sensitivity: 'base'});
        return asc ? diff : -diff;
    });

    rows.forEach(r => tbody.appendChild(r));

    th.querySelector('.rs-sort-icon')?.remove();
    const icon = document.createElement('i');
    icon.className = `fa fa-sort-${asc ? 'asc' : 'desc'} ms-1 rs-sort-icon`;
    icon.setAttribute('aria-hidden', 'true');
    th.appendChild(icon);
};

/**
 * Initialises sortable column headers on all matching tables.
 *
 * @param {string} selector CSS selector for tables to enable sorting on.
 */
export const init = (selector) => {
    document.querySelectorAll(selector).forEach(table => {
        table.querySelectorAll('thead th[data-sort-col]').forEach((th, colIndex) => {
            th.style.cursor = 'pointer';

            const icon = document.createElement('i');
            icon.className = 'fa fa-sort ms-1 rs-sort-icon text-muted';
            icon.setAttribute('aria-hidden', 'true');
            th.appendChild(icon);

            th.addEventListener('click', () => sortTable(table, colIndex, th));
        });
    });
};
