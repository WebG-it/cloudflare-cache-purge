# Cloudflare Cache Purge (pkg_cfpurge)

Joomla package by WebG. It clears the Cloudflare cache when your Joomla site changes and adds a
box to the administrator home page to clear it by hand (the whole cache, or one or more
addresses), with a guided setup that checks the connection.

- Joomla 5.0 or later (5.x and 6.x; not Joomla 4), PHP 8.1 or later.
- Tested on Joomla 6.2.0 and 5.4.10, PHP 8.4, with a fake Cloudflare API (no real token is ever
  used in the tests).
- No table in the database; the uninstall removes every file, the module, the log and the
  update server.
- License: GNU GPL version 2 or later (see `LICENSE.txt`).

## What is inside

| Extension | Type | What it does |
|---|---|---|
| `plg_content_cfpurge` (Content - Cloudflare Cache Purge) | plugin | the options (Zone ID, token, guided setup, connection check), the purge on articles, categories, menu items, custom fields and tags, and the com_ajax entry point of the buttons |
| `plg_system_cfpurge` (System - Cloudflare Cache Purge) | plugin | hears the two events a content plugin cannot hear: saving or deleting a site module, and System > Clear Cache. No options of its own |
| `mod_cfpurge` (Cloudflare Cache Purge) | administrator module | the box on the administrator home page (position `cpanel`), published by the installer |
| `com_cfpurge` (Cloudflare Cache Purge) | component, API only | no screens: the permissions (read the state, clear the cache) and the controller of the Web Services API |
| `plg_webservices_cfpurge` (Web Services - Cloudflare Cache Purge) | plugin | registers the three API routes: `GET v1/cfpurge/status`, `POST v1/cfpurge/all`, `POST v1/cfpurge/urls`; switch it off to close the API |

It replaces the standalone plugin `plg_content_cfpurge` 1.1.4: installing the package over it (or
updating from *System > Update*) is an upgrade in place, Zone ID, token and switches are kept.

## Install

Download `pkg_cfpurge-2.1.1.zip` from the
[releases](https://github.com/WebG-it/cloudflare-cache-purge/releases) (or [dist/](../../dist)), then
*System > Install > Extensions > Upload Package File*. Open *System > Plugins > Content -
Cloudflare Cache Purge* and follow the 4 steps at the top of the options.

Full guides: [USAGE.md](../../docs/cfpurge/USAGE.md) (English) and
[USO.md](../../docs/cfpurge/USO.md) (Italiano).

## Source layout

```
pkg_cfpurge.xml            package manifest (update server, children)
script.php                 checks PHP/Joomla, switches on what is new, removes the log on uninstall
language/{en-GB,it-IT}/    package name and install message
extensions/<child>/        one source folder per child; build/build_plugin.py zips them first
```

Build: `python build/build_plugin.py plugins/pkg_cfpurge` (see
[DEVELOPING.md](../../docs/cfpurge/DEVELOPING.md)).
