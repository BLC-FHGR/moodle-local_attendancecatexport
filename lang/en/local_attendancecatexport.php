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
 * English language strings for local_attendancecatexport.
 *
 * @package    local_attendancecatexport
 * @copyright  2026 Ramon Heeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['basis'] = 'Percentage basis';
$string['basis_all'] = 'All sessions';
$string['basis_help'] = 'Which percentage is used for the "Threshold met" column and the "Max. points" column. "All sessions" relates the points to all sessions of the activity since the course start date, including sessions not yet taken (as in the "All sessions" summary of the attendance report). "Taken sessions" relates the points to the sessions already taken only.';
$string['basis_taken'] = 'Taken sessions';
$string['col_category'] = 'Course category';
$string['col_coursefullname'] = 'Course full name';
$string['col_courseshortname'] = 'Course short name';
$string['col_email'] = 'Email address';
$string['col_firstname'] = 'First name';
$string['col_idnumber'] = 'ID number';
$string['col_lastname'] = 'Last name';
$string['col_maxpoints'] = 'Max. points';
$string['col_numallsessions'] = 'Total sessions';
$string['col_numtakensessions'] = 'Taken sessions';
$string['col_percentall'] = '% all sessions';
$string['col_percenttaken'] = '% taken sessions';
$string['col_points'] = 'Points';
$string['col_thresholdmet'] = 'Threshold met';
$string['dataformat'] = 'Format';
$string['eventreportexported'] = 'Category attendance report exported';
$string['exportfilename'] = 'attendance_{$a->category}_{$a->date}';
$string['exportintro'] = 'Exports the attendance of all participants for every course with an attendance activity in this category. If a course contains several attendance activities, their points are summed up.';
$string['exportmenu'] = 'Attendance export';
$string['includesubcategories'] = 'Include subcategories';
$string['pluginname'] = 'Attendance category export';
$string['privacy:metadata'] = 'The Attendance category export plugin does not store any personal data. It only exports data stored by the attendance activity.';
$string['threshold'] = 'Threshold (%)';
$string['threshold_help'] = 'Minimum attendance in percent. The "Threshold met" column shows "Yes" if the percentage of the selected basis is at least this value.';
$string['thresholdinvalid'] = 'Enter a value between 0 and 100.';
