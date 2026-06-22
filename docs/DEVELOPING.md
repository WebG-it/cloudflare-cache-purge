# Guida sviluppatore — plg_content_cfpurge

Documentazione completa per **modificare, compilare e rilasciare** il plugin Joomla
`plg_content_cfpurge` (Content - Cloudflare Cache Purge). Scritta per essere seguibile
da chiunque, anche senza conoscere Joomla a fondo.

> ⚠️ Questo repo è **PUBBLICO**. Qui dentro NON vanno mai token, password o chiavi reali.
> I valori dei token si inseriscono nei **parametri del plugin sul sito**, non nel codice.

---

## 1. Cosa fa il plugin

Quando un articolo Joomla viene **salvato, cambia stato o viene cancellato**, il plugin
chiama l'API di Cloudflare e fa un **purge totale** (`purge_everything`) della cache della
zona configurata. Serve a far sparire subito dalla cache edge le pagine vecchie quando
pubblichi/modifichi un contenuto, così gli utenti (e Googlebot) vedono subito il nuovo.

È **best-effort**: se la chiamata a Cloudflare fallisce, il salvataggio dell'articolo NON
viene mai bloccato.

### Quando scatta e quando NO (importante)

Il plugin si aggancia agli eventi Joomla `onContentAfterSave` / `onContentChangeState` /
`onContentAfterDelete`. Questi eventi scattano **solo se l'articolo viene salvato tramite
il modello di Joomla**, cioè:

- ✅ Backend Joomla (modifica articolo da UI).
- ✅ **API nativa di Joomla** (`/api/index.php/v1/content/articles`), che passa per il modello → eventi → purge. *(È così che pubblica il bot.)*
- ❌ Scritture **SQL dirette** sul database (es. endpoint custom che fanno `INSERT/UPDATE` a mano): NON emettono eventi → il plugin NON scatta. In quel caso il purge va fatto a parte.

---

## 2. Struttura dei file del plugin

```
cfpurge.xml                  ← manifest (metadati, parametri, update server)
services/provider.php        ← service provider (registra il plugin, pattern Joomla 4/5/6)
src/Extension/Cfpurge.php    ← la classe del plugin (logica)
```

- **`cfpurge.xml`** — dichiara nome/versione/autore, i campi `zone_id` e `api_token`, il
  namespace `WebG\Plugin\Content\Cfpurge`, e il blocco `<updateservers>` (per gli update).
- **`services/provider.php`** — istanzia la classe `Cfpurge` e la collega al dispatcher.
- **`src/Extension/Cfpurge.php`** — implementa `SubscriberInterface`, sottoscrive i 3 eventi,
  e in `purgeEverything()` fa la POST a `https://api.cloudflare.com/client/v4/zones/<zone>/purge_cache`.

L'`element` del plugin è **`cfpurge`**, il gruppo è **`content`**.

---

## 3. Come modificare il plugin

- **Cambiare cosa viene purgato** (es. solo l'URL dell'articolo invece di tutto): modifica
  `purgeEverything()` in `src/Extension/Cfpurge.php`. Per un purge mirato useresti
  `{"files":["url1","url2"]}` invece di `{"purge_everything": true}`.
- **Aggiungere un parametro** (es. un TTL, un flag): aggiungi un `<field>` in `cfpurge.xml`
  dentro `<fieldset name="basic">`, e leggilo con `$this->params->get('nome')`.
- **Aggiungere un evento**: aggiungilo in `getSubscribedEvents()` mappandolo a un metodo.

Dopo OGNI modifica → **bump della versione** in `cfpurge.xml` (`<version>`) e ricompila.

---

## 4. COME COMPILARE LO ZIP (passo-passo, leggi con attenzione)

Joomla installa il plugin da un file **.zip**. Il pacchetto deve avere i file **alla radice**
dello zip (non dentro una sottocartella) e con i **separatori di percorso in avanti `/`**.

### ⚠️ Il trabocchetto principale (Windows)

Su Windows, il comando PowerShell `Compress-Archive` scrive i percorsi nello zip con il
**backslash `\`** (`services\provider.php`). L'installer di Joomla (logica Unix) **sbaglia
l'estrazione**: crea file con nomi letterali tipo `services\provider.php` invece della
cartella `services/`. Risultato: **installazione fallita o plugin rotto**.

➡️ Quindi: **NON usare `Compress-Archive` su Windows.** Usa uno dei due metodi qui sotto, che
producono percorsi con `/` come da standard ZIP.

### Metodo A — Linux / macOS (il più semplice)

Il comando `zip` usa nativamente gli slash in avanti.

```bash
# posizionati nella cartella che CONTIENE cfpurge.xml, services/, src/
cd plugin-source/
zip -r ../plg_content_cfpurge-1.0.1.zip cfpurge.xml services src
```

### Metodo B — Windows (PowerShell, con .NET — slash corretti garantiti)

```powershell
$base = "C:\percorso\plugin-source"          # cartella con cfpurge.xml, services\, src\
$dest = "C:\percorso\plg_content_cfpurge-1.0.1.zip"
if (Test-Path $dest) { Remove-Item $dest -Force }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($dest, [System.IO.Compression.ZipArchiveMode]::Create)

# le CHIAVI hanno gli slash in avanti: è questo che rende lo zip valido per Joomla
$map = @{
  "cfpurge.xml"               = "$base\cfpurge.xml"
  "services/provider.php"     = "$base\services\provider.php"
  "src/Extension/Cfpurge.php" = "$base\src\Extension\Cfpurge.php"
}
foreach ($e in $map.Keys) {
  [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $map[$e], $e) | Out-Null
}
$zip.Dispose()
```

### Verifica che lo zip sia corretto

I percorsi devono apparire con `/` e i file alla radice:

```powershell
[System.IO.Compression.ZipFile]::OpenRead($dest).Entries | ForEach-Object { $_.FullName }
# Atteso:
#   cfpurge.xml
#   services/provider.php
#   src/Extension/Cfpurge.php
```

Se vedi dei `\` → lo zip è sbagliato, rifallo col Metodo A o B.

---

## 5. Come rilasciare una nuova versione (update server)

Gli update sono gestiti dal repo `WebG-it/joomla-extensions` (collection update server).
Per pubblicare una nuova versione di cfpurge:

1. Bump `<version>` in `cfpurge.xml` (es. `1.0.2`) e ricompila lo zip (sezione 4).
2. Metti il nuovo zip in `dist/plg_content_cfpurge-1.0.2.zip`.
3. Aggiorna `updates/cfpurge.xml`: cambia `<version>` e il `downloadurl`.
4. Aggiorna `version` (e se serve `detailsurl`) in `collection.xml`.

I siti vedranno l'aggiornamento in **Sistema → Aggiorna** entro il loro ciclo di controllo.

**Aggiungere un plugin NUOVO** al server di update: vedi il `README.md` del repo (3 mosse).

---

## 6. COME OTTENERE LE CREDENZIALI (per chiunque, click per click)

Il plugin ha bisogno di **due cose di Cloudflare**: lo **Zone ID** e un **API Token**.
Si inseriscono nei parametri del plugin sul sito (mai nel codice/zip).

### 6.1 — Cloudflare: Zone ID

1. Vai su **https://dash.cloudflare.com** e fai login.
2. Nella lista dei siti, **clicca sul dominio** interessato (es. `offerteesconti.it`).
3. Si apre la pagina **Overview** (Panoramica).
4. Guarda la **colonna a destra**, sezione **"API"**: lì c'è la voce **"Zone ID"** (una
   stringa di 32 caratteri esadecimali). Clicca **"Click to copy"** per copiarla.

Lo Zone ID identifica la zona/dominio. Non è un segreto critico ma va trattato con cura.

### 6.2 — Cloudflare: API Token (con permesso SOLO di purge)

Crea un token **dedicato e ristretto** (principio del minimo privilegio), non la Global Key.

1. Cloudflare dashboard → in **alto a destra** clicca sull'icona profilo → **"My Profile"**.
2. Menu a sinistra → **"API Tokens"**.
3. Bottone **"Create Token"**.
4. In fondo, **"Create Custom Token"** → **"Get started"**.
5. **Token name**: un nome riconoscibile, es. `joomla-cache-purge-offerteesconti`.
6. **Permissions** (Permessi): scegli nei tre menu a tendina:
   - primo menu: **Zone**
   - secondo menu: **Cache Purge**
   - terzo menu: **Purge**
   (Cioè: `Zone` → `Cache Purge` → `Purge`. È l'unico permesso che serve.)
7. **Zone Resources** (Risorse zona): **Include** → **Specific zone** → seleziona il dominio
   (es. `offerteesconti.it`).
8. Clicca **"Continue to summary"**, poi **"Create Token"**.
9. Cloudflare mostra il token **UNA SOLA VOLTA**: **copialo subito** e incollalo nel campo
   "Cloudflare API Token" del plugin sul sito. Se lo perdi, ne crei un altro.

### 6.3 — (Correlato) Token API nativa di Joomla

Non serve al plugin, ma serve a chi pubblica via **API nativa di Joomla** (e per testare
che il purge scatti su quel percorso). Come crearlo:

1. Backend Joomla → assicurati che il plugin **"API Authentication - Joomla Token"** sia
   abilitato (Sistema → Plugin) e che il **Web Services** sia attivo.
2. **Utenti → Gestisci** → apri (o crea) l'utente che farà le chiamate API (deve avere i
   permessi per agire sui contenuti).
3. Nella scheda utente, tab **"Joomla API Token"** → genera/copia il token.
4. Le chiamate usano l'header `Authorization: Bearer <token>` (oppure `X-Joomla-Token`).
   Nota pratica: dietro Cloudflare serve uno **User-Agent valido** nelle richieste, altrimenti
   alcune regole di sicurezza (errore 1010) bloccano la chiamata.

I **valori reali** dei token NON stanno qui (repo pubblico): vivono nei parametri del plugin
sul sito e, per il sistema WebG, nel KB privato.

---

## 7. Installare / configurare sul sito

1. Joomla → **Sistema → Installa → Installa da URL** (o carica lo zip).
   Reinstallare sopra una versione esistente è un **upgrade in-place** (`method="upgrade"`):
   **i parametri e lo stato abilitato vengono mantenuti**.
2. **Sistema → Plugin** → cerca `cfpurge` → apri **"Content - Cloudflare Cache Purge"**.
3. Incolla **Zone ID** e **API Token** → **Stato: Abilitato** → **Salva**.

### Verificare che il purge funzioni davvero

1. Scalda la cache: richiedi una pagina del sito finché `Cf-Cache-Status: HIT`.
2. Salva un articolo (da backend o via API nativa).
3. Richiedi di nuovo la pagina: deve comparire `Cf-Cache-Status: MISS` (cache svuotata),
   poi torna `HIT` con `Age: 0` (riscaricata fresca dall'origine).

Prova end-to-end del contenuto: cambia un campo che renda nella pagina (es. `metadesc`),
verifica che la pagina servita lo contenga dopo il purge, poi ripristina.
