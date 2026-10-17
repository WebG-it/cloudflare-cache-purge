# Changelog

## 2.0.0 (2026-10-17)

From a single plugin to a package, with a box on the administrator home page and a guided setup.

New
- **Box on the administrator home page** (module `mod_cfpurge`, position `cpanel`, published by
  the installer): traffic light of the connection (not configured, not verified, connected,
  problem), button *Clear the whole cache*, field for one or more addresses (one per line, a path
  such as `/page` or a full address of the site) with *Clear these addresses*, the outcome of the
  last purge (who, when, what, why, Cloudflare answer) and a *Verify connection* button.
- **Guided setup** at the top of the plugin options: where to find the Zone ID, how to create
  the token (Zone > Cache Purge > Purge, optionally Zone > Zone > Read) and a **Verify
  connection** button that checks the token, the zone and the permission (with a harmless test
  purge) and explains in plain words what is wrong, in English and Italian. With the optional
  Zone Read permission it finds the Zone ID of the site by itself and refuses the Zone ID of
  another domain.
- **Purge mode**: the whole cache (default, as before) or only the changed addresses (article,
  its category and parent categories, home page, fixed extra addresses such as `/sitemap.xml`;
  the old address too when an article is renamed or moved; the whole cache when an address
  cannot be worked out or after more than 90 addresses).
- **More events**, each with its own switch (on by default): categories, menu items, site
  modules, custom fields and field groups, tags. State changes and deletions too.
- **System > Clear Cache** can clear Cloudflare too (switch, off by default; *Purge Expired*
  never does).
- Cloudflare's limit of 30 addresses per call is handled in blocks; several saves in one request
  (a batch publish) are one call.
- **Log** `administrator/logs/plg_content_cfpurge.php` (one JSON line per purge or check,
  rotated above 1 MB; no token, no Zone ID). The box shows the last purge from it.
- **Who can use the buttons**: Super Users, plus the user groups chosen in the options (default
  Administrator and Super Users). The server checks it on every call, together with the form
  token; the buttons stay off until the connection is verified.
- Italian language files (en-GB and it-IT) for the package, the plugins and the module.

Changed
- A failed purge is no longer silent: it is logged and the box shows it (a Cloudflare refusal was
  treated as a success before). A purge call times out after 8 seconds.
- The purge is sent once, at the end of the request, instead of while the article is saving.
- Packaging: `pkg_cfpurge` contains the content plugin (same element `cfpurge`, same group, so
  Zone ID, token and switches are kept on upgrade), the system plugin and the module. The update
  server entry stays the plugin's (`type="plugin"`) but downloads the package, so sites that have the
  standalone plugin 1.1.4 are offered 2.0.0.

Good to know
- The Joomla *Clear Cache* event and the module events are heard by the system plugin: keep it
  enabled (it does nothing while the content plugin is off).
- The *targeted* mode cannot know every page that shows an article (side modules, search,
  pagination, tags, language home pages): they expire with the Cloudflare cache rules. Use the
  whole cache when that matters.
- After the upgrade, open the plugin options and click *Verify connection* once: the buttons of
  the box stay off until the saved connection is verified.
- Uninstalling the package removes everything, including the log files.

Checked: install, upgrade over 1.1.4, update through the update server (also to a later
release), purge from the dashboard, verification with wrong tokens and zones, events, targeted
addresses and uninstall on Joomla 6.2.0 and 5.4.10 (PHP 8.4), with a fake Cloudflare API.
JED Checker `master` and `develop` on the package: no errors, no warnings; only the notes about
the `<licence>` element (required by rule PH3 of `develop`).

## 1.1.4 and earlier (standalone plugin `plg_content_cfpurge`)

See [changelogs/cfpurge.xml](../../changelogs/cfpurge.xml): purge of the whole cache on article
create, update, state change and delete, with a switch for each event.
