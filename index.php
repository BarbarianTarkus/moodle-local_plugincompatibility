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

/**
 * Plugin compatibility report: installed plugins vs Moodle.org pluglist API.
 *
 * @package     local_plugincompatibility
 * @copyright   2020 Raúl Martínez <raulmartinez911@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

require_once($CFG->libdir . '/environmentlib.php');
require_once($CFG->dirroot . '/local/plugincompatibility/lib.php');

if (!has_capability('moodle/site:config', context_system::instance())) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('pluginname', 'local_plugincompatibility'));
}

$currentversion = $CFG->release;
$normalizedcurrent = normalize_version($currentversion);
$destinationversion = optional_param('version', $normalizedcurrent, PARAM_TEXT);
$dataformat = optional_param('dataformat', '', PARAM_ALPHA);

$versions = [];
$knownfuturemajors = ['5.0', '5.1', '5.2', '5.3'];
if ($contents = load_environment_xml()) {
    $envversions = get_list_of_environment_versions($contents);
    if ($envversions) {
        foreach ($envversions as $envver) {
            if ($envver === 'all') {
                continue;
            }
            if (version_compare($envver, $normalizedcurrent, '>=')) {
                $versions[$envver] = $envver;
            }
        }
    }
}
foreach ($knownfuturemajors as $major) {
    if (version_compare($major, $normalizedcurrent, '>=') && !isset($versions[$major])) {
        $versions[$major] = $major;
    }
}
uksort($versions, function ($a, $b) {
    return version_compare($a, $b);
});
if (empty($versions)) {
    $versions[$normalizedcurrent] = $currentversion;
}

$version = $destinationversion;
if (!isset($versions[$version])) {
    $version = $normalizedcurrent;
}

$PAGE->set_url('/local/plugincompatibility/index.php', ['version' => $version]);
$PAGE->set_context(context_system::instance());
$PAGE->requires->css('/local/plugincompatibility/styles.css');
$PAGE->set_title(get_string('pluginname', 'local_plugincompatibility'));
$PAGE->set_heading(get_string('pluginname', 'local_plugincompatibility'));

$data = get_installed_plugins($version);

if ($dataformat) {
    local_plugincompatibility_export_report($version, $dataformat);
}

echo $OUTPUT->header();

if (empty($data)) {
    echo $OUTPUT->notification(
        get_string('noinstalledplugins', 'local_plugincompatibility'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo $OUTPUT->footer();
    exit;
}

$output = $OUTPUT->box_start();
$output .= html_writer::tag('div', get_string('targetversion_help', 'local_plugincompatibility'));
$select = new single_select(
    new moodle_url('/local/plugincompatibility/index.php'),
    'version',
    $versions,
    $version,
    null
);
$select->label = get_string('targetversion', 'local_plugincompatibility');
$output .= $OUTPUT->render($select);
$output .= $OUTPUT->box_end();
echo $output;

$table = new html_table();
$table->head = [
    mb_strtoupper(get_string('pluginname_column', 'local_plugincompatibility')),
    mb_strtoupper(get_string('plugin', 'core')),
    mb_strtoupper(get_string('dependson', 'local_plugincompatibility')),
    mb_strtoupper(get_string('currentversion_column', 'local_plugincompatibility')),
    mb_strtoupper(get_string('compatibility_with_version', 'local_plugincompatibility', $version)),
    mb_strtoupper(get_string('lastrelease', 'local_plugincompatibility')),
];
$table->size = ['20%', '20%', '20%', '20%', '20%', '20%'];
$table->align = ['left', 'center', 'center', 'center', 'center', 'center'];
$table->data = $data;

echo html_writer::table($table);
echo $OUTPUT->download_dataformat_selector(
    get_string('downloadtable', 'local_plugincompatibility'),
    $PAGE->url->out_omit_querystring(),
    'dataformat',
    ['version' => $version]
);
echo $OUTPUT->footer();
