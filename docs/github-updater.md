# Sistema di aggiornamento automatico via GitHub Releases

**Documento interno Mavida — uso tecnico**
Descrive l'architettura implementata in `woo-multi-stock` e fornisce le istruzioni per replicarla su altri plugin.

---

## Indice

1. [Obiettivo](#1-obiettivo)
2. [Panoramica del flusso](#2-panoramica-del-flusso)
3. [Architettura dei componenti](#3-architettura-dei-componenti)
4. [Hook WordPress utilizzati](#4-hook-wordpress-utilizzati)
5. [API GitHub](#5-api-github)
6. [Caching e rate limiting](#6-caching-e-rate-limiting)
7. [Problema della cartella estratta (fix_source_dir)](#7-problema-della-cartella-estratta-fix_source_dir)
8. [Interfaccia utente nella pagina admin](#8-interfaccia-utente-nella-pagina-admin)
9. [Sicurezza](#9-sicurezza)
10. [Come replicare su un nuovo plugin](#10-come-replicare-su-un-nuovo-plugin)
11. [Workflow di rilascio su GitHub](#11-workflow-di-rilascio-su-github)
12. [Decisioni di progetto e alternative scartate](#12-decisioni-di-progetto-e-alternative-scartate)

---

## 1. Obiettivo

WordPress non supporta nativamente l'aggiornamento di plugin ospitati su repository privati o su GitHub. Il plugin `woo-multi-stock` implementa un sistema **self-hosted** che:

- Interroga le **GitHub Releases** del repository per verificare se esiste una versione più recente.
- Inietta i dati di aggiornamento nel flusso nativo di WordPress, così l'update compare nella lista plugin esattamente come un plugin da wordpress.org.
- Permette un **check manuale** dalla pagina admin del plugin, senza aspettare il cron di WordPress.
- Gestisce automaticamente la **rinomina della cartella** estratta dall'archivio zip di GitHub (che altrimenti avrebbe un nome casuale).

Il sistema non richiede librerie esterne, token di autenticazione (per repo pubblici) o servizi di terze parti.

---

## 2. Panoramica del flusso

```
┌──────────────────────────────────────────────────────────────┐
│                    PLUGIN CARICATO                           │
│          (hook: plugins_loaded, priorità 11)                 │
│                                                              │
│          Updater::register_hooks()                           │
│          registra 5 hook (vedi §4)                          │
└────────────────────┬─────────────────────────────────────────┘
                     │
         ┌───────────┼──────────────┐
         │           │              │
   ┌─────▼──────┐  ┌─▼──────────┐  ┌▼─────────────────┐
   │ Cron WP    │  │ Check      │  │ Esecuzione       │
   │ (ogni 12h) │  │ manuale    │  │ aggiornamento    │
   │            │  │ (AJAX)     │  │ (update.php)     │
   └─────┬──────┘  └─┬──────────┘  └┬─────────────────┘
         │           │              │
         └─────┬─────┘              │
               │                   │
       ┌───────▼──────────┐         │
       │ get_remote_      │         │
       │ release()        │         │
       │                  │         │
       │ 1. legge cache   │         │
       │    transient     │         │
       │ 2. se miss →     │         │
       │    API GitHub    │         │
       │ 3. caching 6h    │         │
       └───────┬──────────┘         │
               │                   │
    ┌──────────▼──────────┐         │
    │ version_compare()   │         │
    │                     │         │
    │ remota > locale?    │         │
    │  sì → inject in     │         │
    │       update_plugins│         │
    └──────────┬──────────┘         │
               │                   │
    ┌──────────▼──────────┐         │
    │ Notice admin WP     │         │
    │ + pulsante          │         │
    │ "Aggiorna ora"      │         │
    └──────────┬──────────┘         │
               │                   │
               └───────────────────┘
                          │
               ┌──────────▼──────────┐
               │ WordPress Upgrader  │
               │                     │
               │ 1. download zip     │
               │    da GitHub        │
               │ 2. estrai in /tmp   │
               │ 3. fix_source_dir() │
               │    → rinomina       │
               │      cartella       │
               │ 4. copia file       │
               │ 5. clear_cache()    │
               └─────────────────────┘
```

---

## 3. Architettura dei componenti

### 3.1 File coinvolti

| File | Responsabilità |
|------|---------------|
| `includes/Class-Updater.php` | Tutta la logica di aggiornamento (contatto API, iniezione update, fix cartella) |
| `includes/Class-Admin.php` | Rendering tab "Aggiornamenti" nella pagina admin, enqueue script |
| `assets/admin-script.js` | Logica JavaScript per il check manuale e la visualizzazione risultati |
| `woo-multi-stock.php` (file principale) | Inizializzazione: `new Updater()->register_hooks()` |

### 3.2 Classe `Updater` — responsabilità per metodo

```
Updater
│
├── register_hooks()           → registra tutti gli hook
├── get_remote_release()       → interroga GitHub / legge cache
├── inject_update()            → inietta dati nel transient update_plugins
├── plugin_info()              → popola il popup "Visualizza dettagli"
├── fix_source_dir()           → rinomina cartella estratta dal zip
├── clear_cache_after_update() → svuota transient dopo update riuscito
├── handle_check_update()      → AJAX handler per check manuale
├── force_check()              → svuota cache + ricontatta API
├── get_basename()             → helper: basename del plugin
├── get_slug()                 → helper: slug (cartella) del plugin
└── get_upgrade_url()          → helper: URL update.php con nonce
```

### 3.3 Configurazione fissa nella classe

Quattro costanti definiscono l'identità del repository:

```
GITHUB_OWNER  → username/organization GitHub
GITHUB_REPO   → nome del repository
CACHE_KEY     → chiave del transient WordPress (univoca per plugin)
CACHE_TTL     → durata cache: 6 ore
```

---

## 4. Hook WordPress utilizzati

### 4.1 `pre_set_site_transient_update_plugins` (filter)

**Quando scatta**: ogni volta che WordPress scrive il transient `update_plugins`, sia via cron (ogni 12 ore) sia dopo un check manuale dell'admin.

**Cosa fa**: chiama `inject_update()` che legge la release GitHub, confronta le versioni, e se disponibile una versione più recente la inietta nell'oggetto transient. WordPress legge questo oggetto per mostrare il badge "aggiornamento disponibile" nella lista plugin.

**Perché questo hook**: è il punto di aggancio ufficiale — usato anche da WooCommerce e da altri plugin commerciali per auto-update. Non richiede accesso diretto al transient.

---

### 4.2 `plugins_api` (filter, priorità 20)

**Quando scatta**: quando l'admin clicca su "Visualizza dettagli" accanto a un plugin nella lista degli aggiornamenti.

**Cosa fa**: intercetta la richiesta solo se lo slug richiesto corrisponde al nostro plugin, e risponde con un oggetto contenente nome, versione, autore, homepage, link download e changelog formattato.

**Senza questo hook**: WordPress cercherebbe il plugin su wordpress.org e troverebbe "plugin non trovato".

---

### 4.3 `upgrader_source_selection` (filter, priorità 10)

**Quando scatta**: dopo che WordPress ha estratto l'archivio zip in una cartella temporanea, prima di spostarlo nella directory dei plugin.

**Cosa fa**: rinomina la cartella estratta al nome corretto. Vedi §7 per il dettaglio del problema.

---

### 4.4 `upgrader_process_complete` (action, priorità 10)

**Quando scatta**: dopo che WordPress ha completato un aggiornamento.

**Cosa fa**: cancella il transient `wms_github_release` (o equivalente) così al prossimo check WordPress ricontatterà GitHub e troverà correttamente che la versione è aggiornata.

---

### 4.5 `wp_ajax_wms_check_update` (action)

**Quando scatta**: chiamata AJAX dal pulsante "Controlla aggiornamenti" nella pagina admin.

**Cosa fa**: esegue un check forzato (svuota cache, ricontatta API), risponde con JSON contenente versione locale, versione remota, changelog, URL della release e URL per eseguire l'update.

---

## 5. API GitHub

### Endpoint

```
GET https://api.github.com/repos/{OWNER}/{REPO}/releases/latest
```

### Header inviati

```
Accept:     application/vnd.github+json
User-Agent: {NomePlugin}-Updater
```

Il `User-Agent` è **obbligatorio**: GitHub rifiuta le richieste senza di esso.

### Risposta JSON — campi estratti

| Campo JSON | Uso |
|-----------|-----|
| `tag_name` | Versione della release (es. `v1.7.1` → normalizzato a `1.7.1`) |
| `zipball_url` | URL per il download dell'archivio del codice |
| `body` | Testo del changelog in Markdown |
| `html_url` | URL della pagina release su GitHub |
| `published_at` | Data di pubblicazione |

### Autenticazione

Per repository **pubblici** non serve alcun token. Per repository **privati** occorre aggiungere l'header:

```
Authorization: Bearer {GITHUB_TOKEN}
```

Il token va memorizzato come opzione WordPress (`get_option()`) e mai hardcodato nel codice.

### Normalizzazione della versione

GitHub permette tag con o senza prefisso `v`. Il campo `tag_name` può valere `v1.7.1` oppure `1.7.1`. La classe rimuove sempre il prefisso con `ltrim( $tag, 'vV' )` prima del confronto con `version_compare()`.

---

## 6. Caching e rate limiting

### Perché il caching è critico

GitHub limita a **60 richieste/ora** per IP senza autenticazione. WordPress su un sito con molti hook e cron potrebbe chiamare `pre_set_site_transient_update_plugins` anche decine di volte al giorno. Senza cache, si supererebbe facilmente il limite.

### Strategia

```
Request  →  Transient presente?  →  sì  →  restituisci dati cached
                   │
                   no
                   │
                   ↓
            Chiama API GitHub
                   │
         Risposta valida?  →  no  →  restituisce false (no update shown)
                   │
                   sì
                   │
                   ↓
            set_transient( CACHE_KEY, $dati, 6 * HOUR_IN_SECONDS )
                   │
                   ↓
            restituisce dati
```

### Invalidazione della cache

La cache viene invalidata in due situazioni:

1. **Scadenza naturale** (6 ore): nessuna azione richiesta.
2. **Aggiornamento eseguito** (`clear_cache_after_update`): garantisce che dopo l'update WordPress veda subito la versione aggiornata come "corrente".
3. **Check manuale** (`force_check`): l'admin può forzare un aggiornamento immediato della cache tramite il pulsante nella tab admin. In questo caso viene cancellato anche il transient nativo `update_plugins` per far comparire subito l'eventuale badge nella lista plugin.

---

## 7. Problema della cartella estratta (`fix_source_dir`)

Questo è il punto più delicato dell'intero sistema.

### Il problema

Quando WordPress scarica un zip da GitHub tramite l'API `/zipball/`, l'archivio contiene una cartella con nome variabile:

```
mavidasnc-woo-multi-stock-a3f8d21/    ← nome generato da GitHub (owner-repo-sha)
```

WordPress estrae questa cartella e la sposta dentro `wp-content/plugins/`. Il risultato sarebbe:

```
wp-content/plugins/mavidasnc-woo-multi-stock-a3f8d21/    ← SBAGLIATO
```

invece di:

```
wp-content/plugins/wp-multi-magazzino/    ← CORRETTO (cartella esistente)
```

Il plugin verrebbe installato come nuovo plugin in una cartella diversa, lasciando la vecchia versione attiva.

### La soluzione: `fix_source_dir()`

Il hook `upgrader_source_selection` intercetta la cartella estratta **prima** che venga copiata e la rinomina al percorso corretto.

### Due scenari gestiti

**Scenario A — Aggiornamento automatico** (WordPress ha già il basename nel contesto):

```
$hook_extra['plugin'] = 'wp-multi-magazzino/woo-multi-stock.php'
               ↓
cartella desiderata = dirname( basename ) = 'wp-multi-magazzino'
               ↓
sposta /tmp/mavidasnc-woo-multi-stock-sha/ → /tmp/wp-multi-magazzino/
```

**Scenario B — Installazione manuale via upload zip**:

Quando l'admin carica manualmente uno zip tramite la pagina "Aggiungi plugin", `$hook_extra['plugin']` è assente. In questo caso la classe identifica l'archivio controllando se il file principale del plugin (`woo-multi-stock.php`) è presente nella cartella estratta.

```
file principale trovato in cartella estratta?
               sì
               ↓
cartella desiderata = slug stabile 'woo-multi-stock'
               ↓
sposta /tmp/woo-multi-stock-1.7.1/ → /tmp/woo-multi-stock/
```

Lo **slug stabile** è il nome da usare per la cartella destinazione nell'installazione manuale. Non deve mai includere il numero di versione, altrimenti ogni upload creerebbe una cartella diversa.

### Pseudocodice di `fix_source_dir`

```
se ($hook_extra['plugin'] corrisponde al nostro plugin):
    desired_slug = dirname(nostro_basename)  // es. "wp-multi-magazzino"

altrimenti:
    se (file principale del plugin NON esiste in $source):
        return $source  // non è il nostro zip, non toccare nulla

    desired_slug = slug_stabile  // es. "woo-multi-stock"

desired_path = dirname(rtrim($source, '/')) + '/' + desired_slug + '/'

se ($source === $desired_path):
    return $source  // già nel posto giusto

WP_Filesystem::move($source, $desired_path)

return $desired_path
```

---

## 8. Interfaccia utente nella pagina admin

Il plugin aggiunge una tab "Aggiornamenti" nella propria pagina admin. Non è obbligatoria per il funzionamento del sistema (l'aggiornamento automatico via cron funziona indipendentemente), ma migliora l'esperienza dell'admin.

### Struttura HTML della tab

```
Tab "Aggiornamenti"
│
├── Tabella informativa
│   ├── Versione installata: [valore da costante WMS_VERSION]
│   ├── Ultima versione: [popolata via AJAX]
│   └── Repository: [link GitHub]
│
├── Pulsante "Controlla aggiornamenti"
│   └── (disabilita se stessa durante la richiesta)
│
└── Area risultati (popolata via AJAX)
    │
    ├── se aggiornato:
    │   └── notice verde "Il plugin è aggiornato"
    │
    └── se disponibile aggiornamento:
        ├── notice giallo "Nuova versione disponibile: X.Y.Z"
        ├── <details> con changelog in Markdown renderizzato
        └── pulsante "Aggiorna ora" → link a update.php con nonce
```

### Flusso AJAX

```
Click "Controlla aggiornamenti"
       ↓
POST wp-admin/admin-ajax.php
  action = wms_check_update
  nonce  = {nonce}
       ↓
PHP: handle_check_update()
  verifica nonce
  verifica current_user_can('manage_options')
  chiama force_check() → API GitHub
  risponde JSON
       ↓
JavaScript riceve risposta
  aggiorna "#wms-latest-version"
  se update_available: mostra notice + changelog + pulsante
  altrimenti: mostra notice "aggiornato"
```

### Dati localizzati (`wp_localize_script`)

Lo script JS riceve tramite `wp_localize_script` un oggetto con:

- `ajaxUrl`: URL dell'endpoint AJAX
- `nonce`: nonce per la verifica sicurezza
- `i18n`: oggetto con tutte le stringhe tradotte (per non avere stringhe hardcodate in JS)

---

## 9. Sicurezza

### Nell'AJAX handler

1. **Verifica nonce** tramite `check_ajax_referer()` — protegge da CSRF.
2. **Verifica capability** `manage_options` — solo gli amministratori possono eseguire check.
3. **Validazione risposta HTTP** — accettata solo con codice 200.
4. **Validazione JSON** — controllato che i campi obbligatori esistano prima dell'uso.

### Nell'output

- Il changelog GitHub (Markdown non trusted) viene sanitizzato con `wp_kses_post()` prima di essere restituito nel JSON.
- In JavaScript, tutte le stringhe inserite nel DOM passano per una funzione `escHtml()` locale che usa `DOMParser` o `createElement/textContent` per evitare XSS.

### Sul processo di aggiornamento

Il download del zip avviene tramite il sistema nativo `WP_Upgrader` di WordPress, che:
- Verifica l'integrità del file scaricato.
- Utilizza `WP_Filesystem` per le operazioni sui file.
- Richiede nonce WordPress (generato da `wp_nonce_url()`) per autorizzare l'operazione.

---

## 10. Come replicare su un nuovo plugin

### Passo 1 — Crea il repository GitHub e pubblica la prima release

1. Crea il repository su `github.com/mavidasnc/{nome-repo}`.
2. Imposta la versione iniziale nell'header del plugin (`Version: 1.0.0`).
3. Crea un tag Git `v1.0.0` e pubblica una GitHub Release con quel tag.

### Passo 2 — Crea `Class-Updater.php`

Copia il file da `woo-multi-stock/includes/Class-Updater.php` e adatta le seguenti costanti:

```
GITHUB_OWNER  → il tuo username GitHub (es. 'mavidasnc')
GITHUB_REPO   → il nome del repository (es. 'nuovo-plugin')
CACHE_KEY     → una chiave univoca (es. 'np_github_release')
```

Aggiorna anche:

- Il namespace: `WooMultiStock\` → `NuovoPlugin\` (o equivalente)
- Il riferimento alla costante versione: `WMS_VERSION` → la tua costante (es. `NP_VERSION`)
- Il riferimento alla costante file principale: `WMS_PLUGIN_FILE` → la tua costante
- Lo slug stabile usato in `fix_source_dir()` Scenario B: deve corrispondere alla cartella del plugin

### Passo 3 — Definisci le costanti nel file principale

Nel file principale del plugin definisci:

```
{PREFIX}_VERSION      → valore string della versione corrente
{PREFIX}_PLUGIN_FILE  → __FILE__ (usato per ricavare path e basename)
```

### Passo 4 — Inizializza l'Updater **fuori da `is_admin()`**

```php
// IMPORTANTE: non wrappare in is_admin().
// Il cron di WordPress non è in contesto admin.
( new \NuovoPlugin\Updater() )->register_hooks();
```

### Passo 5 — (Facoltativo) Aggiungi la tab UI nella pagina admin

Se il plugin ha una pagina admin, aggiungi una tab "Aggiornamenti" con:
- Tabella versione installata / ultima disponibile
- Pulsante "Controlla aggiornamenti" con AJAX
- Area risultati (notice + changelog + pulsante update)

Copia la sezione rilevante da `Class-Admin.php` e da `admin-script.js` adattando prefissi e nomi degli hook AJAX.

### Checklist riepilogativa

```
[ ] Repository GitHub creato
[ ] Prima release pubblicata con tag vX.Y.Z
[ ] Class-Updater.php copiato e costanti adattate
[ ] Namespace aggiornato
[ ] Costanti VERSION e PLUGIN_FILE definite nel file principale
[ ] register_hooks() chiamato fuori da is_admin()
[ ] Testato: check automatico via cron (simula con wp-cli: wp cron run --due-now)
[ ] Testato: check manuale via tab admin (se implementata)
[ ] Testato: aggiornamento completo — cartella rinominata correttamente
[ ] Testato: installazione manuale via upload zip
```

---

## 11. Workflow di rilascio su GitHub

Per ogni nuova versione del plugin:

### 1. Aggiorna la versione nel codice

- Header del plugin: `Version: X.Y.Z`
- Costante: `define( '{PREFIX}_VERSION', 'X.Y.Z' )`
- `CHANGELOG.md`: sposta le voci da `[Unreleased]` a `[X.Y.Z] - YYYY-MM-DD`

### 2. Commit e tag

```bash
git add .
git commit -m "chore: rilascio versione X.Y.Z"
git tag vX.Y.Z
git push origin main --tags
```

### 3. Pubblica la release su GitHub

1. Vai su `github.com/{OWNER}/{REPO}/releases/new`
2. Seleziona il tag `vX.Y.Z`
3. Titolo: `v X.Y.Z`
4. Descrizione: incolla il contenuto del CHANGELOG per questa versione
5. Pubblica

**Attenzione**: GitHub genera automaticamente lo zip del codice alla tag selezionata. Non è necessario caricare un file zip manualmente — l'API `/releases/latest` restituisce nel campo `zipball_url` lo zip del codice alla tag.

### 4. Verifica

Attendi qualche minuto e vai nella tab "Aggiornamenti" del plugin su un sito di test. Clicca "Controlla aggiornamenti" — deve comparire la nuova versione con changelog.

---

## 12. Decisioni di progetto e alternative scartate

### Perché non usare una libreria esterna (es. `plugin-update-checker`)?

La libreria `yahnis-elsts/plugin-update-checker` è la scelta più diffusa per questo caso d'uso. È stata scartata perché:

- Introduce una dipendenza esterna da gestire (aggiornamento, compatibilità, composer/include manuale).
- Per i nostri plugin la logica da implementare è limitata e ben definita: una classe autonoma è più semplice da mantenere e capire.
- Permette controllo completo su ogni aspetto (caching, sicurezza, UI).

### Perché non usare `assets_url` invece di `zipball_url`?

GitHub Releases supporta anche allegati (`assets`) caricati manualmente. Usando `assets[0].browser_download_url` si potrebbe distribuire uno zip costruito manualmente (senza cartelle build o file di sviluppo).

Per ora si usa `zipball_url` perché:
- È automatico (zero azioni manuali al rilascio).
- I plugin Mavida non hanno un processo di build separato — il codice del repository **è** il codice del plugin.

Se in futuro il plugin avesse file compilati (Webpack, etc.) da escludere, si dovrebbe passare a uno zip allegato manualmente alla release.

### Perché il TTL della cache è 6 ore e non 12 ore?

Il cron di WordPress per gli aggiornamenti gira ogni 12 ore. Con un TTL di 6 ore si garantisce che ogni ciclo cron effettui almeno una chiamata API fresca, senza superare il limite di 60 richieste/ora di GitHub (un sito con molti plugin che usano questo sistema potrebbe avere decine di check in parallelo, ma per singolo plugin rimane abbondantemente sotto la soglia).

### Perché `register_hooks()` è separato dal costruttore?

Per evitare side effect nell'istanziazione della classe. Chiamare `new Updater()` non deve mai registrare hook — li registra solo quando si chiama esplicitamente `register_hooks()`. Questo rende il codice più testabile e prevedibile.
