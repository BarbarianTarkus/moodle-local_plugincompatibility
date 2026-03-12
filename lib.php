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
 * Plugin compatibility library: fetches pluglist from Moodle API and checks installed plugins.
 *
 * @package     local_plugincompatibility
 * @copyright   2020 Raúl Martínez <raulmartinez911@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** URL of the Moodle pluglist API (all plugins with supported Moodle versions). */
const LOCAL_PLUGINCOMPATIBILITY_PLUGLIST_URL = \core\update\api::APIROOT . '/' . \core\update\api::APIVER . '/pluglist.php';

/** Base URL for plugin versions page on Moodle.org. */
const LOCAL_PLUGINCOMPATIBILITY_MOODLEORG_VERSIONS_URL = 'https://moodle.org/plugins/pluginversions.php?plugin=';

/**
 * Get cached or fresh pluglist from download.moodle.org API.
 *
 * @return object|null Decoded JSON with 'plugins' array, or null on failure.
 */
function local_plugincompatibility_get_pluglist(): ?object {
    global $CFG;

    // Raise memory limit before fetching/decoding the large (~15 MB) pluglist JSON.
    raise_memory_limit(MEMORY_EXTRA);

    $cache = cache::make('local_plugincompatibility', 'pluglist');

    // Store the raw JSON string in cache to avoid serialising a huge object tree.
    $cached = $cache->get('pluglist_json');
    if ($cached !== false) {
        $data = json_decode($cached);
        if ($data !== null && isset($data->plugins) && is_array($data->plugins)) {
            return $data;
        }
    }

    require_once($CFG->libdir . '/filelib.php');
    $curl = new \curl();
    $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_FOLLOWLOCATION' => true]);
    $response = $curl->get(LOCAL_PLUGINCOMPATIBILITY_PLUGLIST_URL);

    if ($response === false || $curl->get_errno()) {
        return null;
    }

    $data = json_decode($response);
    if ($data === null || !isset($data->plugins) || !is_array($data->plugins)) {
        return null;
    }

    // Cache the raw JSON string.
    $cache->set('pluglist_json', $response);
    return $data;
}

/**
 * Build a map component -> best matching version info for a given Moodle release.
 *
 * @param object $pluglist Result from local_plugincompatibility_get_pluglist().
 * @param string $moodlerelease Normalized target release (e.g. "4.5", "4.4").
 * @return array Associative array component => object.
 */
function local_plugincompatibility_pluglist_map_for_release(object $pluglist, string $moodlerelease): array {
    $map = [];

    foreach ($pluglist->plugins as $plugin) {
        $component = $plugin->component ?? null;
        if (empty($component)) {
            continue;
        }
        $versions = $plugin->versions ?? [];
        $best = null;
        $bestversionnum = 0;

        $latestvnum = 0;
        $latestrelease = '-';

        foreach ($versions as $version) {
            $vnum = (int) ($version->version ?? 0);
            if ($vnum > $latestvnum) {
                $latestvnum = $vnum;
                $latestrelease = ($version->release ?? '-') . ' (' . ($version->version ?? '') . ')';
            }

            $supported = $version->supportedmoodles ?? [];
            foreach ($supported as $sm) {
                $release = trim($sm->release ?? '');
                $releasemajor = preg_replace('/^(\d+\.\d+).*$/', '$1', $release);
                if ($release === $moodlerelease || $releasemajor === $moodlerelease) {
                    if ($vnum > $bestversionnum) {
                        $bestversionnum = $vnum;
                        $best = (object) [
                            'release' => $version->release ?? '',
                            'version' => $version->version ?? '',
                            'compatible' => true,
                        ];
                    }
                }
            }
        }

        if ($best !== null) {
            $map[$component] = $best;
        } else {
            $map[$component] = (object) ['release' => '', 'version' => '', 'compatible' => false];
        }
        $map[$component]->latestrelease = $latestrelease;
    }

    return $map;
}

/**
 * Get raw data of installed plugins and their compatibility.
 *
 * @param string $version Normalized Moodle version to check.
 * @return array Array of plugin info objects.
 */
function local_plugincompatibility_get_report_data(string $version): array {
    $data = [];
    $pluginman = \core_plugin_manager::instance();
    $pluglist = local_plugincompatibility_get_pluglist();
    $compatmap = $pluglist ? local_plugincompatibility_pluglist_map_for_release($pluglist, $version) : [];

    foreach ($pluginman->get_plugins() as $plugintype => $pluginnames) {
        foreach ($pluginnames as $pluginname => $pluginfo) {
            if ($pluginfo->is_standard() || $pluginfo->is_subplugin() || $pluginname === 'plugincompatibility') {
                continue;
            }

            $component = $plugintype . '_' . $pluginname;
            $dependencies = !empty($pluginfo->dependencies) ? implode(', ', array_keys($pluginfo->dependencies)) : '-';

            $info = $compatmap[$component] ?? null;
            $status = $info ? (!empty($info->compatible) ? 'compatible' : 'notcompatible') : 'notfound';

            $currentversion = (!empty($pluginfo->release) ? $pluginfo->release : '-') . ' (' . ($pluginfo->versiondb ?? '') . ')';
            $data[] = (object) [
                'name' => $pluginfo->displayname ?: $component,
                'component' => $component,
                'dependencies' => $dependencies,
                'currentversion' => $currentversion,
                'status' => $status,
                'compatrelease' => $info->release ?? '',
                'compatversion' => $info->version ?? '',
                'lastrelease' => $info ? $info->latestrelease : '-',
                'has_info' => (bool)$info,
            ];
        }
    }
    return $data;
}


/**
 * Export compatibility report to a specific data format.
 *
 * @param string $version Target Moodle version.
 * @param string $dataformat Format (e.g. 'csv', 'excel').
 * @param array|null $rawdata Optional pre-filtered data.
 */
function local_plugincompatibility_export_report(string $version, string $dataformat, ?array $rawdata = null): void {
    global $CFG;

    if ($rawdata === null) {
        $rawdata = local_plugincompatibility_get_report_data($version);
    }

    $fields = [
        'pluginname' => get_string('pluginname_column', 'local_plugincompatibility'),
        'plugin' => get_string('plugin', 'core'),
        'dependson' => get_string('dependson', 'local_plugincompatibility'),
        'currentversion' => get_string('currentversion_column', 'local_plugincompatibility'),
        'compatibility' => get_string('compatibility_with_version', 'local_plugincompatibility', $version),
        'pluginurl' => get_string('pluginurl', 'local_plugincompatibility'),
        'lastrelease' => get_string('lastrelease', 'local_plugincompatibility'),
    ];

    $rows = [];
    foreach ($rawdata as $plugin) {
        $pluginurl = $plugin->has_info ? LOCAL_PLUGINCOMPATIBILITY_MOODLEORG_VERSIONS_URL . urlencode($plugin->component) : '';
        $statuslabel = get_string($plugin->status, 'local_plugincompatibility');
        $rows[] = [
            $plugin->name,
            $plugin->component,
            $plugin->dependencies,
            $plugin->currentversion,
            $statuslabel,
            $pluginurl,
            $plugin->lastrelease,
        ];
    }

    $host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'moodle';
    $filename = clean_filename($host . '-version_' . $version);
    \core\dataformat::download_data($filename, $dataformat, $fields, $rows);
    exit;
}
