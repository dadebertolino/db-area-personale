<?php
/**
 * Dichiarazione dei trattamenti al registro privacy unificato (DB Privacy Hub).
 *
 * Aggancia `dbph_processing_register` (Hub) e `dbseo_processing_register`
 * (legacy SEO Manager 1.2.x): l'Hub deduplica per `id`, quindi il doppio
 * aggancio è innocuo. Senza plugin privacy installati nessuno applica i
 * filter e la classe non ha effetti.
 *
 * Voci dinamiche: vengono dichiarati solo i trattamenti effettivamente
 * attivi sul sito (moduli presenti o dati già registrati).
 *
 * ID con prefisso `cbgap_`: è il prefisso del plugin (`cbg_ap_`) senza il
 * separatore interno, così le voci sono riconducibili a colpo d'occhio a
 * DB Area Personale e non collidono con `dbap_`, che nel catalogo DB non
 * è assegnato a questo plugin.
 *
 * @package CBG_AP
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Privacy_Declarations
 */
class CBG_AP_Privacy_Declarations {

	/**
	 * Registra i filter.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'dbph_processing_register', array( __CLASS__, 'declare_processing' ), 10, 1 );
		add_filter( 'dbseo_processing_register', array( __CLASS__, 'declare_processing' ), 10, 1 );
	}

	/**
	 * Aggiunge le voci del plugin al registro.
	 *
	 * @param array $register Voci già dichiarate.
	 * @return array
	 */
	public static function declare_processing( $register ) {
		if ( ! is_array( $register ) ) {
			$register = array();
		}

		$entries   = array();
		$entries[] = self::build_access_log_entry();
		$entries[] = self::build_personal_area_entry();
		$entries[] = self::build_notifications_entry();

		if ( self::module_exists( 'auth/class-cbg-ap-device-tracker.php' )
			|| self::module_exists( 'auth/class-cbg-ap-totp.php' )
			|| self::table_has_rows( 'cbg_ap_known_devices' )
			|| self::table_has_rows( 'cbg_ap_2fa_secrets' )
		) {
			$entries[] = self::build_security_entry();
		}

		if ( self::table_has_rows( 'cbg_ap_studenti_classi' )
			|| self::table_has_rows( 'cbg_ap_docenti_classi' )
			|| self::table_has_rows( 'cbg_ap_user_news_capabilities' )
		) {
			$entries[] = self::build_registry_entry();
		}

		$sso_mapping = get_option( 'cbg_ap_sso_group_mapping', array() );
		if ( ! empty( $sso_mapping ) || self::module_exists( 'auth/class-cbg-ap-google-sso.php' ) ) {
			$entries[] = self::build_google_sso_entry();
		}

		/**
		 * Filter: voci dichiarate da DB Area Personale al registro trattamenti.
		 *
		 * @since 1.1.0
		 * @param array $entries Voci (8 campi ciascuna).
		 */
		$entries = (array) apply_filters( 'cbg_ap_privacy_declarations', $entries );

		foreach ( $entries as $entry ) {
			$register[] = $entry;
		}

		return $register;
	}

	/**
	 * Log accessi: sicurezza.
	 *
	 * @return array
	 */
	private static function build_access_log_entry() {
		$months = class_exists( 'CBG_AP_Access_Log_Retention' )
			? CBG_AP_Access_Log_Retention::retention_months()
			: (int) get_option( 'cbg_ap_access_log_retention_months', 12 );

		if ( $months > 0 ) {
			$retention = sprintf(
				/* translators: %d: mesi di conservazione */
				__( '%d mesi dalla registrazione dell\'evento; cancellazione automatica giornaliera (evento cron cbg_ap_cleanup_access_log). Una richiesta di cancellazione (art. 17) non anticipa la scadenza, salvo diversa configurazione del titolare.', 'cbg-ap' ),
				$months
			);
		} else {
			$retention = __( 'Nessuna cancellazione automatica configurata (pulizia disattivata dal titolare): le righe restano fino a cancellazione manuale.', 'cbg-ap' );
		}

		return array(
			'id'             => 'cbgap_access_log',
			'label'          => __( 'Log accessi all\'area personale (DB Area Personale)', 'cbg-ap' ),
			'status'         => 'active',
			'purpose'        => __( 'Garantire la sicurezza dell\'area riservata: rilevare accessi non autorizzati, tentativi falliti, uso di nuovi dispositivi e verifiche di secondo fattore; ricostruire gli eventi in caso di incidente.', 'cbg-ap' ),
			'legal_basis'    => __( 'Legittimo interesse del titolare alla sicurezza del sistema (art. 6.1.f e art. 32 GDPR). Per le scuole statali, che come autorità pubbliche non possono invocare il legittimo interesse nei compiti istituzionali, la base è l\'adempimento dell\'obbligo di sicurezza del trattamento (art. 6.1.c e art. 32 GDPR).', 'cbg-ap' ),
			'data_collected' => __( 'ID utente WordPress, nome utente (o identificativo digitato nei tentativi falliti), indirizzo IP in chiaro, user agent del browser, tipo di evento (login, logout, 2FA, step-up, SSO rifiutato, nuovo dispositivo), metodo di autenticazione, dettagli tecnici dell\'evento, data e ora.', 'cbg-ap' ),
			'retention'      => $retention,
			'transfers'      => __( 'Nessuno. Database WordPress locale; consultabile solo dagli utenti con la capability cbg_ap_view_access_log.', 'cbg-ap' ),
		);
	}

	/**
	 * Preferenze e dashboard personale.
	 *
	 * @return array
	 */
	private static function build_personal_area_entry() {
		return array(
			'id'             => 'cbgap_personal_area',
			'label'          => __( 'Preferenze e dashboard dell\'area personale (DB Area Personale)', 'cbg-ap' ),
			'status'         => 'active',
			'purpose'        => __( 'Erogare l\'area personale richiesta dall\'utente: disposizione e impostazioni dei widget, preferenze di notifica e orari dei digest, conferme di lettura di news e comunicazioni, avvisi amministrativi già chiusi.', 'cbg-ap' ),
			'legal_basis'    => __( 'Esecuzione del servizio richiesto dall\'interessato nell\'ambito del rapporto in essere con l\'istituto (art. 6.1.b GDPR); per le scuole statali, esecuzione di compiti di interesse pubblico (art. 6.1.e GDPR).', 'cbg-ap' ),
			'data_collected' => __( 'ID utente, layout della dashboard (widget, posizione, dimensione, visibilità, impostazioni), preferenze in formato JSON, orari digest mattino/sera, conferme di lettura (tipo e ID contenuto, data/ora), user meta con prefisso cbg_ap_.', 'cbg-ap' ),
			'retention'      => __( 'Per la durata dell\'account: i dati vengono cancellati alla cancellazione dell\'utente WordPress o su richiesta di cancellazione (art. 17) tramite Strumenti → Cancella dati personali.', 'cbg-ap' ),
			'transfers'      => __( 'Nessuno. Database WordPress locale.', 'cbg-ap' ),
		);
	}

	/**
	 * Notifiche in-app ed email.
	 *
	 * @return array
	 */
	private static function build_notifications_entry() {
		return array(
			'id'             => 'cbgap_notifications',
			'label'          => __( 'Notifiche in-app ed email (DB Area Personale)', 'cbg-ap' ),
			'status'         => 'active',
			'purpose'        => __( 'Informare l\'utente di news, comunicazioni, approvazioni ed eventi di sicurezza che lo riguardano, in-app e via email (immediata o in digest secondo le sue preferenze).', 'cbg-ap' ),
			'legal_basis'    => __( 'Esecuzione del servizio richiesto dall\'interessato (art. 6.1.b GDPR); per le scuole statali, esecuzione di compiti di interesse pubblico (art. 6.1.e GDPR). Le notifiche di sicurezza rientrano negli obblighi dell\'art. 32 GDPR.', 'cbg-ap' ),
			'data_collected' => __( 'ID utente, tipo di notifica, contenuto (payload JSON), stato di lettura, modalità e stato dell\'invio email (tentativi, ultimo errore), data/ora. L\'indirizzo email è letto dall\'account WordPress al momento dell\'invio.', 'cbg-ap' ),
			'retention'      => __( 'Per la durata dell\'account: le notifiche vengono cancellate alla cancellazione dell\'utente WordPress o su richiesta di cancellazione (art. 17).', 'cbg-ap' ),
			'transfers'      => __( 'Nessuno per la conservazione (database locale). Le email transitano dal servizio SMTP configurato sul sito (es. Google Workspace SMTP relay), da dichiarare come responsabile del trattamento.', 'cbg-ap' ),
		);
	}

	/**
	 * Dispositivi noti e 2FA.
	 *
	 * @return array
	 */
	private static function build_security_entry() {
		return array(
			'id'             => 'cbgap_security_devices',
			'label'          => __( 'Dispositivi noti e autenticazione a due fattori (DB Area Personale)', 'cbg-ap' ),
			'status'         => 'active',
			'purpose'        => __( 'Proteggere l\'account: riconoscere i dispositivi già usati per segnalare accessi da dispositivi nuovi, verificare il secondo fattore TOTP e lo step-up per le azioni sensibili.', 'cbg-ap' ),
			'legal_basis'    => __( 'Legittimo interesse alla sicurezza (art. 6.1.f e art. 32 GDPR); per le scuole statali, adempimento dell\'obbligo di sicurezza (art. 6.1.c e art. 32 GDPR).', 'cbg-ap' ),
			'data_collected' => __( 'Impronta del dispositivo (hash), IP e user agent dell\'ultimo accesso, date di primo e ultimo utilizzo, flag di fiducia; per la 2FA: segreto TOTP cifrato, codici di recupero solo come hash, stato di attivazione e date di utilizzo.', 'cbg-ap' ),
			'retention'      => __( 'Per la durata dell\'account. I dispositivi noti sono cancellati anche su richiesta di cancellazione (art. 17); la configurazione 2FA è conservata finché l\'account esiste perché la sua rimozione ne ridurrebbe la sicurezza, e viene cancellata con l\'utente WordPress.', 'cbg-ap' ),
			'transfers'      => __( 'Nessuno. Database WordPress locale.', 'cbg-ap' ),
		);
	}

	/**
	 * Anagrafica scolastica e abilitazioni.
	 *
	 * @return array
	 */
	private static function build_registry_entry() {
		return array(
			'id'             => 'cbgap_school_registry',
			'label'          => __( 'Anagrafica classi, docenti e abilitazioni (DB Area Personale)', 'cbg-ap' ),
			'status'         => 'active',
			'purpose'        => __( 'Associare studenti e docenti alle classi per indirizzare news e comunicazioni ai destinatari corretti e gestire le abilitazioni alla pubblicazione per tipo di news.', 'cbg-ap' ),
			'legal_basis'    => __( 'Esecuzione di compiti di interesse pubblico connessi all\'istruzione (art. 6.1.e GDPR) e rapporto in essere con l\'istituto (art. 6.1.b GDPR).', 'cbg-ap' ),
			'data_collected' => __( 'ID utente, classe, anno scolastico, periodo di appartenenza, numero di registro; per i docenti materia, ore, ruolo e incarico di coordinatore; abilitazioni per tipo di news con autore e data della concessione; registro degli import (utente che ha importato, file, esito).', 'cbg-ap' ),
			'retention'      => __( 'Per la durata del rapporto con l\'istituto, secondo le regole di conservazione della segreteria. Non viene cancellata su richiesta art. 17 dall\'area personale (dato istituzionale gestito dalla segreteria): la richiesta va gestita dal titolare.', 'cbg-ap' ),
			'transfers'      => __( 'Nessuno per la conservazione. Eventuale sincronizzazione con Google Workspace e con il gestionale scolastico, se attivata dall\'amministratore.', 'cbg-ap' ),
		);
	}

	/**
	 * Accesso Google Workspace SSO.
	 *
	 * @return array
	 */
	private static function build_google_sso_entry() {
		return array(
			'id'             => 'cbgap_google_sso',
			'label'          => __( 'Accesso con Google Workspace SSO (DB Area Personale)', 'cbg-ap' ),
			'status'         => 'active',
			'purpose'        => __( 'Autenticare il personale e gli studenti con l\'account istituzionale Google Workspace e assegnare i ruoli in base ai gruppi di appartenenza.', 'cbg-ap' ),
			'legal_basis'    => __( 'Esecuzione del servizio richiesto dall\'interessato (art. 6.1.b GDPR); per le scuole statali, esecuzione di compiti di interesse pubblico (art. 6.1.e GDPR).', 'cbg-ap' ),
			'data_collected' => __( 'Email istituzionale, nome e cognome, dominio (claim hd), identificativo Google e gruppi Workspace ricevuti al login; nessuna password viene memorizzata dal plugin.', 'cbg-ap' ),
			'retention'      => __( 'I dati ricevuti aggiornano l\'account WordPress e seguono la sua durata; gli esiti del login sono registrati nel log accessi con la relativa conservazione.', 'cbg-ap' ),
			'transfers'      => __( 'Google LLC / Google Ireland Ltd (Google Workspace for Education), responsabile del trattamento dell\'istituto; possibile trasferimento negli Stati Uniti coperto dal EU-US Data Privacy Framework e dalle clausole contrattuali standard.', 'cbg-ap' ),
		);
	}

	/**
	 * Il file del modulo esiste nel plugin?
	 *
	 * @param string $relative_path Path relativo a includes/.
	 * @return bool
	 */
	private static function module_exists( $relative_path ) {
		return file_exists( CBG_AP_INCLUDES_DIR . $relative_path );
	}

	/**
	 * La tabella contiene almeno una riga? (cache per-request)
	 *
	 * @param string $table Nome tabella senza prefisso WP.
	 * @return bool
	 */
	private static function table_has_rows( $table ) {
		static $cache = array();

		if ( ! isset( $cache[ $table ] ) ) {
			global $wpdb;
			$full = $wpdb->prefix . $table;

			$suppress        = $wpdb->suppress_errors( true );
			$cache[ $table ] = (bool) $wpdb->get_var( "SELECT 1 FROM {$full} LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->suppress_errors( $suppress );
		}

		return $cache[ $table ];
	}
}

add_action( 'plugins_loaded', array( 'CBG_AP_Privacy_Declarations', 'init' ), 10 );
