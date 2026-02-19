# Plugin compatibility

This plugin checks your **installed third‑party plugins** against the official Moodle plugins directory to see whether a version is available for a given Moodle release (e.g. 4.5). It helps plan upgrades: you can select a target Moodle version and see which of your plugins are listed as compatible on Moodle.org.

## What it does

- Lists all **non‑core** plugins installed on your site (by component: `mod_zoom`, `block_foo`, etc.).
- Shows their **dependencies** (from `version.php`).
- For a **chosen Moodle version** (current or a future one from the environment matrix), shows whether the plugin appears in the [Moodle pluglist API](https://download.moodle.org/api/1.3/pluglist.php) with a version that supports that release:
  - **Compatible** – the plugin has at least one version in the directory that supports the selected Moodle version.
  - **Not compatible** – no such version is listed.
  - **Not found** – the component is not in the pluglist (e.g. private or not published).

Data is taken from **https://download.moodle.org/api/1.3/pluglist.php** (cached for 1 hour). No scraping of HTML; compatibility is derived from the API’s `supportedmoodles` for each plugin version.

## Requirements

- Moodle 4.1+ (tested on 4.5).
- Capability `moodle/site:config` (administrators).

## Installation

1. Copy the `plugincompatibility` folder into `local/`.
2. Visit **Site administration → Notifications** and complete the upgrade.

## Usage

- Go to **Site administration → Plugin compatibility** (or **/local/plugincompatibility/index.php**).
- Choose the **Moodle version** you want to check (e.g. 4.5).
- The table shows each installed plugin and its compatibility for that version.
- Use the download selector to export the table (e.g. CSV).

## License

2020 Raúl Martínez <raulmartinez911@hotmail.com>

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
