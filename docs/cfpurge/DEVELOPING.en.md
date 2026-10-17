# Cloudflare Cache Purge: developer guide (pkg_cfpurge 2.0)

English summary of [DEVELOPING.md](./DEVELOPING.md) (Italian, more detailed). User guides:
[USAGE.md](./USAGE.md), [USO.md](./USO.md).

> Public repository: never commit tokens, passwords or keys. The tests use a fake Cloudflare API
> and fake tokens (`devtok-...`). A gitleaks hook checks every commit.

## Architecture

```
plugins/pkg_cfpurge/
  pkg_cfpurge.xml          package manifest (update server, children)
  script.php               PHP/Joomla check, switches on what is new, removes the log on uninstall
  extensions/
    plg_content_cfpurge/   element cfpurge, group content (as 1.1.4: in-place upgrade, parameters kept)
    plg_system_cfpurge/    element cfpurge, group system: events a content plugin cannot hear
    mod_cfpurge/           administrator module, position cpanel
```

- **Why a system plugin**: Joomla imports only the `system`, `behaviour` and `user` plugin groups on
  every request. Two events never reach a content plugin (checked in the Joomla 5.4 and 6.2
  sources): `onExtensionAfterSave` / `onExtensionAfterDelete` when a **site module** is saved or
  deleted, and `onAfterPurge` of **System > Clear Cache**. The system plugin listens to them and
  uses the same logic (`Service\Events`).
- **No `ajax` plugin**: `com_ajax` with `group=content&plugin=cfpurge` imports the content group and
  fires `onAjaxCfpurge`; the content plugin answers (actions `status`, `verify`, `purge_all`,
  `purge_urls`).
- **Events**: `Events::afterSave/changeState/afterDelete` check the kind of item (article,
  category, menu item, module, field, tag), the switch and whether it is a site item, then queue a
  purge. `Purger` sends **one call at the end of the request** (`register_shutdown_function`),
  addresses in blocks of 30, the whole cache above 90 addresses. Any error while working out the
  addresses falls back to the whole cache; nothing is ever thrown into a save.
- **Buttons**: `fetch` POST to `index.php?option=com_ajax&group=content&plugin=cfpurge&format=json`
  with the form token; the server checks administrator client, POST, token, `Access::canPurge`
  (Super Users, plus the groups in `allowed_groups`; plugins have no permission screen), and for
  purges a **valid saved verification** (result stored in the plugin params, valid only while the
  fingerprint of Zone ID + token is the same). No table: the verification lives in the params, the
  last purge in the log `plg_content_cfpurge.php` (one JSON line each, rotated above 1 MB, no token,
  no Zone ID).
- **Verification** (`Service\Diagnostics`): format, `GET /user/tokens/verify` (informational:
  account tokens fail it), zone check or lookup by site name (needs the optional Zone Read), and a
  harmless purge of a non-existing address to prove the Purge permission.
- The Cloudflare address is fixed in the code; the token goes only there.

## Test environment (Docker)

`dev/cfpurge/`: Joomla 6.2.0 or 5.4.10 + PHP 8.4 + MariaDB 11.4 and a **fake Cloudflare API** (the
web container resolves `api.cloudflare.com` to itself; `mock/index.php` answers over HTTPS, with a
throw-away CA added to the system and Joomla certificate bundles). The token chooses the behaviour.

```bash
cd dev/cfpurge
cp .env.example .env && docker compose up -d
until docker compose logs web | grep -q AH00163; do sleep 3; done
docker compose exec web bash /dev-scripts/setup.sh
docker compose exec web bash /dev-scripts/mock_cf_setup.sh
python ../../build/build_plugin.py ../../plugins/pkg_cfpurge
python scripts/e2e.py            # all scenarios; or: python scripts/e2e.py install upgrade
```

With Git Bash on Windows prefix `docker compose exec` with `MSYS_NO_PATHCONV=1`. A second Joomla
(5.4) runs side by side with `docker compose -p cfpurge-j5 --env-file .cache/compat-j5.env` and
`python scripts/e2e.py --project cfpurge-j5 --env .cache/compat-j5.env`.

`scripts/e2e.py` scenarios: `install`, `upgrade` (1.1.4 then the package), `visibility`, `verify`,
`state`, `purge`, `events`, `targeted`, `cache`, `update` (local update server), `uninstall`.

JED Checker: Joomla 6 needs *Behaviour - Backward Compatibility 6* on, only for the check. Run
`master` with `scripts/jed_master.py` and `develop` with `scripts/jedchecker_cli.php` on the
**package zip** (both open nested zips). Expected: no errors, no warnings, notes about the
`<licence>` element only.

## Build and release

```bash
python build/build_plugin.py plugins/pkg_cfpurge       # build/out/pkg_cfpurge-X.Y.Z.zip + sha256
```

Child zips are built first (folders `extensions/<name>`), stored under `packages/`; sorted
entries, fixed dates, LF text files: same source, same sha256 on every PC.

1. Version in every manifest (package and three children) and in the two `joomla.asset.json`;
   `plugins/pkg_cfpurge/CHANGELOG.md`, `changelogs/cfpurge.xml`.
2. Build, run `scripts/e2e.py` on Joomla 6.2 and 5.4, JED Checker `master` and `develop`.
3. Zip into `dist/pkg_cfpurge-X.Y.Z.zip`.
4. `updates/cfpurge.xml`: keep **a single `<update>` of `type=plugin`, element `cfpurge`, folder
   `content`** (as today), with the new `<version>`, the `downloadurl` pointing to the **package zip**
   `dist/pkg_cfpurge-X.Y.Z.zip`, its sha256, targetplatform `(5|6)\.[0-9]+`, `<php_minimum>8.1</php_minimum>`.
5. `collection.xml`: the `cfpurge` row (type plugin, folder content) gets the new version.
6. Tag `cfpurge-X.Y.Z` and a GitHub release with the zip and the notes
   (`build/release-notes/cfpurge-X.Y.Z.md`).

**Why the plugin row is enough** (update from 1.1.4 and later): Joomla matches collection rows with
installed extensions by element, type and folder; when the update is applied it reads `detailsurl`
and downloads the zip listed there. If that zip is the package, it installs the children, the
plugin upgrades in place (same extension id, parameters kept) and `pkg_cfpurge` is created; the
plugin carries the package version, so the next release is offered again through the same row. An
extra `type=package` row also works (tested by `e2e.py update`) but would not be enough alone: a site
with only the old plugin has no package installed, and Joomla would not offer it. Verified: 1.1.4
sees one update to 2.0.0, installed as the package; a 2.0.1 built on the fly is then offered once and
does not come back. The package registers the same update server address as the old plugin:
Joomla keeps one update site linked to both.
