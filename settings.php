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
 * Plugin settings: registers the plugin in the Site Administration menu.
 *
 * @package     local_plugincompatibility
 * @copyright   2020 Raúl Martínez <raulmartinez911@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $currentversion = normalize_version($CFG->release);
    $parts = explode('.', $currentversion);
    $major = $parts[0] . '.' . ($parts[1] ?? '0');

    $url = new moodle_url('/local/plugincompatibility/index.php', ['version' => $major]);
    $page = new admin_externalpage(
        'local_plugincompatibility_report',
        get_string('pluginname', 'local_plugincompatibility'),
        $url
    );

    $ADMIN->add('localplugins', $page);
}
