# Cloudflare Cache Purge: guida per lo sviluppatore (pkg_cfpurge 2.1)

Documentazione **tecnica**, per uno sviluppatore o un'AI che deve modificare, provare, compilare o
rilasciare il pacchetto. Per installare e configurare: [USO.md](./USO.md) (italiano),
[USAGE.md](./USAGE.md) (inglese). Riassunto in inglese: [DEVELOPING.en.md](./DEVELOPING.en.md).

> Repo **PUBBLICO**: mai token, password o chiavi reali nei file. Le prove usano un'API Cloudflare
> finta e token finti (`devtok-...`). Un hook gitleaks controlla ogni commit.

## 1. Architettura

```
plugins/pkg_cfpurge/
  pkg_cfpurge.xml               pacchetto: server di aggiornamento, elenco dei figli
  script.php                    controlli PHP/Joomla, accende il nuovo, toglie il log alla disinstallazione
  language/{en-GB,it-IT}/       nome del pacchetto e messaggio di installazione
  extensions/
    plg_content_cfpurge/        element cfpurge, gruppo content (come la 1.1.4: upgrade sul posto)
    plg_system_cfpurge/         element cfpurge, gruppo system: solo gli eventi che il gruppo content non sente
    mod_cfpurge/                modulo amministratore, posizione cpanel
```

Namespace: `WebG\Plugin\Content\Cfpurge`, `WebG\Plugin\System\Cfpurge`,
`WebG\Module\Cfpurge\Administrator`. Gli ultimi due leggono le classi `Service\*` del plugin di
contenuto (autoload dei namespace di Joomla, valgono anche a plugin spento; `class_exists` protegge
il caso in cui manchi).

### Perché tre estensioni

- **Il plugin `content`** deve restare quello della 1.1.4 (stesso element e gruppo): è l'unico modo
  perché l'installazione sopra la 1.1.4 sia un upgrade sul posto con i parametri intatti.
- **Un plugin `system` è necessario**, non un vezzo: Joomla importa solo i gruppi `system`, `behaviour`
  e `user` a ogni richiesta; il gruppo `content` viene caricato dai modelli che lo usano. Due eventi
  non lo raggiungono mai (verificato nel sorgente di Joomla 5.4 e 6.2): `onExtensionAfterSave` e
  `onExtensionAfterDelete` del **salvataggio e cancellazione di un modulo** (ModuleModel importa il
  gruppo `extension`), e `onAfterPurge` di **Sistema > Svuota cache** (com_cache non importa nulla).
  Il plugin `system` li ascolta e li passa alla stessa logica.
- **Niente plugin `ajax`**: `com_ajax` con `group=content&plugin=cfpurge` importa il gruppo
  `content` e lancia `onAjaxCfpurge`, quindi il plugin di contenuto risponde da solo.
- **Il modulo** `mod_cfpurge` è il riquadro; installato come modulo amministratore, lo script del
  pacchetto lo pubblica in `cpanel` e lo assegna a tutte le pagine.

### Classi (plg_content_cfpurge/src)

| Classe | Ruolo |
|---|---|
| `Extension\Cfpurge` | plugin: eventi di contenuto, entry point `onAjaxCfpurge` (azioni `status`, `verify`, `purge_all`, `purge_urls`) |
| `Service\Config` | parametri; `load()` (solo se acceso), `loadFromDatabase()` (anche spento, per il modulo); impronta e stato di verifica |
| `Service\Events` | cosa svuotare per ogni evento, modalità tutto/mirata, coalescenza |
| `Service\Contexts` | contesto dell'evento → tipo (articolo, categoria, voce di menu, modulo, campo, tag) |
| `Service\Purger` | coda del request, invio alla chiusura (`register_shutdown_function`), purge immediato dei pulsanti |
| `Service\CloudflareClient`, `CfResult` | le sole chiamate usate; classificazione degli errori (token, permesso, zona, limite, rete, server) |
| `Service\Diagnostics` | la verifica della connessione (formato, token, zona, prova di purge) con messaggi tradotti |
| `Service\Targets` | indirizzi pubblici (Route del sito, categorie e genitori, home, extra); `normalise()` per gli indirizzi scritti dall'utente |
| `Service\PurgeLog`, `LastPurge` | log su file Joomla con rotazione; descrizione in una riga dell'ultimo purge |
| `Service\Access` | chi può usare i pulsanti |
| `Field\VerifyField` | pannello *Verifica connessione* nelle opzioni (campo nascosto `verify_state` + pulsante + JS) |

### Flussi

**Evento di Joomla** → `Events::afterSave/changeState/afterDelete` → controlli (tipo, interruttore,
modulo/menu del sito) → `Purger::queueEverything` oppure `queueUrls` → **una sola chiamata alla
chiusura della richiesta** (`register_shutdown_function`, anche dopo un redirect con `exit`). Un
errore nel calcolo degli indirizzi ripiega su tutta la cache; nessuna eccezione esce verso il
salvataggio (`guard()` nel plugin, `try/catch` nel plugin di sistema).

**Pulsanti** → `fetch` POST a `index.php?option=com_ajax&group=content&plugin=cfpurge&format=json`
con `<token di sessione>=1` e `action` → controlli: client amministratore, POST, token di form,
`Access::canPurge` → `purge_*` richiedono anche la **verifica valida**: `Config::isVerified()` =
risultato salvato ok **e** impronta (sha256 troncato di Zone ID + token) uguale a quella dei valori
salvati. Cambiare token o zona annulla la verifica.

**Stato condiviso senza tabelle**: la verifica sta nei parametri del plugin (`verify_state`, JSON
in un campo nascosto del modulo di opzioni: il JS lo aggiorna dopo ogni verifica, così il
salvataggio del form non lo sovrascrive con un valore vecchio); l'ultimo purge sta nel log.

### Sicurezza

- Un plugin non ha la schermata dei permessi: l'accesso è `core.admin` (Super Utenti) più i gruppi
  scelti nelle opzioni (`allowed_groups`, ereditarietà inclusa), predefinito Amministratore e Super
  Utenti. Il controllo è **lato server** a ogni chiamata, non solo nell'interfaccia.
- Gli indirizzi scritti dall'utente: solo percorsi o indirizzi dello stesso host, niente spazi,
  caratteri di controllo, utenti nell'URL; massimo 100 per volta e 2000 caratteri ciascuno.
- Il token non esce mai dal server verso altro che `api.cloudflare.com` (indirizzo fisso nel
  codice, nessuna opzione per cambiarlo), non è mai nel log, nelle risposte JSON o nel messaggio.
  Nel log non c'è nemmeno lo Zone ID.
- Il JS inserisce testo solo con `textContent`; l'HTML del modulo esce con `htmlspecialchars`.
- `com_ajax` di Joomla 5.4/6.2 lascia passare agli ospiti dell'amministrazione solo i metodi con
  l'attributo `AllowUnauthorizedAdministratorAccess`: il nostro non lo ha.

## L'API (2.1)

`com_cfpurge` e' un componente senza schermate: `admin/` ha `access.xml` (azioni `cfpurge.status`, `cfpurge.purge`),
`config.xml` (il modulo dei permessi) e il service provider; `api/src/Controller/PurgeController.php` risponde alle tre
rotte registrate da `plg_webservices_cfpurge` (`onBeforeApiRoute`). Il controller controlla l'identita' stabilita
dall'autenticazione API di Joomla (`$user->authorise('cfpurge.purge', 'com_cfpurge')`, i Super utenti sempre; l'asset parte
senza regole) e poi chiama `Service\ManualPurge`, lo stesso codice del riquadro nella home (connessione verificata,
controllo degli indirizzi, 100 indirizzi, `Purger::purgeNow`). La risposta e' una risorsa JSON:API costruita con
`ResultSerializer`. `ManualPurge::publicLast()` e' l'unica vista del log che l'API da': il token e lo Zone ID non sono
mai parte di un risultato. Per entrare nelle API serve anche il permesso di core *Accesso ai Web Services*
(`core.login.api`) per il gruppo. Joomla 5.0 o successivo: il pacchetto non si installa su Joomla 4 (provato su 4.4.14:
installazione, riquadro e API funzionano, ma la modalita' mirata costruisce indirizzi sbagliati).

Collaudo: scenario `api` di `scripts/e2e.py` (in joomla-dev-tools).

## 2. Ambiente di prova (Docker)

`dev/cfpurge/`: Joomla 6.2.0 (o 5.4.10 con `JOOMLA_ZIP`) + PHP 8.4 + MariaDB 11.4, porta 8790
(8791 per la seconda istanza), e un'**API Cloudflare finta**: il container web risolve
`api.cloudflare.com` su se stesso, Apache serve `mock/index.php` in HTTPS con una CA usa e getta
aggiunta ai certificati di sistema e a quello di Joomla. Il comportamento lo sceglie il token
(`devtok-full`, `purgeonly`, `noperm`, `inactive`, `acct`, `probeonly`, `err500`, `slow`); le zone sono
`0123456789abcdef0123456789abcdef` (cfdemo.localhost) e `fedcba98...` (un altro dominio). Il sito
risponde su `www.cfdemo.localhost` (Chrome risolve `*.localhost` da solo).

Da Git Bash su Windows prefissare i comandi `docker compose exec` con `MSYS_NO_PATHCONV=1`. Tutto
dalla cartella `dev/cfpurge`.

```bash
mkdir -p .cache && curl -L -o .cache/joomla-6.2.0.zip \
  https://github.com/joomla/joomla-cms/releases/download/6.2.0/Joomla_6.2.0-Stable-Full_Package.zip
sha256sum .cache/joomla-6.2.0.zip     # atteso: eaafc68b5990d7b9dd9156656d4c3c3547743d1d7a85682a92e2f9121b58f018
cp .env.example .env                  # scegli le password locali
docker compose up -d
until docker compose logs web | grep -q AH00163; do sleep 3; done
docker compose exec web bash /dev-scripts/setup.sh
docker compose exec web bash /dev-scripts/mock_cf_setup.sh
python ../../build/build_plugin.py ../../plugins/pkg_cfpurge
python scripts/e2e.py                 # tutto il collaudo; oppure: python scripts/e2e.py install upgrade
```

Seconda istanza con Joomla 5.4: `.cache/compat-j5.env` (non versionato) con `DEV_PORT=8791`,
`JOOMLA_ZIP=./.cache/joomla-5.4.10.zip` e password proprie; poi gli stessi comandi con
`docker compose -p cfpurge-j5 --env-file .cache/compat-j5.env ...` e
`python scripts/e2e.py --project cfpurge-j5 --env .cache/compat-j5.env`.

Dopo una modifica al codice: `bash scripts/redeploy.sh` (ricompila e installa sopra).

### Il collaudo (`scripts/e2e.py`)

Pilota Joomla come un utente (CLI per l'installazione, amministrazione via HTTP con login e token,
API Web Services) e legge dal log del finto Cloudflare cosa ha inviato il plugin. Scenari:

| Scenario | Cosa prova |
|---|---|
| `install` | installazione pulita: 4 estensioni accese, modulo in `cpanel` pubblicato su tutte le pagine, nessuna tabella nuova, lingue, media, server di aggiornamento |
| `upgrade` | 1.1.4 poi pacchetto: stesso `extension_id`, parametri e interruttori intatti, modulo e plugin di sistema accesi, il salvataggio di un articolo purga ancora tutto |
| `visibility` | riquadro per il super utente, assente per un Manager, 403 per Manager/ospite/sito pubblico/token sbagliato/GET, gruppo scelto nelle opzioni |
| `verify` | 17 casi: zona trovata da sola, token solo purge, account token, token non valido, senza permesso, inattivo, zona inesistente o di un altro dominio, 500, formati, timeout |
| `state` | semaforo: non configurato, non verificato, verificato, verifica invalidata dal cambio di token, non valido, plugin spento |
| `purge` | pulsanti: tutto, indirizzi, rifiuto di indirizzi altrui, blocchi da 30, oltre 100, fallimento controllato di Cloudflare, il salvataggio non si blocca, il log non ha il token |
| `events` | articolo (crea, modifica, stato, cestino, cancella), interruttori, tre articoli in una richiesta = una chiamata, categorie, voci di menu, tag, campi, moduli del sito (evento extension) e dell'amministrazione (nessun purge) |
| `targeted` | indirizzi esatti: articolo, categoria e genitori, home, extra, rinomina e spostamento (vecchio e nuovo), blocchi, oltre 90 indirizzi = tutta la cache |
| `cache` | Sistema > Svuota cache: *Elimina tutto*, *Elimina*, *Elimina scaduti* (mai), interruttore spento o mai salvato |
| `update` | server di aggiornamento locale: 1.1.4 vede un solo aggiornamento, 2.0.0 installato come pacchetto, poi una 2.0.1 offerta una volta sola, senza giri |
| `uninstall` | disinstallazione: nessuna estensione, file, modulo, log, riga di menu, server di aggiornamento |

### JED Checker

Il checker (https://github.com/joomla-extensions/jedchecker) su Joomla 6 vuole il plugin
*Behaviour - Backward Compatibility 6* acceso (usa classi Filesystem tolte in J6): accenderlo solo
per il controllo. Due rami: `master` (carica lo zip: `scripts/jed_master.py`, che fa la stessa
sequenza dello schermo) e `develop` (`scripts/jedchecker_cli.php`). Si controlla **lo zip del
pacchetto**: entrambi aprono anche gli zip annidati. Gli zip dei figli controllati da soli danno un
errore sul server di aggiornamento: voluto, nel pacchetto il server sta solo nel manifest del
pacchetto. Le note attese sono quelle sull'elemento `<licence>` (serve alla regola PH3 di `develop`).

## 3. Compilare

```bash
python build/build_plugin.py plugins/pkg_cfpurge     # build/out/pkg_cfpurge-2.1.0.zip
```

Per un pacchetto lo script costruisce prima gli zip dei figli (cartelle `extensions/<nome>`, uno per
ogni `<file>` del manifest), li mette in `packages/` e poi zippa il pacchetto; stampa l'sha256 di
ognuno. Percorsi con `/`, ordine e date fissi, file di testo con LF anche da un checkout Windows:
stesso sorgente = stesso sha256 su ogni PC. Mai `Compress-Archive`.

## 4. Rilasciare

Da `build/release-notes/cfpurge-2.0.0.md` e dal CHANGELOG:

1. Versione in **tutti** i manifest (`pkg_cfpurge.xml` e i tre dei figli) e in
   `media/joomla.asset.json` dei due con media; voce in `plugins/pkg_cfpurge/CHANGELOG.md` e in
   `changelogs/cfpurge.xml`.
2. Compila, passa `scripts/e2e.py` su Joomla 6.2 e 5.4, JED Checker `master` e `develop` (zero
   errori e zero avvisi).
3. Copia lo zip in `dist/pkg_cfpurge-X.Y.Z.zip` (i vecchi `plg_content_cfpurge-*.zip` restano:
   servono al collaudo di upgrade).
4. **`updates/cfpurge.xml`**: resta **un solo `<update>` di `type=plugin`, element `cfpurge`, folder
   `content`** (com'e' oggi), con la nuova `<version>`, il `downloadurl` che punta allo **zip del
   pacchetto** `dist/pkg_cfpurge-X.Y.Z.zip` e lo sha256 stampato dalla build (piu'
   `<targetplatform name="joomla" version="(5|6)\.[0-9]+" />`, `<php_minimum>8.1</php_minimum>`).
5. **`collection.xml`**: la riga `cfpurge` (type plugin, folder content) prende la nuova versione.
6. Tag `cfpurge-X.Y.Z` e release GitHub con lo zip allegato e le note.

### Perche' basta la voce del plugin (aggiornamento dalla 1.1.4 e dopo)

Joomla confronta le voci del collection con le estensioni installate per `element`, `type` e
`folder` e, quando l'utente applica l'aggiornamento, rilegge `detailsurl` e scarica lo zip indicato
li'. Se quello zip e' il pacchetto: il pacchetto installa i figli, `plg_content_cfpurge` si aggiorna sul
posto (stesso `extension_id`, parametri intatti) e nasce `pkg_cfpurge`; il plugin ha la stessa
versione del pacchetto, quindi dalla release dopo la voce `plugin` torna ad offrire l'aggiornamento
(sempre lo zip del pacchetto). Una voce `type=package` in piu' non serve (funziona lo stesso, provata
in `scripts/e2e.py update`, ma per i siti che hanno ancora la 1.1.4 non basterebbe da sola: il
pacchetto non e' installato e Joomla non lo offre). Provato: 1.1.4 vede un solo aggiornamento a 2.0.0,
installato come pacchetto; poi una 2.0.1 costruita al volo e' offerta una volta sola e non si
ripresenta. Il server di aggiornamento registrato dal pacchetto ha lo stesso indirizzo di quello del
vecchio plugin: Joomla ne tiene uno solo, collegato a entrambi.

## 5. Decisioni e limiti noti

- **Purge alla chiusura della richiesta**, sincrono: la richiesta dell'amministratore aspetta
  Cloudflare (timeout 8 s) come nella 1.1.4. Su PHP-FPM si potrebbe chiudere prima la risposta con
  `fastcgi_finish_request()`: non fatto perché il collaudo Docker usa Apache con mod_php e non
  potrebbe provarlo.
- La modalità mirata non conosce le pagine che mostrano l'articolo altrove né le home delle altre
  lingue; per tutto ciò che non è un articolo si svuota tutta la cache.
- Le scritture dirette nel database non fanno scattare gli eventi (vale per ogni plugin di Joomla).
- La verifica fa un purge di prova su un indirizzo inesistente: è l'unico modo di provare il
  permesso Purge (Cloudflare non espone i permessi del token).
- Il token di account (`/accounts/{id}/tokens/verify`) non è riconosciuto dal controllo utente: la
  verifica lo segnala come nota e decide la prova di purge.
