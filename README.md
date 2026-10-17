# Cloudflare Cache Purge

Joomla content plugin by [WebG](https://www.webg.it), free software under the GNU GPL v2 or later.
It clears the **Cloudflare cache** when an article is created, changed, published or deleted, so
visitors see the new content at once. Best effort: if the call to Cloudflare fails, saving the
article is never blocked.

- Each event (create, update, change of state, delete) has its own switch, all on by default.
- Works from the Joomla backend and from the native Joomla API.
- Needs only the Zone ID and a Cloudflare API token limited to *Cache Purge*; both are kept in
  the plugin options of the site, never in the code.

**Requirements**: Joomla 5 or 6, PHP 8.1+, a site behind Cloudflare.

**Install**: download `plg_content_cfpurge-1.1.4.zip` from the
[releases](https://github.com/WebG-it/cloudflare-cache-purge/releases) (or [dist/](./dist)), then
*System > Install > Extensions > Upload Package File*. Step by step guide (italiano):
[docs/USO.md](./docs/USO.md). Developer guide: [docs/DEVELOPING.md](./docs/DEVELOPING.md).

**Updates**: automatic, in *System > Update > Extensions*. The update server of this plugin is
[`collection.xml`](./collection.xml) in this repository
(`https://raw.githubusercontent.com/WebG-it/cloudflare-cache-purge/main/collection.xml`), which points to [`updates/cfpurge.xml`](./updates/cfpurge.xml).
The 1.1.x releases still declare the shared WebG collection of
[joomla-extensions](https://github.com/WebG-it/joomla-extensions) in their manifest (no site uses them);
2.x declares this repository.

**Version 2.0** (a package with a box on the administrator home page, a guided setup and more
events) is developed on the branch `cfpurge-2.0` and is not released yet.

## Repository

```
dist/                            installable zips (one per released version)
collection.xml                   update server of this plugin (one row)
updates/cfpurge.xml              its details: version, download, sha256
changelogs/cfpurge.xml           changelog feed shown by Joomla
build/release-notes/             text of each GitHub release
docs/                            user guide (USO), developer guide (DEVELOPING), JED listing fields
assets/                          logos
```

Build script, Docker test sites (Joomla 5 and 6) and JED Checker helpers are shared by all WebG
plugins and live in [joomla-dev-tools](https://github.com/WebG-it/joomla-dev-tools): not copied here.
A new version always changes the version number: two different zips never share one.

Support: [GitHub issues](https://github.com/WebG-it/cloudflare-cache-purge/issues) (write your
Joomla and PHP versions; never post passwords, keys or tokens).

License: GNU GPL v2 or later.
