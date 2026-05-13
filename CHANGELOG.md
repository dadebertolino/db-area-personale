# Changelog

Tutte le modifiche rilevanti del plugin sono documentate in questo file.

Il formato è basato su [Keep a Changelog](https://keepachangelog.com/it-IT/1.1.0/),
e il versioning segue [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — ruoli e capability custom
- `includes/roles/role-definitions.php` — definizioni dichiarative degli 8 ruoli `cbg_*` (docente, docente_pubblicatore, dsga, dirigente, ata, rsu, studente, genitore) e di 15 capability custom CBG conformi a §2.1 e §3.2 del documento di requisiti v1.2.
- `CBG_AP_Roles` — registrazione e sincronizzazione idempotente dei ruoli. Versione governata dalla costante `CBG_AP_ROLES_VERSION`: bump = riconciliazione automatica al prossimo bootstrap.
- `CBG_AP_Capabilities` — gestione delle **capability dinamiche per tipo news** via `map_meta_cap`. La meta-cap `cbg_ap_publish_news_type` (con term_id come argomento) viene risolta dinamicamente leggendo dalla tabella `cbg_ap_user_news_capabilities` (opzione 2 del design: data-driven, no migrazione cap quando si aggiunge un tipo news). API: `grant_news_type_to_user()`, `revoke_news_type_from_user()`, cache per-request.
- `functions-permissions.php` — API pubblica per check di permessi: `cbg_ap_user_can_access_area()`, `cbg_ap_user_can_publish_news_type()`, `cbg_ap_user_can_publish_bacheca_sindacale()`, `cbg_ap_user_can_approve_news()`, `cbg_ap_user_has_role()`, `cbg_ap_user_has_any_role()`, `cbg_ap_is_docente()`, `cbg_ap_is_studente()`, `cbg_ap_is_dirigente()`, `cbg_ap_is_dsga()`, `cbg_ap_require_capability()`.
- `CBG_AP_Activator::register_roles()` — al primo attivazione del plugin i ruoli vengono creati e all'`administrator` viene concessa l'intera suite di capability CBG.
- Cumulabilità ruoli (§2.1): un utente può cumulare ruoli CBG senza modifiche al codice; WordPress gestisce nativamente l'union delle capability.
- Soft sync su `init`: ad ogni bootstrap, se la versione delle definizioni è cambiata, le capability dei ruoli vengono riallineate (senza rimuovere quelle aggiunte manualmente dall'admin).
- `uninstall.php` esteso: rimozione delle capability CBG da TUTTI i ruoli del sistema (incluso `administrator`).

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
