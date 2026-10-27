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
        return $errors;
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
