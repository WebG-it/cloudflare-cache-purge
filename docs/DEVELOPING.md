# Guida sviluppatore — plg_content_cfpurge

> **Strumenti.** L'ambiente Docker di prova (`dev/...`), lo script di build (`build/build_plugin.py`) e gli helper del JED Checker non stanno in questo repository: sono in [joomla-dev-tools](https://github.com/WebG-it/joomla-dev-tools). I percorsi `dev/` e `build/` qui sotto si riferiscono a quel repository (clonalo accanto a questo). L'update server di questo plugin e' `collection.xml` (rimanda a `updates/cfpurge.xml`) in questo repository.

Documentazione **tecnica**, destinata a uno **sviluppatore o a un'AI** che deve modificare,
compilare o rilasciare il plugin. Tratta codice, build dello zip e processo di rilascio.

> 👤 **Sei un umano che deve solo installare/configurare/aggiornare/disinstallare?**
> Non ti serve questo file: vai su **[`USO.md`](./USO.md)** (tutto da interfaccia Joomla,
> nessuna compilazione).

> ⚠️ Repo **PUBBLICO**: mai token, password o chiavi reali nei file. I valori dei token
> stanno nei parametri del plugin sul sito, non nel codice.

---

## 1. Cosa fa e quando scatta

Su `onContentAfterSave` / `onContentChangeState` / `onContentAfterDelete` di un articolo,
il plugin fa una POST a Cloudflare con `{"purge_everything": true}` sulla zona configurata.
Best-effort: se la chiamata fallisce, il salvataggio dell'articolo non viene mai bloccato.

Da **1.1.0** ogni evento è attivabile dai parametri `on_create` / `on_update` / `on_state` /
`on_delete` (default tutti ON). Creazione vs modifica si distinguono col flag **`isNew`** di
`onContentAfterSave`. Un guard statico (`$alreadyPurged`) evita purge doppi nella stessa richiesta.

**Quando scatta e quando NO** (cruciale):

- ✅ Salvataggio da **backend Joomla** (UI).
- ✅ **API nativa di Joomla** (`/api/index.php/v1/content/articles`) → passa per il modello
  articolo → emette gli eventi → il plugin scatta. *(È il percorso usato dal bot.)*
- ❌ Scritture **SQL dirette** sul DB (endpoint custom che fanno INSERT/UPDATE a mano):
  NON emettono eventi → il plugin NON scatta.

Nota campi API nativa: in PATCH il campo `text` è read-only/calcolato (200 ma non persiste);
i campi scrivibili sono `introtext`, `fulltext`, `metadesc`, `title`, ecc.

---

## 2. Struttura del codice

```
cfpurge.xml                  ← manifest (metadati, parametri, <updateservers>)
services/provider.php        ← service provider (pattern Joomla 4/5/6)
src/Extension/Cfpurge.php    ← classe del plugin: SubscriberInterface, logica purge
```

`element` = `cfpurge`, gruppo = `content`, namespace = `WebG\Plugin\Content\Cfpurge`.

### Modificare
- **Purge mirato** invece che totale: in `purgeEverything()` usa `{"files":[url,...]}`.
- **Nuovo parametro**: aggiungi un `<field>` in `cfpurge.xml` e leggilo con `$this->params->get('nome')`.
- **Nuovo evento**: aggiungilo in `getSubscribedEvents()`.

Dopo ogni modifica → **bump `<version>`** in `cfpurge.xml` e ricompila.

### Nota disinstallazione (perché NON serve uno script)
Il plugin non crea tabelle DB né file fuori dalla sua cartella. L'unico stato persistente
sono i suoi params in `#__extensions`, che Joomla rimuove da solo alla disinstallazione.
Quindi **niente `script.php` né blocco `<uninstall>`**: la rimozione standard è già completa.
Aggiungere un uninstall handler serve SOLO se in futuro il plugin creasse tabelle/dati custom.

---

## 3. COMPILARE LO ZIP (puntiglioso — leggi tutto)

Lo zip deve avere i file **alla radice** e con separatori **in avanti `/`**.

### ⚠️ Trabocchetto Windows
`Compress-Archive` di PowerShell scrive i percorsi con **backslash `\`** → l'installer di
Joomla (logica Unix) sbaglia l'estrazione (crea file `services\provider.php` invece della
cartella). **Non usare `Compress-Archive`.** Usa un metodo che produce `/`:

### Metodo A — Linux/macOS
```bash
cd plugin-source/                 # contiene cfpurge.xml, services/, src/
zip -r ../plg_content_cfpurge-1.0.1.zip cfpurge.xml services src
```

### Metodo B — Windows (PowerShell, .NET)
```powershell
$base = "C:\percorso\plugin-source"
$dest = "C:\percorso\plg_content_cfpurge-1.0.1.zip"
if (Test-Path $dest) { Remove-Item $dest -Force }
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($dest, [System.IO.Compression.ZipArchiveMode]::Create)
$map = @{
  "cfpurge.xml"               = "$base\cfpurge.xml"
  "services/provider.php"     = "$base\services\provider.php"
  "src/Extension/Cfpurge.php" = "$base\src\Extension\Cfpurge.php"
}
foreach ($e in $map.Keys) { [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip,$map[$e],$e) | Out-Null }
$zip.Dispose()
```

### Verifica
```powershell
[System.IO.Compression.ZipFile]::OpenRead($dest).Entries | ForEach-Object { $_.FullName }
# Atteso (con '/', file alla radice):
#   cfpurge.xml
#   services/provider.php
#   src/Extension/Cfpurge.php
```
Se compare un `\` → zip sbagliato, rifallo.

---

## 4. Rilasciare una nuova versione (update server)

L'update server di questo plugin sta in questo repository: `collection.xml` (una riga, tipo
`collection`, servito da `raw.githubusercontent.com/WebG-it/cloudflare-cache-purge/main/collection.xml`
e dichiarato nel manifest in `<updateservers>`) che rimanda a `updates/cfpurge.xml` (versione, download,
sha256). Il tipo e' `collection` perche' Joomla, aggiornando, cambia solo l'indirizzo della riga di
update site che il sito ha gia' e ne conserva il tipo: i siti arrivati dal vecchio collection condiviso
resterebbero ciechi su un file di tipo `extension`.

1. Bump `<version>` nei manifest: ogni modifica a uno zip fa avanzare la versione, mai due zip
   diversi con lo stesso numero. Voce in `changelogs/cfpurge.xml` e note in
   `build/release-notes/cfpurge-X.Y.Z.md`.
2. Compila con `python build/build_plugin.py <cartella>` (script di joomla-dev-tools): zip
   riproducibile con sha256 stampato.
3. Metti lo zip in `dist/`.
4. Aggiorna `updates/cfpurge.xml` (`version`, `downloadurl` sul file in `dist/`, che esiste al push,
   `sha256`) e la `version` della riga in `collection.xml`.
5. JED Checker (`master` e `develop`) e prova di aggiornamento in Docker con gli strumenti di
   joomla-dev-tools.
6. Tag `vX.Y.Z` e GitHub Release con lo zip e le note.

I siti vedono l'update in *Sistema → Aggiorna*.

---

## 5. Credenziali per test/sviluppo

- **Cloudflare** (Zone ID + API Token "Cache Purge"): procedura click-per-click in
  [`USO.md`](./USO.md) sez. 2.
- **Token API nativa Joomla** (per testare il purge sul percorso del bot): in Joomla,
  *Utenti → Gestisci →* utente API *→* tab **"Joomla API Token"** (richiede il plugin
  "API Authentication - Joomla Token" attivo). Header `Authorization: Bearer <token>`;
  dietro Cloudflare serve uno **User-Agent valido** (altrimenti errore 1010).

### Verifica funzionamento (header)
1. Scalda la cache: GET pagina finché `Cf-Cache-Status: HIT`.
2. Salva un articolo (UI o API nativa).
3. La pagina deve andare in `Cf-Cache-Status: MISS`, poi `HIT` con `Age: 0`.
   Prova del contenuto: cambia `metadesc` via API nativa, verifica che compaia nella pagina
   servita dopo il purge, poi ripristina.
