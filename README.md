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

**1.0.0** — in sviluppo. Bootstrap pronto, sviluppo incrementale dei moduli in corso.

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

## Contribuire

Vedi [CONTRIBUTING.md](CONTRIBUTING.md).

## Licenza

GPL v2 or later. © Davide Bertolino.

Il tema istituzionale Design Scuole Italia è distribuito sotto AGPL-3.0;
sono opere separate che non si linkano staticamente.
