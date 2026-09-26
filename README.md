# DB Area Personale

[![CI](https://github.com/dadebertolino/db-area-personale/actions/workflows/ci.yml/badge.svg)](https://github.com/dadebertolino/db-area-personale/actions/workflows/ci.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net)

Plugin WordPress che fornisce un portale interno unificato per il personale e
gli studenti dell'**IIS Cigna-Baruffi-Garelli** (Mondovì, CN).

Riferimento normativo interno: **Documento di requisiti v1.2** (13 maggio 2026).

## Cosa fa

Sostituisce, per i ruoli non amministrativi, la bacheca standard di
`/wp-admin` con un'interfaccia dedicata a `/area-personale/` di tipo
dashboard a widget. Integra:

- **SSO Google Workspace** con mapping esplicito gruppi → ruoli e step-up TOTP per azioni sensibili.
- **Anagrafica classi / studenti / docenti** con import CSV e sync Google Workspace.
- **News interne** con CPT dedicato, tipi, capability granulari, workflow approvazione DS, selettore destinatari strutturato (per classe, anno, indirizzo, sede, ruolo, utente).
- **Bacheca sindacale** con visibilità per-post (interna o pubblica) e nessuna moderazione preventiva (art. 25 L. 300/1970).
- **Comunicazioni del Dirigente** come CPT separato.
- **Sistema notifiche** in-app + email (immediata/digest mattino/digest sera) con orari personalizzabili e granularità 15 minuti.
- **PWA installabile** su mobile e desktop.
- **REST API estesa** per dashboard widget, audience, classi, import, sistema.
- **Cron architecture** basata su Action Scheduler con system cron Unix.

Coesiste con il tema istituzionale [**Design Scuole Italia**](https://github.com/italia/design-scuole-wordpress-theme)
sostituendo unicamente la modale di accesso e isolando i propri asset
(vedi `docs/theme-coexistence.md`).

## Stato

**1.1.0** — in sviluppo. Bootstrap pronto, sviluppo incrementale dei moduli in corso.

Roadmap (§9 documento requisiti):

- **v1.0** — Rilascio iniziale.
- **v1.2** — Push notifications, integrazione registro Argo, materiali didattici, statistiche DS, esportazione iCal.
- **v2.0** — Apertura ai genitori (ruolo `cbg_genitore`).

## Requisiti

- **PHP** 8.1+
- **WordPress** 6.0+
- **MySQL/MariaDB** con InnoDB
- **Cron Unix** di sistema (WP-Cron disabilitato)
- **SMTP transactional** raccomandato (Google Workspace SMTP relay o equivalente)
- **Plugin custom CBG**: Segreteria Digitale, Moduli Scuola, GESTIONESCUOLA
- **Tema istituzionale**: Design Scuole Italia 2.16+ (consigliato come child theme)

## Installazione (produzione)

1. Scarica l'ultimo archivio da [Releases](https://github.com/dadebertolino/db-area-personale/releases).
2. Carica la cartella `db-area-personale` in `/wp-content/plugins/`.
3. Attiva il plugin da WordPress → Plugin.
4. Disabilita WP-Cron in `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
5. Configura un cron Unix che chiami `/wp-json/cbg-ap/v1/cron/tick` ogni minuto con header `X-CBG-Cron-Key` impostato al valore dell'option `cbg_ap_cron_secret`.
6. Configura SSO Google Workspace e mapping gruppi → ruoli dal pannello **Impostazioni → Area Personale**.
7. Importa anagrafica classi/studenti/docenti via CSV.

Documentazione completa: `docs/deployment.md`.

## Installazione (sviluppo)

```bash
git clone https://github.com/dadebertolino/db-area-personale.git
cd db-area-personale
composer install
npm install
npm run build
```

Symlink della cartella in `wp-content/plugins/` dell'installazione locale, quindi attiva.

## Architettura

Il plugin segue il **DB WordPress Plugin Development Standard** dell'autore:

- Singleton + loader hook centralizzato.
- Prefisso `cbg_ap_` per funzioni, option, user meta, post meta; `CBG_AP_` per classi.
- Tabelle DB con prefisso `{$wpdb->prefix}cbg_ap_`.
- UI in italiano (text domain `cbg-ap`).
- Capability check su ogni endpoint REST, nonce su ogni mutazione.
- `$wpdb->prepare()` su ogni query.

## Struttura

```
db-area-personale/
├── db-area-personale.php       Bootstrap, costanti, check ambiente, check tema
├── uninstall.php               Cleanup completo
├── includes/                   Backend PHP (auth, cpt, audience, classi, widgets, cron, rest, frontend, admin, integrations)
├── templates/                  Template PHP frontend
├── assets/                     Sorgenti e build (Tailwind + Alpine.js)
├── languages/                  File .pot/.po/.mo
├── tests/                      PHPUnit
└── docs/                       Documentazione tecnica
```

## Documentazione

- `docs/architecture.md` — Architettura generale
- `docs/widget-development.md` — Come scrivere un widget per la dashboard
- `docs/integration-guide.md` — Integrazione con altri plugin custom
- `docs/api-reference.md` — REST API
- `docs/database-schema.md` — Schema DB
- `docs/audience-model.md` — Modello destinatari news
- `docs/import-format.md` — Formato CSV import classi/studenti/docenti
- `docs/deployment.md` — Deployment, cron, SMTP, recovery
- `docs/theme-coexistence.md` — Coesistenza col tema Design Scuole Italia

## Integrazione ecosistema DB privacy

Quando uno o più plugin dell'ecosistema privacy DB (**DB Privacy Hub**,
**DB SEO Manager** 1.2.x) sono installati, DB Area Personale li sfrutta
automaticamente — senza configurazione. Senza di essi il plugin resta
pienamente conforme tramite gli strumenti privacy nativi di WordPress.

### Consent gate

Non necessario: il plugin non carica script di terze parti sul frontend e non
raccoglie consensi (le basi giuridiche sono 6.1.b/c/e/f GDPR). L'SSO Google è
un redirect OIDC lato server.

### Dichiarazione trattamenti al registro privacy unificato

Con **DB Privacy Hub 1.0.0+** il plugin dichiara i propri trattamenti in
"Privacy → Registro trattamenti" (filter `dbph_processing_register`, più il
legacy `dbseo_processing_register`; l'Hub deduplica per `id`). Le voci sono
dinamiche e personalizzabili con il filter `cbg_ap_privacy_declarations`.
Gli ID usano il prefisso `cbgap_` (il prefisso del plugin `cbg_ap_` senza
separatore interno), riconducibile al plugin e senza collisioni nel catalogo DB.

| ID | Quando appare |
|---|---|
| `cbgap_access_log` | sempre — log accessi (IP, user agent, username), sicurezza |
| `cbgap_personal_area` | sempre — preferenze, layout dashboard, conferme di lettura |
| `cbgap_notifications` | sempre — notifiche in-app ed email |
| `cbgap_security_devices` | modulo device tracker/TOTP presente o dati già registrati |
| `cbgap_school_registry` | anagrafica classi/incarichi o abilitazioni news popolate |
| `cbgap_google_sso` | mapping SSO configurato o modulo Google SSO presente (trasferimento a Google) |

### Conservazione del log accessi

Evento WP-Cron giornaliero `cbg_ap_cleanup_access_log` (programmato in modo
idempotente su `init`, rimosso alla disattivazione e alla disinstallazione)
che cancella a blocchi le righe di `wp_cbg_ap_access_log` più vecchie di
**12 mesi** (option `cbg_ap_access_log_retention_months`, filter omonimo;
`0` = pulizia disattivata, dichiarato come tale nel registro). Con
`DISABLE_WP_CRON` il cron di sistema deve invocare anche `wp-cron.php`.

### DSAR routing via Privacy Hub

`CBG_AP_Privacy_DSAR` registra 3 exporter e 3 eraser (`cbg-ap-area-personale`,
`cbg-ap-notifications`, `cbg-ap-access-log`) su doppio canale:
`dbph_user_data_exporters` / `dbph_user_data_erasers` (chiave `label`) quando
l'Hub è presente, `wp_privacy_personal_data_exporters` / `_erasers` (chiavi
`*_friendly_name`) solo se `DBPH_DSAR` non esiste. Email → utente WordPress;
il log accessi è cercato anche per nome utente/email digitati nei tentativi
falliti. Paginazione a 100 righe con `done` corretto.

Cancellazione (art. 17):

- **cancellati**: preferenze, layout, conferme di lettura, dispositivi noti,
  user meta `cbg_ap_*`, notifiche (a blocchi di 1000);
- **conservati e segnalati** (`items_retained` + messaggio): log accessi fino
  alla scadenza della finestra di sicurezza (filter
  `cbg_ap_dsar_erase_access_log` → `true` per cancellarlo subito);
  configurazione 2FA finché l'account esiste; anagrafica classi, incarichi,
  abilitazioni news e registro import (dati istituzionali della segreteria).

Alla cancellazione dell'utente WordPress (`deleted_user`) vengono rimossi
preferenze, layout, notifiche, conferme, dispositivi, 2FA e abilitazioni news.

### Marker `CBG_AP_DSAR_AVAILABLE`

Costante definita nel file principale insieme al blocco "Privacy capabilities".
Il policy generator dell'Hub riconosce i marker solo per i prefissi noti, per
questo il plugin aggiunge anche `add_filter( 'dbph_dsar_available', '__return_true' )`
(oltre a registrare exporter sull'Hub): la Privacy Policy generata cita così la
procedura DSAR.

## Changelog

### 1.1.0 — Integrazione DB Privacy Hub, DSAR e conservazione log accessi

**Registro trattamenti (art. 30 GDPR):**
- Nuova `CBG_AP_Privacy_Declarations` su `dbph_processing_register` +
  `dbseo_processing_register`: fino a 6 voci dinamiche (`cbgap_*`) con tutti
  gli 8 campi (id, label, status, purpose, legal_basis, data_collected,
  retention, transfers); base giuridica sicurezza 6.1.f/art. 32 con nota per
  le scuole statali (6.1.c), servizio richiesto 6.1.b/6.1.e.

**DSAR (artt. 15 e 17 GDPR):**
- Nuova `CBG_AP_Privacy_DSAR`: 3 exporter + 3 eraser, doppio canale Hub/core,
  formati esatti del core (`data`/`done`; `items_removed`/`items_retained`
  booleani, `messages`, `done`), paginazione 100/1000.
- Export: preferenze, layout, user meta, stato 2FA (senza segreto/codici),
  dispositivi noti, abilitazioni news, classi/incarichi/coordinamento,
  import eseguiti, conferme di lettura, notifiche, log accessi.
- Pulizia dati per-utente su `deleted_user`.
- Marker `CBG_AP_DSAR_AVAILABLE` + filter `dbph_dsar_available`.

**Conservazione (art. 5.1.e GDPR):**
- Nuova `CBG_AP_Access_Log_Retention`: cron giornaliero
  `cbg_ap_cleanup_access_log` (DELETE a blocchi da 5000, max 100 blocchi),
  finestra da option `cbg_ap_access_log_retention_months` (12) + filter;
  action `cbg_ap_access_log_cleaned`. Evento rimosso in Deactivator e uninstall.

**Componenti condivisi DB e rilascio:**
- `includes/class-updater.php` (`DB_GitHub_Updater`, repo `db-area-personale`),
  caricato in modo guardato.
- `assets/css/db-admin-ui.css` registrato come handle condiviso `db-admin-ui`
  e accodato nelle schermate admin del plugin.
- `.github/workflows/release.yml`: verifica tag = header = `CBG_AP_VERSION`,
  ZIP con radice `db-area-personale/`, Release con asset.
- `.phpcs.xml.dist` (WPCS, prefissi `cbg_ap`/`CBG_AP`, text domain `cbg-ap`):
  la CI ora usa il ruleset del repo invece del default PEAR.

**Nessun breaking change.** Nessuna modifica allo schema DB.

## Contribuire

Vedi [CONTRIBUTING.md](CONTRIBUTING.md).

## Licenza

GPL v2 or later. © Davide Bertolino.

Il tema istituzionale Design Scuole Italia è distribuito sotto AGPL-3.0;
sono opere separate che non si linkano staticamente.
