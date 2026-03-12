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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_plugincompatibility\table;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Table class for plugin compatibility report.
 *
 * @package     local_plugincompatibility
21:  * @copyright   2020 Raúl Martínez <raulmartinez911@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compatibility_table extends \flexible_table {

    /** @var string Target Moodle version. */
    protected $targetversion;

    /**
     * Constructor.
     *
     * @param string $uniqueid
     * @param string $targetversion
     */
    public function __construct($uniqueid, $targetversion) {
        parent::__construct($uniqueid);
        $this->targetversion = $targetversion;

        $columns = [
            'name',
            'component',
            'dependencies',
            'currentversion',
            'status',
            'lastrelease',
        ];
        $headers = [
            get_string('pluginname_column', 'local_plugincompatibility'),
            get_string('plugin', 'core'),
            get_string('dependson', 'local_plugincompatibility'),
            get_string('currentversion_column', 'local_plugincompatibility'),
            get_string('compatibility_with_version', 'local_plugincompatibility', $targetversion),
            get_string('lastrelease', 'local_plugincompatibility'),
        ];

        $this->define_columns($columns);
        $this->define_headers($headers);

        $this->sortable(true, 'name', SORT_ASC);
        $this->no_sorting('dependencies');
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable compatibilitytable');
    }

    /**
     * Format name column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_name($row) {
        return format_string($row->name);
    }

    /**
     * Format component column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_component($row) {
        if ($row->has_info) {
            $pluginurl = 'https://moodle.org/plugins/pluginversions.php?plugin=' . urlencode($row->component);
            return \html_writer::link(
                $pluginurl,
                s($row->component),
                ['target' => '_blank', 'rel' => 'noopener noreferrer', 'class' => 'local_plugincompatibility-pluginlink']
            );
        }
        return s($row->component);
    }

    /**
     * Format dependencies column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_dependencies($row) {
        return s($row->dependencies);
    }

    /**
     * Format status column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_status($row) {
        return \html_writer::tag(
            'span',
            get_string($row->status, 'local_plugincompatibility'),
            ['class' => 'local_plugincompatibility-' . $row->status]
        );
    }

    /**
     * Format currentversion column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_currentversion($row) {
        return s($row->currentversion);
    }

    /**
     * Format lastrelease column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_lastrelease($row) {
        return s($row->lastrelease);
    }
}
