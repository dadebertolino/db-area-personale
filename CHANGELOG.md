# Changelog

Tutte le modifiche rilevanti del plugin sono documentate in questo file.

Il formato è basato su [Keep a Changelog](https://keepachangelog.com/it-IT/1.1.0/),
e il versioning segue [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — repository GitHub
- `.gitignore`, `.gitattributes` (con `export-ignore` per release pulite), `.editorconfig` allineato ai WordPress Coding Standards.
- `CONTRIBUTING.md` con setup ambiente, convenzioni di naming, branch model, standard di sicurezza.
- `.github/` con template issue (bug/feature), template pull request, workflow CI (lint PHP 8.1/8.2/8.3 + WP Coding Standards via PHPCS).

### Added — coesistenza col tema Design Scuole Italia
- `cbg_ap_check_theme_compatibility()` — detection del tema istituzionale AgID (TextDomain `design_scuole_italia` o stylesheet `design-scuole-wordpress-theme`) sia come tema attivo che come parent del child theme. Avviso admin non bloccante con dismiss persistente (user meta `cbg_ap_dismissed_theme_notice`) se il tema non corrisponde.
- `docs/theme-coexistence.md` — specifica architetturale completa della coesistenza col tema: rendering a tre modalità, override modale di accesso, riuso CPT del tema, isolamento asset, vincolo Gutenberg, gestione licenze AGPL/GPL.
- Riferimento documento requisiti aggiornato a **v1.2** (13 maggio 2026): nuovo §1.3 "Coesistenza con il tema istituzionale", §5.2 esteso con pattern di routing duale, §4.1 con nota isolamento stack Bootstrap Italia, glossario con voci AGPL-3.0, Bootstrap Italia, Tema Design Scuole Italia.

### Added — bootstrap iniziale
- File principale `db-area-personale.php` con header WP, costanti, check requisiti, bootstrap difensivo.
- Classe singleton `CBG_AP_Plugin` con loader hook centralizzato.
- `CBG_AP_Loader` — registrazione centralizzata di action/filter/shortcode.
- `CBG_AP_I18n` — caricamento text domain `cbg-ap` su `init`.
- `CBG_AP_Activator` — creazione idempotente di **15 tabelle DB** custom (conformi a §4.2 v1.1), generazione `cbg_ap_cron_secret`, seed tipi news di default, option di default per SSO/sedi/anno scolastico.
- `CBG_AP_Deactivator` — disattivazione soft (no perdita dati): unschedule job Action Scheduler, pulizia transient, flush rewrites.
- `uninstall.php` — cleanup completo, opzionale tramite `cbg_ap_preserve_data_on_uninstall`.
- `composer.json` — autoload PSR-4, dipendenze `google/apiclient`, `spomky-labs/otphp`, `woocommerce/action-scheduler`.
- `readme.txt`, `README.md`, `LICENSE` di riferimento.
- Struttura cartelle completa secondo §7.1 del documento di requisiti.

## [1.0.0] — TBD

Rilascio iniziale conforme al documento di requisiti v1.2.
