# JED submission: campi pronti (pkg_cfpurge)

Valori pronti da incollare nel form "Add an extension" della Joomla Extensions Directory.
La descrizione DEVE essere in inglese.

## Campi principali

| Campo JED | Valore |
|-----------|--------|
| **Extension Name** | `Cloudflare Cache Purge` |
| **Version** | `2.1.1` |
| **Extensions File** | carica `pkg_cfpurge-2.1.1.zip` (da `dist/`) |
| My extension includes external libraries | **NO** (lasciare deselezionato) |
| **Category** | `Hosting & Servers → Site speed` (in alternativa `Core Enhancements → Performance`) |
| **Tags** (max 5) | cloudflare, cache, cdn, performance, purge |

## Links

| Campo | Valore |
|-------|--------|
| **Project homepage** * | `https://github.com/WebG-it/cloudflare-cache-purge` |
| **Download** * | `https://github.com/WebG-it/cloudflare-cache-purge/tree/main/dist` |
| Demo | (vuoto) |
| **Support** | `https://github.com/WebG-it/cloudflare-cache-purge/issues` |
| **Documentation** | `https://github.com/WebG-it/cloudflare-cache-purge/blob/main/docs/cfpurge/USAGE.md` |
| License page on your site | (vuoto, solo se a pagamento) |

## Joomla integration options

| Campo | Valore |
|-------|--------|
| **Download type** | `Free Direct Download link:` |
| **Download URL** | `https://raw.githubusercontent.com/WebG-it/cloudflare-cache-purge/main/dist/pkg_cfpurge-2.1.1.zip` |

## Options

| Campo | Valore |
|-------|--------|
| **License** * | `GPLv2 or later` |
| Related Free/Paid | (nessuno) |
| **Compatibility** * | spunta **5.0 compatible** e **6.0 compatible** |
| **uses_updater** * | **spunta** (integra il Joomla Update System; vedi note pre-submit) |
| **Download Type** * | `Free download` |
| **Extension includes** * | spunta **Plugin** e **Module** (e **Package** se il form lo chiede) |
| Parent extension | (non applicabile) |

## Media

| Campo | Valore |
|-------|--------|
| Video | (vuoto) |
| **Logo** * | carica `assets/cfpurge-jed-logo.png` (1200×525, 16:7) |
| Images (screenshot) | opzionale: uno screenshot del pannello parametri del plugin aiuta |

## Note pre-submission
- **JED Checker**: prima di inviare, esegui il [JED Checker](https://extensions.joomla.org/extension/jedchecker) sullo zip (è una checkbox richiesta da `uses_updater`).
- **Naming**: il nome installato nel manifest è `Content - Cloudflare Cache Purge`. Se la review JED contesta il match nome-entry, allineare il `<name>` del manifest a `Cloudflare Cache Purge`.

---

## Description (incollare nel campo Description, inglese, markdown)

**Cloudflare Cache Purge** clears your Cloudflare cache when your Joomla site changes, and adds a box to the administrator home page to clear it by hand: the whole cache, or just the addresses you type. Visitors and search engine crawlers see fresh content instead of a stale cached page.

It is **best-effort**: if the purge call ever fails, your save is never blocked; the failure is logged and shown in the box.

### Features

- **Automatic purge** when an article, category, menu item, site module, custom field or tag is saved, changes state or is deleted. Each kind of change has its own switch.
- **Whole cache or only the changed addresses** (article, its categories, home page, fixed extras such as the sitemap). Optionally also when you use *System > Clear Cache*.
- **Administrator home page box**: traffic light of the Cloudflare connection, *Clear the whole cache*, a field for one or more addresses, and the outcome of the last purge. Shown only to Super Users and the user groups you choose; every request is checked on the server.
- **Guided setup** with a *Verify connection* button: where to find the Zone ID, how to create a token with only the *Zone > Cache Purge > Purge* permission, and plain-language messages when something is wrong. With the optional *Zone > Zone > Read* permission it finds the Zone ID by itself.
- Works with the standard back-end, the front-end article form and the Joomla Web Services API.
- One-click updates through the Joomla Update System. English and Italian.
- Clean, complete uninstall: no custom database tables, no leftover files (the log is removed too).

### Configuration

1. Install the package; it enables the plugins and publishes the box on the home page.
2. Open *System > Plugins > Content - Cloudflare Cache Purge* and follow the four steps at the top: Zone ID, API token, paste, **Verify connection**, Save.
3. Optionally choose what to clear and which changes trigger it.

### External service & privacy

This extension connects to the **Cloudflare API** (`https://api.cloudflare.com`) to verify your token and zone and to request a cache purge for your configured zone. It sends only authenticated API requests (your API token, the Zone ID and, when you purge only some pages, the public addresses of your own site), over HTTPS. **No visitor data and no personal data are ever sent.** The verification sends one purge request for an address that does not exist, to prove the token may purge. The token is stored in the plugin options and written nowhere else (not in the log). A Cloudflare account and a site served through Cloudflare are required for the extension to do anything.

### Documentation & support

- User guide: https://github.com/WebG-it/cloudflare-cache-purge/blob/main/docs/cfpurge/USAGE.md
- Developer guide: https://github.com/WebG-it/cloudflare-cache-purge/blob/main/docs/cfpurge/DEVELOPING.en.md
- Source code & issue tracker: https://github.com/WebG-it/cloudflare-cache-purge

Released under the GNU General Public License v2 or later.
