<?php
/**
 * Activator: operazioni eseguite all'attivazione del plugin.
 *
 * Idempotente: ogni operazione verifica lo stato corrente e applica
 * solo le differenze. Sicuro da rieseguire (es. update plugin).
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Activator
 */
class CBG_AP_Activator {

	/**
	 * Entry point invocato da register_activation_hook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::check_requirements_or_die();
		self::create_tables();
		self::create_default_options();
		self::generate_cron_secret_if_missing();
		self::seed_news_types();
		self::register_roles();
		self::update_db_version();

		// I job Action Scheduler vengono inizializzati dai rispettivi moduli
		// al primo bootstrap post-attivazione, per evitare dipendenze hard
		// durante l'attivazione (Action Scheduler potrebbe non essere ancora
		// caricato se installato come libreria Composer).

		// Flush rewrite rules per registrare le route /area-personale/*
		// (le rule effettive vengono aggiunte dal router al prossimo init).
		flush_rewrite_rules();

		// Set di flag che il bootstrap successivo userà per completare
		// le operazioni che richiedono il plugin caricato.
		update_option( 'cbg_ap_needs_post_activation_setup', 1, false );
	}

	/**
	 * Carica le definizioni di ruoli e capability e le sincronizza.
	 *
	 * Viene chiamata sia in fase di attivazione sia (in modo idempotente)
	 * al primo bootstrap dopo bump di CBG_AP_ROLES_VERSION.
	 *
	 * @return void
	 */
	protected static function register_roles() {
		require_once CBG_AP_INCLUDES_DIR . 'roles/role-definitions.php';
		require_once CBG_AP_INCLUDES_DIR . 'roles/class-cbg-ap-roles.php';

		// All'attivazione siamo fuori dal normale ciclo di `plugins_loaded`,
		// quindi l'hook auto-istanziatore non gira. Istanziamo direttamente.
		$instance = new CBG_AP_Roles();
		$instance->sync_roles();

		update_option( 'cbg_ap_roles_version', CBG_AP_ROLES_VERSION, false );
	}

	/**
	 * Hard fail con messaggio chiaro se l'ambiente non è adeguato.
	 *
	 * Usato solo all'attivazione (non al bootstrap normale, dove preferiamo
	 * la disattivazione soft con admin notice).
	 *
	 * @return void
	 */
	protected static function check_requirements_or_die() {
		global $wp_version;

		if ( version_compare( PHP_VERSION, CBG_AP_MIN_PHP, '<' ) ) {
			deactivate_plugins( CBG_AP_PLUGIN_BASE );
			wp_die(
				sprintf(
					/* translators: 1: required PHP, 2: current PHP */
					esc_html__( 'DB Area Personale richiede PHP %1$s o superiore. Versione corrente: %2$s.', 'cbg-ap' ),
					esc_html( CBG_AP_MIN_PHP ),
					esc_html( PHP_VERSION )
				),
				esc_html__( 'Requisiti non soddisfatti', 'cbg-ap' ),
				array( 'back_link' => true )
			);
		}

		if ( version_compare( $wp_version, CBG_AP_MIN_WP, '<' ) ) {
			deactivate_plugins( CBG_AP_PLUGIN_BASE );
			wp_die(
				sprintf(
					/* translators: 1: required WP, 2: current WP */
					esc_html__( 'DB Area Personale richiede WordPress %1$s o superiore. Versione corrente: %2$s.', 'cbg-ap' ),
					esc_html( CBG_AP_MIN_WP ),
					esc_html( $wp_version )
				),
				esc_html__( 'Requisiti non soddisfatti', 'cbg-ap' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Crea (o aggiorna via dbDelta) tutte le tabelle custom.
	 *
	 * Lo schema riflette il documento di requisiti v1.1 §4.2.
	 *
	 * @return void
	 */
	protected static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		// dbDelta è schizzinoso: serve formato standard (PRIMARY KEY, KEY, due spazi, ecc.).
		$schemas = array();

		$schemas[] = "CREATE TABLE {$p}cbg_ap_user_layout (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			widget_id VARCHAR(64) NOT NULL,
			position SMALLINT NOT NULL DEFAULT 0,
			size CHAR(1) NOT NULL DEFAULT 'M',
			visible TINYINT(1) NOT NULL DEFAULT 1,
			settings_json LONGTEXT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_widget (user_id, widget_id),
			KEY idx_user_position (user_id, position)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_user_prefs (
			user_id BIGINT UNSIGNED NOT NULL,
			prefs_json LONGTEXT NOT NULL,
			digest_morning_time TIME DEFAULT '07:30:00',
			digest_evening_time TIME DEFAULT '18:00:00',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (user_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_notifications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(64) NOT NULL,
			payload_json LONGTEXT NOT NULL,
			read_at DATETIME NULL,
			sent_email_at DATETIME NULL,
			email_mode ENUM('immediate','digest_morning','digest_evening','off') NOT NULL,
			delivery_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_attempt_at DATETIME NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_unread (user_id, read_at),
			KEY idx_email_pending (sent_email_at, email_mode, delivery_attempts)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_access_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			username VARCHAR(60) NULL,
			ip VARCHAR(45) NOT NULL,
			user_agent VARCHAR(255) NULL,
			action ENUM(
				'login_success','login_fail','logout',
				'2fa_success','2fa_fail',
				'sso_rejected_no_group','sso_rejected_wrong_domain',
				'group_sync_role_removed',
				'new_device_login',
				'stepup_success','stepup_fail','stepup_required_blocked',
				'stepup_recovery_admin'
			) NOT NULL,
			auth_method ENUM('sso_google','password','totp') NULL,
			details_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_time (user_id, created_at),
			KEY idx_action_time (action, created_at),
			KEY idx_ip_time (ip, created_at)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_read_receipts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			object_type VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			read_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_object (user_id, object_type, object_id),
			KEY idx_object (object_type, object_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_2fa_secrets (
			user_id BIGINT UNSIGNED NOT NULL,
			secret_encrypted VARBINARY(255) NOT NULL,
			backup_codes_hashed_json LONGTEXT NOT NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 0,
			required TINYINT(1) NOT NULL DEFAULT 0,
			enabled_at DATETIME NULL,
			last_used_at DATETIME NULL,
			PRIMARY KEY  (user_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_user_news_capabilities (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			news_tipo_term_id BIGINT UNSIGNED NOT NULL,
			granted_by BIGINT UNSIGNED NOT NULL,
			granted_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_tipo (user_id, news_tipo_term_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_known_devices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			device_fingerprint VARCHAR(64) NOT NULL,
			ip VARCHAR(45) NOT NULL,
			user_agent VARCHAR(255) NOT NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			trusted TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_fp (user_id, device_fingerprint),
			KEY idx_user_last (user_id, last_seen)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_classi (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			anno_scolastico VARCHAR(9) NOT NULL,
			anno_corso TINYINT UNSIGNED NOT NULL,
			sezione VARCHAR(8) NOT NULL,
			indirizzo VARCHAR(64) NOT NULL,
			articolazione VARCHAR(64) NULL,
			codice_meccanografico VARCHAR(16) NULL,
			sede VARCHAR(32) NULL,
			coordinatore_user_id BIGINT UNSIGNED NULL,
			attiva TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_classe_as (anno_scolastico, anno_corso, sezione, indirizzo),
			KEY idx_as_attiva (anno_scolastico, attiva),
			KEY idx_coordinatore (coordinatore_user_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_studenti_classi (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			classe_id BIGINT UNSIGNED NOT NULL,
			anno_scolastico VARCHAR(9) NOT NULL,
			dal DATE NOT NULL,
			al DATE NULL,
			numero_registro SMALLINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_studente_classe_as (user_id, classe_id, anno_scolastico),
			KEY idx_classe_as (classe_id, anno_scolastico),
			KEY idx_user_as (user_id, anno_scolastico),
			KEY idx_attivo (user_id, al)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_docenti_classi (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			classe_id BIGINT UNSIGNED NOT NULL,
			materia VARCHAR(64) NOT NULL,
			ore_settimanali TINYINT UNSIGNED NULL,
			ruolo_docente ENUM('titolare','itp','sostegno','potenziamento') NOT NULL DEFAULT 'titolare',
			coordinatore TINYINT(1) NOT NULL DEFAULT 0,
			anno_scolastico VARCHAR(9) NOT NULL,
			dal DATE NOT NULL,
			al DATE NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_doc_classe_mat_as (user_id, classe_id, materia, anno_scolastico),
			KEY idx_classe_as (classe_id, anno_scolastico),
			KEY idx_user_as (user_id, anno_scolastico)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_content_audience (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			audience_type ENUM(
				'all','role','user','classe',
				'anno_corso','indirizzo','sede','gruppo_workspace'
			) NOT NULL,
			audience_value VARCHAR(128) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_obj_audience (object_type, object_id, audience_type, audience_value),
			KEY idx_audience (audience_type, audience_value),
			KEY idx_object (object_type, object_id),
			KEY idx_object_type_audience (object_type, audience_type, audience_value)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_external_mappings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ap_table VARCHAR(64) NOT NULL,
			ap_id BIGINT UNSIGNED NOT NULL,
			external_system VARCHAR(32) NOT NULL,
			external_id VARCHAR(128) NOT NULL,
			last_synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_mapping (ap_table, ap_id, external_system),
			KEY idx_external (external_system, external_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_import_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			imported_by BIGINT UNSIGNED NOT NULL,
			import_type ENUM('classi','studenti','docenti_classi') NOT NULL,
			filename VARCHAR(255) NOT NULL,
			rows_total INT UNSIGNED NOT NULL,
			rows_new INT UNSIGNED NOT NULL,
			rows_modified INT UNSIGNED NOT NULL,
			rows_skipped INT UNSIGNED NOT NULL,
			rows_failed INT UNSIGNED NOT NULL,
			errors_json LONGTEXT NULL,
			status ENUM('preview','committed','rolled_back') NOT NULL,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_user_time (imported_by, started_at)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$p}cbg_ap_cron_runs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_name VARCHAR(64) NOT NULL,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			status ENUM('running','success','failed','timeout') NOT NULL,
			items_processed INT UNSIGNED NOT NULL DEFAULT 0,
			error_message TEXT NULL,
			duration_ms INT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY idx_job_time (job_name, started_at),
			KEY idx_status (status, started_at)
		) $charset_collate;";

		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Crea le option di default se mancanti.
	 *
	 * Usa `add_option` (non `update_option`) per non sovrascrivere
	 * configurazioni esistenti su riattivazione.
	 *
	 * @return void
	 */
	protected static function create_default_options() {
		// Mapping SSO gruppi Workspace → ruoli CBG. Vuoto di default:
		// nessun utente può loggarsi via SSO finché l'admin non popola la mappa.
		// Questa è la scelta sicura prevista da §2.2.
		add_option( 'cbg_ap_sso_group_mapping', array(), '', 'no' );

		// Dominio istituzionale autorizzato per il claim `hd` di Google OIDC.
		add_option( 'cbg_ap_sso_allowed_domain', 'cigna-baruffi-garelli.edu.it', '', 'no' );

		// Anno scolastico corrente (formato AAAA/AAAA). Calcolo euristico.
		add_option( 'cbg_ap_anno_scolastico_corrente', self::current_anno_scolastico(), '', 'yes' );

		// Sedi di default dell'istituto.
		add_option( 'cbg_ap_sedi', array( 'Cigna', 'Baruffi', 'Garelli' ), '', 'yes' );

		// Modalità sync GESTIONESCUOLA: off all'attivazione (vedi §3.8).
		add_option( 'cbg_ap_sync_gestionescuola_mode', 'off', '', 'no' );

		// Modalità sync classi da Workspace: read-only di default.
		add_option( 'cbg_ap_sync_workspace_classes_mode', 'read_only', '', 'no' );

		// Ritenzione log accessi (mesi).
		add_option( 'cbg_ap_access_log_retention_months', 12, '', 'no' );

		// Finestra modifica autore news post-pubblicazione (ore).
		add_option( 'cbg_ap_news_edit_window_hours', 24, '', 'no' );

		// Versione DB per gestione migrazioni future.
		add_option( 'cbg_ap_db_version', '0.0.0', '', 'no' );

		// Versione delle definizioni ruoli (vedi CBG_AP_ROLES_VERSION).
		// 0.0.0 forza la sincronizzazione al primo bootstrap.
		add_option( 'cbg_ap_roles_version', '0.0.0', '', 'no' );
	}

	/**
	 * Genera la chiave segreta per l'endpoint cron/tick se non presente.
	 *
	 * 32 byte random esadecimali (64 caratteri). L'admin può rigenerarla
	 * dal pannello Stato sistema; al momento il cron Unix andrà aggiornato.
	 *
	 * @return void
	 */
	protected static function generate_cron_secret_if_missing() {
		if ( false === get_option( 'cbg_ap_cron_secret', false ) ) {
			add_option(
				'cbg_ap_cron_secret',
				bin2hex( random_bytes( 32 ) ),
				'',
				'no'
			);
		}
	}

	/**
	 * Inserisce i termini della tassonomia `cbg_news_tipo` previsti dal requisito §3.2.
	 *
	 * Eseguito qui in modo opportunistico: se la tassonomia è già registrata
	 * (es. update plugin) inseriamo subito; altrimenti, il CPT loader la creerà
	 * e farà seed al primo init via cbg_ap_needs_post_activation_setup.
	 *
	 * @return void
	 */
	protected static function seed_news_types() {
		$tipi = array(
			'didattica'           => __( 'Didattica', 'cbg-ap' ),
			'eventi'              => __( 'Eventi', 'cbg-ap' ),
			'fsl'                 => __( 'FSL — Formazione Scuola-Lavoro', 'cbg-ap' ),
			'orientamento'        => __( 'Orientamento', 'cbg-ap' ),
			'progetti'            => __( 'Progetti', 'cbg-ap' ),
			'viaggi-uscite'       => __( 'Viaggi e uscite didattiche', 'cbg-ap' ),
			'sportelli'           => __( 'Sportelli', 'cbg-ap' ),
			'formazione-docenti'  => __( 'Formazione docenti', 'cbg-ap' ),
		);
		update_option( 'cbg_ap_default_news_types', $tipi, false );
	}

	/**
	 * Calcola l'anno scolastico corrente nel formato AAAA/AAAA.
	 *
	 * Cambio a settembre (mese 9). Es. il 15/05/2026 → "2025/2026";
	 * il 10/09/2026 → "2026/2027".
	 *
	 * @return string
	 */
	protected static function current_anno_scolastico() {
		$now   = current_datetime();
		$year  = (int) $now->format( 'Y' );
		$month = (int) $now->format( 'n' );
		if ( $month >= 9 ) {
			return sprintf( '%d/%d', $year, $year + 1 );
		}
		return sprintf( '%d/%d', $year - 1, $year );
	}

	/**
	 * Aggiorna l'option della versione DB.
	 *
	 * @return void
	 */
	protected static function update_db_version() {
		update_option( 'cbg_ap_db_version', CBG_AP_DB_VERSION, false );
	}
}
