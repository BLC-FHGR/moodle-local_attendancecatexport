<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library callbacks for local_attendancecatexport.
 *
 * @package    local_attendancecatexport
 * @copyright  2026 Ramon Heeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the export link to the course category settings navigation.
 *
 * Moodle 5.2 provides no hook for the category settings menu, so the legacy callback is used.
 *
 * @param navigation_node $parentnode The category settings node.
 * @param context_coursecat $context The category context.
 */
function local_attendancecatexport_extend_navigation_category_settings(
    navigation_node $parentnode,
    context_coursecat $context
): void {
    if (!\local_attendancecatexport\report::can_export($context)) {
        return;
    }
    $url = new moodle_url('/local/attendancecatexport/export.php', ['categoryid' => $context->instanceid]);
    $node = $parentnode->add(
        get_string('exportmenu', 'local_attendancecatexport'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_attendancecatexport',
        new pix_icon('i/export', '')
    );
    // Show the link in the "More" menu of the category secondary navigation.
    $node->set_force_into_more_menu(true);
}
