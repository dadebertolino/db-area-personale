# Contribuire a DB Area Personale

Questo plugin è sviluppato su misura per l'IIS Cigna-Baruffi-Garelli (Mondovì, CN).
Il repository è pubblico per trasparenza, riusabilità e tracciabilità. Pull request
e issue sono benvenute.

## Requisiti ambiente di sviluppo

- PHP 8.1+ con estensioni `mbstring`, `openssl`, `json`, `mysqli`
- WordPress 6.0+ (consigliato 6.5+)
- MySQL 5.7+ o MariaDB 10.3+ con InnoDB
- Composer 2.x
- Node.js 18+ con npm

## Setup locale

```bash
git clone https://github.com/dadebertolino/db-area-personale.git
cd db-area-personale
composer install
npm install
npm run build
```

Symlink della cartella dentro `wp-content/plugins/` dell'installazione locale di
WordPress, quindi attivare il plugin dal pannello.

## Standard di codice

- **PHP**: WordPress Coding Standards (verifica con `composer lint`).
- **Convenzioni di naming**: prefisso `CBG_AP_` per classi, `cbg_ap_` per
  funzioni, option, post meta, user meta, capability, nonce, hook Action
  Scheduler. Tabelle: `{$wpdb->prefix}cbg_ap_*`. Text domain: `cbg-ap`.
- **Sicurezza**: capability check su ogni endpoint REST, nonce su ogni POST/PUT/DELETE,
  `$wpdb->prepare()` per ogni query, escape output con `esc_html()` / `esc_attr()` /
  `esc_url()`, sanitize input con `sanitize_text_field()` / `wp_kses_post()`.
- **UI**: italiano per testi utente, etichette, errori.
- **Commit**: messaggi in italiano o inglese, presente indicativo
  ("aggiunge X", "fix Y"). Riferimento al numero issue dove pertinente.

## Branch model

- `main` — sempre stabile, allineato all'ultimo tag release.
- `develop` — branch di integrazione per le feature in corso.
- `feature/<nome>` — singole feature, partono da `develop` e ci rientrano via PR.
- `fix/<nome>` — bugfix.
- `release/v<x.y.z>` — preparazione release (bump versione, changelog, regressione).

## Pull request

1. Apri PR contro `develop`.
2. Includi descrizione del cambiamento, motivazione, link a issue rilevanti.
3. Aggiorna `CHANGELOG.md` nella sezione `[Unreleased]`.
4. Aggiorna documentazione in `docs/` se cambi un'API pubblica, lo schema DB,
   il modello audience o il pattern di coesistenza col tema.
5. Test PHPUnit verdi (`composer test`).

## Segnalazione bug

Usa le [issue GitHub](https://github.com/dadebertolino/db-area-personale/issues).
Includi:
- Versione plugin, versione WP, versione PHP.
- Tema attivo (e versione, soprattutto se è il tema Design Scuole Italia).
- Plugin attivi rilevanti (Segreteria Digitale, Moduli Scuola, GESTIONESCUOLA).
- Passi per riprodurre.
- Comportamento atteso vs osservato.
- Log rilevanti (Action Scheduler, `cbg_ap_access_log`, `wp-content/debug.log`).

## Licenza

Contribuendo accetti che il tuo codice sia rilasciato sotto **GPL-2.0-or-later**
coerentemente con il resto del plugin.
