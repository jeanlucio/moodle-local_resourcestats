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
 * Turns stored names into plain text for the destinations that escape them themselves.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\local;

use context;

/**
 * Text helpers shared by the page controllers and the export.
 */
final class text {
    /**
     * Returns a stored name as plain text, for a Mustache `{{ }}` tag or a spreadsheet cell.
     *
     * format_string() returns HTML, with "&" as "&amp;", so a double-mustache tag would show "&amp;amp;" and
     * an exported cell would carry "&amp;". Its 'escape' => false option does not fix that: with the site
     * setting formatstringstriptags off the HTML cleaner re-encodes the text. The string filters still run;
     * the markup is stripped and the entities are decoded afterwards.
     *
     * The result is NOT safe to print as HTML, since entities are decoded; never hand it to "{{{ }}}" or to
     * a destination that prints markup as it is.
     *
     * @param string|null $string The stored name.
     * @param context|null $context Context for the filters; defaults to the page context.
     * @return string Plain text, unescaped.
     */
    public static function plain(?string $string, ?context $context = null): string {
        if ($string === null || $string === '') {
            return '';
        }

        $options = $context === null ? [] : ['context' => $context];
        $formatted = format_string($string, true, $options);

        return html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Text that core already formatted as HTML, as plain text for a Mustache `{{ }}` tag.
     *
     * Core hands back a section name through get_section_name() as format_string() output, with "&"
     * as "&amp;". The filters have already run, so only the markup is stripped and the entities decoded.
     *
     * @param string $html Formatted text, as returned by core.
     * @return string Plain text, unescaped.
     */
    public static function from_html(string $html): string {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
