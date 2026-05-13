<?php
/**
 * Definizioni dichiarative di ruoli e capability del plugin.
 *
 * Questo file è LA fonte di verità: aggiungere o rimuovere capability qui
 * e bumpare `CBG_AP_ROLES_VERSION` farà sì che al prossimo bootstrap la
 * sincronizzazione applichi le differenze ai ruoli esistenti.
 *
 * Ritorna un array con due chiavi:
 *   - 'capabilities': elenco capability custom (per documentazione/uninstall)
 *   - 'roles':        mapping ruolo → {label, capabilities[]}
 *
 * Riferimenti documento requisiti v1.2:
 *   §2.1 Tassonomia ruoli — gli 8 ruoli `cbg_*` + administrator
 *   §2.3 Step-up authentication su azioni sensibili
 *   §3.2 Pubblicazione contenuti (capability granulari per tipo via tabella)
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Versione delle definizioni di ruoli e capability.
 *
 * BUMPARE questo numero quando si modificano i ruoli o le capability:
 * il sincronizzatore lo confronta con l'option `cbg_ap_roles_version` e
 * applica le differenze. Non bumpare = nessuna riconciliazione = utenti
 * esistenti con ruoli "vecchi" non riceveranno le capability nuove.
 */
if ( ! defined( 'CBG_AP_ROLES_VERSION' ) ) {
	define( 'CBG_AP_ROLES_VERSION', '1.0.0' );
}

return array(

	/* ------------------------------------------------------------------
	 * Elenco capability custom introdotte dal plugin.
	 *
	 * Tenute esplicite (non solo "tutte quelle dei ruoli") per due ragioni:
	 *   1. Documentazione: chi legge questo file sa subito cosa controlla cosa.
	 *   2. Uninstall: per rimuoverle in modo pulito da ruoli non-CBG (es.
	 *      administrator), serve la lista canonica.
	 *
	 * Le capability dinamiche per-tipo-news NON compaiono qui: sono gestite
	 * tramite map_meta_cap + tabella `cbg_ap_user_news_capabilities`.
	 * ------------------------------------------------------------------ */
	'capabilities' => array(

		// --- Accesso ----------------------------------------------------
		'cbg_ap_access_area_personale' => __( 'Accesso alla /area-personale/', 'cbg-ap' ),

		// --- Pubblicazione (capability base; il tipo specifico è dinamico)
		'cbg_ap_publish_news'                 => __( 'Pubblicare news interne (capability base, tipi specifici via tabella)', 'cbg-ap' ),
		'cbg_ap_publish_bacheca_sindacale'    => __( 'Pubblicare in bacheca sindacale', 'cbg-ap' ),
		'cbg_ap_publish_comunicazione_ds'     => __( 'Pubblicare comunicazioni del Dirigente', 'cbg-ap' ),

		// --- Approvazioni (richiedono step-up TOTP — vedi §2.3) ----------
		'cbg_ap_approve_news'      => __( 'Approvare/rifiutare news in stato revisione', 'cbg-ap' ),
		'cbg_ap_approve_assenze'   => __( 'Approvare/rifiutare richieste di assenza', 'cbg-ap' ),
		'cbg_ap_approve_moduli'    => __( 'Approvare/rifiutare moduli amministrativi', 'cbg-ap' ),

		// --- Gestione admin --------------------------------------------
		'cbg_ap_manage_classi'         => __( 'Gestire anagrafica classi/studenti/docenti', 'cbg-ap' ),
		'cbg_ap_manage_news_types'     => __( 'Gestire i tipi di news (tassonomia, flag approvazione DS)', 'cbg-ap' ),
		'cbg_ap_manage_sso_mapping'    => __( 'Gestire la mappa SSO gruppi Workspace → ruoli', 'cbg-ap' ),
		'cbg_ap_manage_capabilities'   => __( 'Modificare capability degli utenti (es. assegnare pubblicatori)', 'cbg-ap' ),
		'cbg_ap_view_access_log'       => __( 'Visualizzare il log accessi', 'cbg-ap' ),
		'cbg_ap_import_data'           => __( 'Importare dati via CSV (classi, studenti, docenti)', 'cbg-ap' ),
		'cbg_ap_manage_system'         => __( 'Accesso al pannello Stato sistema (cron, code, healthcheck)', 'cbg-ap' ),

		// --- Sicurezza --------------------------------------------------
		'cbg_ap_force_2fa_for_role'    => __( 'Forzare 2FA per ruolo', 'cbg-ap' ),
	),

	/* ------------------------------------------------------------------
	 * Definizione dei ruoli custom.
	 *
	 * `inherits_from`: nome di un ruolo da cui ereditare TUTTE le sue cap
	 * al momento della registrazione. Utile per partire dalla baseline
	 * di `subscriber` (lettura minima) ed estenderla con cap CBG.
	 *
	 * Le `capabilities` definite qui sono in formato {cap => bool}; il
	 * registratore le applica con `add_cap`.
	 * ------------------------------------------------------------------ */
	'roles' => array(

		/* -------------- DOCENTE (base) -------------------------------- */
		'cbg_docente' => array(
			'label'         => __( 'Docente', 'cbg-ap' ),
			'inherits_from' => 'subscriber',
			'capabilities'  => array(
				'read'                            => true,
				'cbg_ap_access_area_personale'    => true,
			),
		),

		/* -------------- DOCENTE PUBBLICATORE --------------------------
		 * Sottoinsieme dei docenti abilitato a pubblicare news per uno
		 * o più tipi. La capability `cbg_ap_publish_news` è il "flag"
		 * generico; il TIPO specifico è autorizzato in tabella
		 * `cbg_ap_user_news_capabilities` (vedi §3.2).
		 *
		 * In pratica un utente ha sia il ruolo `cbg_docente` sia
		 * `cbg_docente_pubblicatore` (i ruoli si cumulano).
		 * ------------------------------------------------------------ */
		'cbg_docente_pubblicatore' => array(
			'label'         => __( 'Docente pubblicatore', 'cbg-ap' ),
			'inherits_from' => 'subscriber',
			'capabilities'  => array(
				'read'                            => true,
				'cbg_ap_access_area_personale'    => true,
				'cbg_ap_publish_news'             => true,
			),
		),

		/* -------------- DSGA ----------------------------------------- */
		'cbg_dsga' => array(
			'label'         => __( 'DSGA', 'cbg-ap' ),
			'inherits_from' => 'subscriber',
			'capabilities'  => array(
				'read'                            => true,
				'cbg_ap_access_area_personale'    => true,
				'cbg_ap_approve_assenze'          => true,
				'cbg_ap_approve_moduli'           => true,
				'cbg_ap_manage_news_types'        => false,
			),
		),

		/* -------------- DIRIGENTE SCOLASTICO ------------------------- */
		'cbg_dirigente' => array(
			'label'         => __( 'Dirigente scolastico', 'cbg-ap' ),
			'inherits_from' => 'subscriber',
			'capabilities'  => array(
				'read'                            => true,
				'cbg_ap_access_area_personale'    => true,
				'cbg_ap_publish_comunicazione_ds' => true,
				'cbg_ap_approve_news'             => true,
				'cbg_ap_approve_assenze'          => true,
				'cbg_ap_manage_news_types'        => true,
			),
		),

		/* -------------- ATA ----------------------------------------- */
		'cbg_ata' => array(
			'label'         => __( 'Personale ATA', 'cbg-ap' ),
			'inherits_from' => 'subscriber',
			'capabilities'  => array(
				'read'                            => true,
				'cbg_ap_access_area_personale'    => true,
			),
		),

		/* -------------- RSU ----------------------------------------- *
		 * Bacheca sindacale: nessuna moderazione preventiva (vincolo
		 * di legge ex art. 25 L. 300/1970, vedi §3.2).
		 * ------------------------------------------------------------ */
		'cbg_rsu' => array(
			'label'         => __( 'RSU / Rappresentante sindacale', 'cbg-ap' ),
			'inherits_from' => 'subscriber',
			'capabilities'  => array(
				'read'                              => true,
				'cbg_ap_access_area_personale'      => true,
				'cbg_ap_publish_bacheca_sindacale'  => true,
			),
		),

		/* -------------- STUDENTE ------------------------------------ */
		'cbg_studente' => array(
			'label'         => __( 'Studente', 'cbg-ap' ),
			'inherits_from' => 'subscriber',
			'capabilities'  => array(
				'read'                            => true,
				'cbg_ap_access_area_personale'    => true,
			),
		),

		/* -------------- GENITORE (predisposto, v2) ------------------ *
		 * Il ruolo viene creato in v1 per non frammentare le migrazioni,
		 * ma `cbg_ap_access_area_personale` NON è assegnata: l'accesso
		 * viene attivato in v2 dopo aver definito la procedura SPID
		 * (vedi §9.3 della roadmap).
		 * ------------------------------------------------------------ */
		'cbg_genitore' => array(
			'label'         => __( 'Genitore', 'cbg-ap' ),
			'inherits_from' => 'subscriber',
			'capabilities'  => array(
				'read' => true,
			),
		),
	),

	/* ------------------------------------------------------------------
	 * Capability aggiuntive da concedere ad `administrator`.
	 *
	 * L'admin ottiene TUTTE le capability custom CBG: deve poter fare
	 * qualsiasi cosa nel pannello del plugin senza alchimie.
	 * Le elenchiamo qui in modo esplicito per il sincronizzatore.
	 * ------------------------------------------------------------------ */
	'administrator_capabilities' => array(
		'cbg_ap_access_area_personale',
		'cbg_ap_publish_news',
		'cbg_ap_publish_bacheca_sindacale',
		'cbg_ap_publish_comunicazione_ds',
		'cbg_ap_approve_news',
		'cbg_ap_approve_assenze',
		'cbg_ap_approve_moduli',
		'cbg_ap_manage_classi',
		'cbg_ap_manage_news_types',
		'cbg_ap_manage_sso_mapping',
		'cbg_ap_manage_capabilities',
		'cbg_ap_view_access_log',
		'cbg_ap_import_data',
		'cbg_ap_manage_system',
		'cbg_ap_force_2fa_for_role',
	),
);
