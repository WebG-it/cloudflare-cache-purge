# JED submission — campi pronti (plg_content_cfpurge)

Valori pronti da incollare nel form "Add an extension" della Joomla Extensions Directory.
La descrizione DEVE essere in inglese.

## Campi principali

| Campo JED | Valore |
|-----------|--------|
| **Extension Name** | `Cloudflare Cache Purge` |
| **Version** | `1.1.3` |
| **Extensions File** | carica `plg_content_cfpurge-1.1.3.zip` (da `dist/`) |
| My extension includes external libraries | **NO** (lasciare deselezionato) |
| **Category** | `Hosting & Servers → Site speed` (in alternativa `Core Enhancements → Performance`) |
| **Tags** (max 5) | cloudflare, cache, cdn, performance, purge |

## Links

| Campo | Valore |
|-------|--------|
| **Project homepage** * | `https://github.com/WebG-it/joomla-extensions` |
| **Download** * | `https://github.com/WebG-it/joomla-extensions/tree/main/dist` |
| Demo | (vuoto) |
| **Support** | `https://github.com/WebG-it/joomla-extensions/issues` |
| **Documentation** | `https://github.com/WebG-it/joomla-extensions/blob/main/docs/USO.md` |
| License page on your site | (vuoto — solo se a pagamento) |

## Joomla integration options

| Campo | Valore |
|-------|--------|
| **Download type** | `Free Direct Download link:` |
| **Download URL** | `https://raw.githubusercontent.com/WebG-it/joomla-extensions/main/dist/plg_content_cfpurge-1.1.3.zip` |

## Options

| Campo | Valore |
|-------|--------|
| **License** * | `GPLv2 or later` |
| Related Free/Paid | (nessuno) |
| **Compatibility** * | spunta **5.0 compatible** e **6.0 compatible** |
| **uses_updater** * | **spunta** (integra il Joomla Update System; vedi note pre-submit) |
| **Download Type** * | `Free download` |
| **Extension includes** * | spunta **Plugin** |
| Parent extension | (non applicabile) |

## Media

| Campo | Valore |
|-------|--------|
| Video | (vuoto) |
| **Logo** * | carica `assets/cfpurge-jed-logo.png` (1200×525, 16:7) |
| Images (screenshot) | opzionale — uno screenshot del pannello parametri del plugin aiuta |

## Note pre-submission
- **JED Checker**: prima di inviare, esegui il [JED Checker](https://extensions.joomla.org/extension/jedchecker) sullo zip (è una checkbox richiesta da `uses_updater`).
- **Naming**: il nome installato nel manifest è `Content - Cloudflare Cache Purge`. Se la review JED contesta il match nome-entry, allineare il `<name>` del manifest a `Cloudflare Cache Purge`.

---

## Description (incollare nel campo Description, inglese, markdown)

**Cloudflare Cache Purge** automatically clears your Cloudflare cache whenever you create, edit, change the state of, or delete a Joomla article. When you publish or update content, your visitors — and search engine crawlers — see the fresh version immediately instead of a stale cached page.

It is lightweight, adds no front-end output, and is **best-effort**: if the purge call ever fails, your article save is never blocked.

### Features

- Purges the Cloudflare cache (`purge_everything`) on article **create**, **update**, **state change** (publish / unpublish / trash) and **delete**.
- Each of these four events can be switched **on or off** independently in the plugin parameters.
- Works with the standard Joomla back-end workflow and with the native Joomla Web Services API.
- One-click updates through the Joomla Update System.
- Clean, complete uninstall — no custom database tables and no leftover files.

### Configuration

1. Install and enable the plugin.
2. Enter your **Cloudflare Zone ID** and an **API Token** that has the *Cache Purge → Purge* permission, scoped to your zone.
3. Optionally choose which of the four events should trigger the purge.

### External service & privacy

This plugin connects to the **Cloudflare API** (`https://api.cloudflare.com`) to request a cache purge for your configured zone. It sends only an authenticated purge request (your Zone ID and API token, over HTTPS). **No visitor data and no personal data are ever sent.** A Cloudflare account and a site served through Cloudflare are required for the plugin to do anything.

### Documentation & support

- User guide: https://github.com/WebG-it/joomla-extensions/blob/main/docs/USO.md
- Developer guide: https://github.com/WebG-it/joomla-extensions/blob/main/docs/DEVELOPING.md
- Source code & issue tracker: https://github.com/WebG-it/joomla-extensions

Released under the GNU General Public License v2 or later.
