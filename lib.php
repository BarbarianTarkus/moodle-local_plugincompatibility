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
const LOCAL_PLUGINCOMPATIBILITY_PLUGLIST_URL = 'https://download.moodle.org/api/1.3/pluglist.php';

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
    // The decoded PHP object tree can consume 100-200 MB; MEMORY_EXTRA gives 256 MB headroom.
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

    // Cache the raw JSON string (much cheaper to serialise than the object tree).
    $cache->set('pluglist_json', $response);
    return $data;
}

/**
 * Build a map component -> best matching version info for a given Moodle release.
 * Uses API pluglist: for each plugin, finds a version that supports the given release.
 *
 * @param object $pluglist Result from local_plugincompatibility_get_pluglist().
 * @param string $moodlerelease Normalized target release (e.g. "4.5", "4.4").
 * @return array Associative array component => object with at least 'release', 'compatible' (bool).
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

        foreach ($versions as $ver) {
            $supported = $ver->supportedmoodles ?? [];
            foreach ($supported as $sm) {
                $release = trim($sm->release ?? '');
                // Match by release string (e.g. "4.5") or by major.minor (e.g. "4.5.1" -> "4.5").
                $releasemajor = preg_replace('/^(\d+\.\d+).*$/', '$1', $release);
                if ($release === $moodlerelease || $releasemajor === $moodlerelease) {
                    $vnum = (int) ($ver->version ?? 0);
                    if ($vnum > $bestversionnum) {
                        $bestversionnum = $vnum;
                        $best = (object) [
                            'release' => $ver->release ?? '',
                            'compatible' => true,
                        ];
                    }
                }
            }
        }

        if ($best !== null) {
            $map[$component] = $best;
        } else {
            $map[$component] = (object) ['release' => '', 'compatible' => false];
        }
    }

    return $map;
}

/**
 * Get list of installed (non-standard, non-subplugin) plugins with compatibility for a target version.
 * Uses Moodle pluglist API 1.3 to determine compatibility.
 *
 * @param string $destinationversion Normalized Moodle version (e.g. "4.5") to check against.
 * @return array List of rows: [component, dependencies_string, compatibility_html].
 */
function get_installed_plugins(string $destinationversion): array {
    $data = [];
    $pluginman = \core_plugin_manager::instance();
    $pluglist = local_plugincompatibility_get_pluglist();
    $compatmap = $pluglist ? local_plugincompatibility_pluglist_map_for_release($pluglist, $destinationversion) : [];

    foreach ($pluginman->get_plugins() as $plugintype => $pluginnames) {
        foreach ($pluginnames as $pluginname => $pluginfo) {
            if ($pluginfo->is_standard() || $pluginfo->is_subplugin()) {
                continue;
            }
            if ($pluginname === 'plugincompatibility') {
                continue;
            }

            $component = $plugintype . '_' . $pluginname;
            $dependencies = '-';
            if (!empty($pluginfo->dependencies)) {
                $deps = [];
                foreach ($pluginfo->dependencies as $dep => $req) {
                    $deps[] = $dep;
                }
                $dependencies = implode(', ', $deps);
            }

            $compatible = check_compatible_version($destinationversion, $component, $compatmap);
            $plugincell = $component;
            if (isset($compatmap[$component])) {
                $pluginurl = LOCAL_PLUGINCOMPATIBILITY_MOODLEORG_VERSIONS_URL . urlencode($component);
                $plugincell = \html_writer::link(
                    $pluginurl,
                    s($component),
                    ['target' => '_blank', 'rel' => 'noopener noreferrer', 'class' => 'local_plugincompatibility-pluginlink']
                );
            }
            $data[] = [$plugincell, $dependencies, $compatible];
        }
    }

    return $data;
}

/**
 * Check if a plugin is compatible with the given Moodle version using the compatibility map.
 *
 * @param string $version Normalized target version (e.g. "4.5").
 * @param string $pluginname Component name (e.g. "mod_zoom").
 * @param array $compatmap Map from local_plugincompatibility_pluglist_map_for_release (optional).
 * @return string HTML fragment for the compatibility cell.
 */
function check_compatible_version(string $version, string $pluginname, array $compatmap = []): string {
    if (empty($compatmap)) {
        $pluglist = local_plugincompatibility_get_pluglist();
        $compatmap = $pluglist ? local_plugincompatibility_pluglist_map_for_release($pluglist, $version) : [];
    }

    if (!isset($compatmap[$pluginname])) {
        return \html_writer::tag(
            'span',
            get_string('notfound', 'local_plugincompatibility'),
            ['class' => 'local_plugincompatibility-notfound']
        );
    }

    $info = $compatmap[$pluginname];
    if (!empty($info->compatible)) {
        $text = get_string('compatible', 'local_plugincompatibility');
        if (!empty($info->release)) {
            $text .= ' (' . s($info->release) . ')';
        }
        return \html_writer::tag('span', $text, ['class' => 'local_plugincompatibility-compatible']);
    }

    return \html_writer::tag(
        'span',
        get_string('notcompatible', 'local_plugincompatibility'),
        ['class' => 'local_plugincompatibility-notcompatible']
    );
}

/**
 * Extend navigation with Plugin compatibility link (admin only).
 *
 * @param \global_navigation $nav
 */
function local_plugincompatibility_extend_navigation(\global_navigation $nav): void {
    global $CFG;
    if (!has_capability('moodle/site:config', \context_system::instance())) {
        return;
    }
    require_once($CFG->libdir . '/environmentlib.php');
    $currentversion = normalize_version($CFG->release);
    $parts = explode('.', $currentversion);
    $major = $parts[0] . '.' . ($parts[1] ?? '0');

    $url = new \moodle_url('/local/plugincompatibility/index.php', ['version' => $major]);
    $node = $nav->add(
        get_string('pluginname', 'local_plugincompatibility'),
        $url,
        \navigation_node::TYPE_SETTING,
        null,
        'local_plugincompatibility_table',
        new \pix_icon('t/log', '')
    );
    $node->showinflatnavigation = true;
}
