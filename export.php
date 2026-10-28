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
 * Attendance export for all courses of a course category.
 *
 * @package    local_attendancecatexport
 * @copyright  2026 Ramon Heeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_attendancecatexport\form\export_form;
use local_attendancecatexport\report;

$categoryid = required_param('categoryid', PARAM_INT);

require_login();

$category = core_course_category::get($categoryid);
$context = context_coursecat::instance($category->id);
report::require_export_capability($context);

$url = new moodle_url('/local/attendancecatexport/export.php', ['categoryid' => $category->id]);
$title = get_string('exportmenu', 'local_attendancecatexport');

$PAGE->set_url($url);
$PAGE->set_category_by_id($category->id);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($title);
$PAGE->set_heading($category->get_formatted_name());

$form = new export_form($url, ['categoryid' => $category->id]);
$norows = false;

if ($data = $form->get_data()) {
    $filters = export_form::get_filters($data);
    $report = new report($category, !empty($data->includesubcategories), (float) $data->threshold, $data->basis, $filters);

    // Compute up to the first row to avoid downloading an empty file. NoRewindIterator keeps the
    // generator from being rewound when download_data() iterates over it.
    $rows = new NoRewindIterator($report->get_rows());
    $norows = !$rows->valid();
}

if ($data && !$norows) {
    \local_attendancecatexport\event\report_exported::create([
        'context' => $context,
        'other' => [
            'includesubcategories' => !empty($data->includesubcategories),
            'threshold' => (float) $data->threshold,
            'basis' => $data->basis,
            'dataformat' => $data->dataformat,
        ] + $filters,
    ])->trigger();

    $filename = clean_filename(get_string('exportfilename', 'local_attendancecatexport', [
        'category' => $category->get_formatted_name(['escape' => false]),
        'date' => userdate(time(), '%Y-%m-%d'),
    ]));
    \core\dataformat::download_data($filename, $data->dataformat, $report->get_columns(), $rows);
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
if ($norows) {
    echo $OUTPUT->notification(get_string('norows', 'local_attendancecatexport'), \core\output\notification::NOTIFY_INFO);
}
echo html_writer::tag('p', get_string('exportintro', 'local_attendancecatexport'));
$form->display();
echo $OUTPUT->footer();
