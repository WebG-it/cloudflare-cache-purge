# Uso del plugin — guida per l'operatore (umano)

Per chi gestisce il sito Joomla. **Non serve toccare codice né riga di comando**: solo
caricare lo zip e qualche click. La parte tecnica (modifica e compilazione) è in
[`DEVELOPING.md`](./DEVELOPING.md) ed è destinata a uno sviluppatore o a un'AI.

Cosa fa il plugin, in una riga: quando pubblichi o modifichi un articolo, **svuota
automaticamente la cache Cloudflare**, così gli utenti vedono subito il contenuto nuovo.

---

## 1. Installare il plugin

Due modi, scegli quello che preferisci (entrambi dal backend Joomla):

- **Trascinamento (drag & drop):** *Sistema → Installa → Estensioni* → tab **"Carica file
  pacchetto"** → trascina lo zip del plugin.
- **Da URL:** *Sistema → Installa → Installa da URL* → incolla l'URL dello zip → **Installa**.
  URL ultima versione:
  ```
  https://raw.githubusercontent.com/WebG-it/joomla-extensions/main/dist/plg_content_cfpurge-1.0.1.zip
  ```

> Reinstallare sopra una versione già presente è un **aggiornamento in-place**: i parametri
> (Zone ID e token) e lo stato abilitato **restano**, non devi reinserirli.

---

## 2. Ottenere le credenziali Cloudflare (click per click)

Il plugin ha bisogno di due cose di Cloudflare: lo **Zone ID** e un **API Token**.

### Zone ID
1. Vai su **https://dash.cloudflare.com** e fai login.
2. Clicca sul **dominio** (es. `offerteesconti.it`).
3. Nella pagina **Overview**, colonna a **destra**, sezione **"API"**: copia lo **"Zone ID"**
   (32 caratteri) con **"Click to copy"**.

### API Token (con permesso solo "Cache Purge")
1. In alto a destra → icona profilo → **"My Profile"**.
2. Menu a sinistra → **"API Tokens"** → bottone **"Create Token"**.
3. In fondo → **"Create Custom Token"** → **"Get started"**.
4. **Nome**: es. `joomla-cache-purge-offerteesconti`.
5. **Permissions**: nei menu a tendina scegli **Zone** → **Cache Purge** → **Purge**.
6. **Zone Resources**: **Include** → **Specific zone** → seleziona il dominio.
7. **"Continue to summary"** → **"Create Token"**.
8. Cloudflare mostra il token **una sola volta**: **copialo subito**.

---

## 3. Configurare il plugin

1. *Sistema → Plugin* → cerca **`cfpurge`** → apri **"Content - Cloudflare Cache Purge"**.
2. Incolla **Cloudflare Zone ID** e **Cloudflare API Token**.
3. **Stato → Abilitato** → **Salva**.

Fatto: da ora ogni salvataggio/pubblicazione di un articolo svuota la cache.

---

## 4. Aggiornare il plugin

Quando esce una versione nuova, compare in **Sistema → Aggiorna** e si applica con **un
click** (funziona se hai installato la **1.0.1 o successiva**, che include il server di
aggiornamento). In alternativa reinstalli lo zip nuovo (sezione 1): è sempre un upgrade
in-place, i parametri restano.

---

## 5. Disinstallare (rimozione pulita e completa)

1. *Sistema → Gestisci → Estensioni*.
2. Cerca **"Cloudflare Cache Purge"** → mettidla la spunta.
3. Clicca **"Disinstalla"**.

Joomla rimuove **tutto**: i file del plugin **e** i suoi parametri (quindi anche il token).
**Non resta niente.** Il plugin non crea tabelle nel database né file fuori dalla sua
cartella, perciò non servono file o script speciali di disinstallazione: la rimozione
standard è già completa.

---

## 6. Verificare che funzioni (facoltativo, semplice)

1. Apri una pagina del sito due volte di fila: alla seconda dovrebbe essere già in cache.
2. Modifica e salva un articolo qualsiasi dal backend.
3. Riapri la pagina: la prima volta dopo il salvataggio la cache risulta svuotata e
   ricaricata fresca. Significa che il purge ha funzionato.

(La verifica tecnica con gli header `Cf-Cache-Status` è descritta in `DEVELOPING.md`.)
