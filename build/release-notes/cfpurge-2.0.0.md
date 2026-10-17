## Cloudflare Cache Purge 2.0.0

From a single plugin to a package: a box on the administrator home page to clear the Cloudflare
cache by hand, a guided setup with a connection check, and more cases covered. Update from
*System > Update > Extensions*, or install the package over the old plugin.

**New**
- **Box on the administrator home page**: traffic light of the Cloudflare connection, *Clear the
  whole cache*, a field to clear one or more addresses (one per line), the outcome of the last
  purge. For Super Users and the user groups chosen in the options; checked on the server on every
  call, with the form token.
- **Guided setup** at the top of the plugin options (Zone ID, token, the permissions the token
  needs) and a **Verify connection** button: it checks the token, the zone and the purge
  permission with a harmless test and explains in plain words what is wrong, in English and
  Italian. With the optional Zone Read permission it finds the Zone ID of the site by itself.
- **Purge mode**: the whole cache (default, as before) or only the changed addresses (article, its
  categories, home page, fixed extra addresses such as `/sitemap.xml`; the old address too when an
  article is renamed or moved).
- **More events**, each with its own switch: categories, menu items, site modules, custom fields,
  tags; optionally also *System > Clear Cache*.
- Log of every purge in `administrator/logs/plg_content_cfpurge.php` (no token, no Zone ID).

**Changed**
- Packaging: `pkg_cfpurge` = content plugin (same element and group as before) + system plugin +
  administrator module. Zone ID, token and switches are kept.
- A purge refused by Cloudflare is no longer treated as done: it is logged and shown in the box.
  The purge is sent once at the end of the request, in blocks of 30 addresses, with an 8 second
  timeout.

**Good to know**
- After the upgrade open the plugin options and click **Verify connection** once: the buttons of
  the box stay off until the saved connection is verified.
- The targeted mode does not clear the pages that show an article elsewhere (side modules, search,
  pagination, tags, language home pages): use the whole cache when that matters.
- Keep the *System - Cloudflare Cache Purge* plugin enabled: it hears the module events and *Clear
  Cache*. Uninstalling the package removes everything, including the log.

**Checked** on Joomla 6.2.0 and 5.4.10 (PHP 8.4, fake Cloudflare API): clean install, upgrade over
1.1.4, update through the update server (also to a later release), purge from the box, wrong
tokens and zones, all the events, targeted addresses, uninstall. JED Checker `master` and
`develop` on the package: no errors, no warnings, notes only about the `<licence>` element.

Docs: [user guide](https://github.com/WebG-it/joomla-extensions/blob/main/docs/cfpurge/USAGE.md) ·
[guida in italiano](https://github.com/WebG-it/joomla-extensions/blob/main/docs/cfpurge/USO.md) ·
[changelog](https://github.com/WebG-it/joomla-extensions/blob/main/plugins/pkg_cfpurge/CHANGELOG.md)

sha256 `524db1083999154590c2d739cf668e32da1cf8be03373441e4319b0fb2f3c5e2`
