# Guida sviluppatore — plg_content_cfpurge

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

Gli update sono serviti dal repo `WebG-it/joomla-extensions` (collection update server).

1. Bump `<version>` in `cfpurge.xml` + ricompila lo zip (sez. 3).
2. Metti lo zip in `dist/plg_content_cfpurge-X.Y.Z.zip`.
3. Aggiorna `version` e `downloadurl` in `updates/cfpurge.xml`.
4. Aggiorna `version` in `collection.xml`.

I siti vedono l'update in *Sistema → Aggiorna*. Per aggiungere un plugin NUOVO al server:
vedi il `README.md` del repo (3 mosse).

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
