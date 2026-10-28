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

namespace local_attendancecatexport\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_attendancecatexport\report;
use stdClass;

/**
 * Export options form.
 *
 * @package    local_attendancecatexport
 * @copyright  2026 Ramon Heeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_form extends \moodleform {
    /** @var string[] Data formats offered by the form (dataformat plugin names). */
    public const DATAFORMATS = ['excel', 'csv', 'ods'];

    /** @var string[] Optional date filters on the course start and end date. */
    public const DATEFILTERS = ['startfrom', 'startto', 'endfrom', 'endto'];

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'categoryid', $this->_customdata['categoryid']);
        $mform->setType('categoryid', PARAM_INT);

        $mform->addElement('advcheckbox', 'includesubcategories', get_string('includesubcategories', 'local_attendancecatexport'));
        $mform->setDefault('includesubcategories', 1);

        $mform->addElement('float', 'threshold', get_string('threshold', 'local_attendancecatexport'));
        $mform->setDefault('threshold', 80);
        $mform->addRule('threshold', null, 'required', null, 'client');
        $mform->addHelpButton('threshold', 'threshold', 'local_attendancecatexport');

        $mform->addElement('select', 'basis', get_string('basis', 'local_attendancecatexport'), [
            report::BASIS_ALL => get_string('basis_all', 'local_attendancecatexport'),
            report::BASIS_TAKEN => get_string('basis_taken', 'local_attendancecatexport'),
        ]);
        $mform->setDefault('basis', report::BASIS_ALL);
        $mform->addHelpButton('basis', 'basis', 'local_attendancecatexport');

        $mform->addElement(
            'select',
            'dataformat',
            get_string('dataformat', 'local_attendancecatexport'),
            self::get_dataformat_options()
        );

        $mform->addElement('header', 'coursefilters', get_string('coursefilters', 'local_attendancecatexport'));
        $mform->setExpanded('coursefilters', true);

        foreach (self::DATEFILTERS as $name) {
            $mform->addElement('date_selector', $name, get_string($name, 'local_attendancecatexport'), ['optional' => true]);
        }
        $mform->addHelpButton('endfrom', 'endfrom', 'local_attendancecatexport');

        $mform->addElement('advcheckbox', 'includehidden', get_string('includehidden', 'local_attendancecatexport'));
        $mform->setDefault('includehidden', 0);

        $this->add_action_buttons(false, get_string('download'));
    }

    /**
     * Form validation.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors indexed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['threshold'] < 0 || $data['threshold'] > 100) {
            $errors['threshold'] = get_string('thresholdinvalid', 'local_attendancecatexport');
        }
        if (!array_key_exists($data['dataformat'], self::get_dataformat_options())) {
            $errors['dataformat'] = get_string('required');
        }
        foreach (['startfrom' => 'startto', 'endfrom' => 'endto'] as $from => $to) {
            if (!empty($data[$from]) && !empty($data[$to]) && $data[$from] > $data[$to]) {
                $errors[$to] = get_string('errordaterange', 'local_attendancecatexport');
            }
        }
        return $errors;
    }

    /**
     * Converts the submitted form data into course filters for {@see report}.
     *
     * The date selectors return midnight of the selected day; "to" dates are extended to the end of
     * that day in the user's timezone so that the whole day is included.
     *
     * @param stdClass $data Submitted form data.
     * @return array Course filters, see {@see report::DEFAULT_FILTERS}.
     */
    public static function get_filters(stdClass $data): array {
        return [
            'startfrom' => (int) ($data->startfrom ?? 0),
            'startto' => self::end_of_day((int) ($data->startto ?? 0)),
            'endfrom' => (int) ($data->endfrom ?? 0),
            'endto' => self::end_of_day((int) ($data->endto ?? 0)),
            'includehidden' => !empty($data->includehidden),
        ];
    }

    /**
     * Returns the last second of the day of the given timestamp in the user's timezone.
     *
     * @param int $timestamp The timestamp, 0 for none.
     * @return int The end of the day, or 0 if no timestamp was given.
     */
    public static function end_of_day(int $timestamp): int {
        if (!$timestamp) {
            return 0;
        }
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(\core_date::get_user_timezone_object());
        $date->setTime(0, 0);
        $date->modify('+1 day');
        return $date->getTimestamp() - 1;
    }

    /**
     * Returns the enabled data formats offered by the form.
     *
     * @return string[] Display names indexed by dataformat plugin name.
     */
    public static function get_dataformat_options(): array {
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('dataformat');
        $enabled = \core\plugininfo\dataformat::get_enabled_plugins();
        $options = [];
        foreach (self::DATAFORMATS as $name) {
            if (isset($plugins[$name]) && isset($enabled[$name])) {
                $options[$name] = $plugins[$name]->displayname;
            }
        }
        return $options;
    }
}
