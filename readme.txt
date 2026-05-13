=== DB Area Personale ===
Contributors: dadebertolino
Tags: school, portal, sso, google-workspace, dashboard
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Portale interno unificato per il personale e gli studenti dell'IIS Cigna-Baruffi-Garelli.

== Description ==

Plugin WordPress che sostituisce la bacheca standard di /wp-admin per i ruoli non amministrativi con un'area personale dedicata su /area-personale/. Integra SSO Google Workspace, anagrafica classi, news interne con destinatari strutturati, bacheca sindacale, comunicazioni del Dirigente, sistema di approvazioni e notifiche.

Sviluppato su misura per l'IIS Cigna-Baruffi-Garelli (Mondovì, CN). Non distribuito pubblicamente.

= Funzionalità principali =

* SSO Google Workspace con mapping esplicito gruppi → ruoli
* Step-up authentication TOTP per azioni sensibili di DSGA, Dirigente e Administrator
* Device tracking con notifica nuovi accessi
* Dashboard personalizzabile con widget drag-and-drop
* Custom Post Type per news interne, bacheca sindacale, comunicazioni DS
* Editor frontend con selettore destinatari strutturato (per classe, anno, indirizzo, sede, ruolo, utente)
* Anagrafica classi/studenti/docenti con import CSV e sync Google Workspace
* Sistema notifiche in-app + email (immediata/digest) con orari personalizzabili
* Progressive Web App installabile
* REST API estesa
* Architettura cron basata su Action Scheduler

== Installation ==

1. Caricare la cartella `db-area-personale` in `/wp-content/plugins/`.
2. Attivare il plugin dal menu Plugin.
3. Disabilitare WP-Cron in `wp-config.php`: `define( 'DISABLE_WP_CRON', true );`
4. Configurare un cron Unix di sistema che chiami l'endpoint `/wp-json/cbg-ap/v1/cron/tick` ogni minuto, con header `X-CBG-Cron-Key` impostato al valore di `cbg_ap_cron_secret`.
5. Configurare l'SSO Google Workspace dal pannello Impostazioni → Area Personale.
6. Definire il mapping gruppi Workspace → ruoli CBG.
7. Importare l'anagrafica classi/studenti/docenti via CSV.

Documentazione completa in `docs/deployment.md`.

== Changelog ==

= 1.0.0 =
* Rilascio iniziale conforme al documento di requisiti v1.1.

== Upgrade Notice ==

= 1.0.0 =
Versione iniziale.
