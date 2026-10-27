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

namespace local_attendancecatexport\event;

/**
 * Event triggered when the category attendance report is exported.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - bool includesubcategories: whether subcategories were included.
 *      - float threshold: threshold in percent.
 *      - string basis: percentage basis ("all" or "taken").
 *      - string dataformat: the data format used.
 * }
 *
 * @package    local_attendancecatexport
 * @copyright  2026 Ramon Heeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_exported extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventreportexported', 'local_attendancecatexport');
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' exported the attendance of the course category with id " .
            "'$this->contextinstanceid' in '{$this->other['dataformat']}' format.";
    }

    /**
     * Returns relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/attendancecatexport/export.php', ['categoryid' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_COURSECAT) {
            throw new \coding_exception('Context level must be CONTEXT_COURSECAT.');
        }
        foreach (['includesubcategories', 'threshold', 'basis', 'dataformat'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
        }
    }

    /**
     * Mapping of the other fields for backup/restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
