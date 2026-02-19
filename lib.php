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
 *
 * @package     local_plugincompatibility
 * @copyright   2020 Raúl Martínez<raulmartinez911@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Get installed plugins.
 *
 * @param string $destinationversion
 * @return array
 */
function get_installed_plugins($destinationversion) {
    $data = [];

    $pluginman = core_plugin_manager::instance();
    $plugininfo = $pluginman->get_plugins();

    foreach ($plugininfo as $plugintype => $pluginnames) {
        foreach ($pluginnames as $pluginname => $pluginfo) {
            $dependencies = '-';
            if (!$pluginfo->is_standard() && !$pluginfo->is_subplugin()) {
                if ($pluginname !== 'plugincompatibility') {
                    $pluginnameformatted = $plugintype . '_' . $pluginname;

                    if ($pluginfo->dependencies) {
                        $dependencies = '';
                        foreach ($pluginfo->dependencies as $kd => $kv) {
                            $dependencies .= $kd;
                        }
                    }
                    $compatible = check_compatible_version($destinationversion, $pluginnameformatted);
                    $data[] = [$pluginnameformatted, $dependencies, $compatible];
                }
            }
        }
    }

    return $data;
}


/**
 * Check compatible version.
 *
 * @param string $version
 * @param string $pluginname
 * @return string
 */
function check_compatible_version($version, $pluginname) {

    $html = @file_get_contents("https://moodle.org/plugins/pluginversions.php?plugin=$pluginname");

    if (!$html) {
        return html_writer::tag('span', get_string('notfound', 'local_plugincompatibility'), ['style' => 'color:black']);
    }

    if (preg_match('/<span class="moodleversions">Moodle.*' . $version . '.*<\/span>/', $html)) {
        return html_writer::tag('span', get_string('compatible', 'local_plugincompatibility'), ['style' => 'color:green']);
    }
    return html_writer::tag('span', get_string('notcompatible', 'local_plugincompatibility'), ['style' => 'color:red']);
}


/**
 * Extend navigation.
 *
 * @param global_navigation $nav
 */
function local_plugincompatibility_extend_navigation(global_navigation $nav) {
    global $CFG;
    require_once($CFG->libdir . '/environmentlib.php');

    $currentversion = normalize_version($CFG->release);
    $currentversionnominors = explode(".", $currentversion);
    $currentversionnominors = $currentversionnominors[0] . "." . $currentversionnominors[1];

    if (has_capability('moodle/site:config', context_system::instance())) {
        $managementsectionnode = navigation_node::create(
            get_string('pluginname', 'local_plugincompatibility'),
            new moodle_url('/local/plugincompatibility/index.php?version=' . $currentversionnominors),
            global_navigation::TYPE_CUSTOM,
            null,
            'local_plugincompatibility_table',
            new pix_icon('t/log', '')
        );

        $managementsectionnode->showinflatnavigation = true;
        $nav->add_node($managementsectionnode);
    }
}
