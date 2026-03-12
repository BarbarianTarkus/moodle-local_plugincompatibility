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

// Filtering parameters.
$filtersearch = optional_param('filter_search', '', PARAM_TEXT);
$filterstatus = optional_param('filter_status', '', PARAM_ALPHA);

$PAGE->set_url('/local/plugincompatibility/index.php', [
    'version'       => $version,
    'filter_search' => $filtersearch,
    'filter_status' => $filterstatus,
]);
$PAGE->set_context(context_system::instance());
$PAGE->requires->css('/local/plugincompatibility/styles.css');
$PAGE->set_title(get_string('pluginname', 'local_plugincompatibility'));
$PAGE->set_heading(get_string('pluginname', 'local_plugincompatibility'));

$data = local_plugincompatibility_get_report_data($version);

// Apply filtering.
if ($filtersearch !== '' || $filterstatus !== '') {
    $data = array_filter($data, function ($plugin) use ($filtersearch, $filterstatus) {
        if (
            $filtersearch !== '' &&
            stripos($plugin->name, $filtersearch) === false &&
            stripos($plugin->component, $filtersearch) === false
        ) {
            return false;
        }
        if ($filterstatus !== '' && $plugin->status !== $filterstatus) {
            return false;
        }
        return true;
    });
}

if ($dataformat) {
    local_plugincompatibility_export_report($version, $dataformat, $data);
}

echo $OUTPUT->header();

if (empty($data) && $filtersearch === '' && $filterstatus === '') {
    echo $OUTPUT->notification(
        get_string('noinstalledplugins', 'local_plugincompatibility'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo $OUTPUT->footer();
    exit;
}

$output = $OUTPUT->box_start('generalbox mt-3 mb-3');
$output .= html_writer::tag('div', get_string('targetversion_help', 'local_plugincompatibility'), ['class' => 'mb-2']);
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

// Filter form.
echo $OUTPUT->box_start('generalbox mb-3');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url, 'class' => 'form-inline']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'version', 'value' => $version]);

echo html_writer::div(
    html_writer::label(get_string('search'), 'filter_search', false, ['class' => 'mr-2']) .
    html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'filter_search',
        'id' => 'filter_search',
        'value' => $filtersearch,
        'class' => 'form-control mr-3',
    ]),
    'form-group ml-2'
);

$statusoptions = [
    '' => get_string('all'),
    'compatible' => get_string('compatible', 'local_plugincompatibility'),
    'notcompatible' => get_string('notcompatible', 'local_plugincompatibility'),
    'notfound' => get_string('notfound', 'local_plugincompatibility'),
];
echo html_writer::div(
    html_writer::label(get_string('status', 'core'), 'filter_status', false, ['class' => 'mr-2']) .
    html_writer::select(
        $statusoptions,
        'filter_status',
        $filterstatus,
        false,
        ['class' => 'form-control mr-3', 'id' => 'filter_status']
    ),
    'form-group ml-2'
);

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'btn btn-primary ml-2']);
echo html_writer::link(
    $PAGE->url->out(false, ['filter_search' => '', 'filter_status' => '']),
    get_string('clearall'),
    ['class' => 'btn btn-secondary ml-2']
);
echo html_writer::end_tag('form');
echo $OUTPUT->box_end();

$table = new \local_plugincompatibility\table\compatibility_table('plugin_compat_report', $version);
$table->define_baseurl($PAGE->url);

$table->setup();

// Apply sorting to data.
$sortcolumns = $table->get_sort_columns();
if ($sortcolumns) {
    usort($data, function ($a, $b) use ($sortcolumns) {
        foreach ($sortcolumns as $column => $order) {
            $vala = $a->$column ?? '';
            $valb = $b->$column ?? '';
            $res = strnatcasecmp($vala, $valb);
            if ($res !== 0) {
                return ($order === SORT_ASC) ? $res : -$res;
            }
        }
        return 0;
    });
}

foreach ($data as $plugin) {
    $table->add_data_keyed($table->format_row($plugin));
}

$table->finish_output();

echo $OUTPUT->download_dataformat_selector(
    get_string('downloadtable', 'local_plugincompatibility'),
    $PAGE->url->out_omit_querystring(),
    'dataformat',
    ['version' => $version, 'filter_search' => $filtersearch, 'filter_status' => $filterstatus]
);
echo $OUTPUT->footer();
