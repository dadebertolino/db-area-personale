<?php
/**
 * DSAR: diritto di accesso (art. 15) e di cancellazione (art. 17 GDPR)
 * tramite gli strumenti nativi di WordPress (Strumenti → Esporta/Cancella
 * dati personali).
 *
 * Doppio canale:
 *  1. `dbph_user_data_exporters` / `dbph_user_data_erasers` (DB Privacy Hub,
 *     chiave `label`): l'Hub li ribalta sui filter core.
 *  2. `wp_privacy_personal_data_exporters` / `_erasers` (core, chiavi
 *     `exporter_friendly_name` / `eraser_friendly_name`): attivi solo se
 *     l'Hub NON è presente (`class_exists( 'DBPH_DSAR' )`), per evitare la
 *     doppia registrazione.
 *
 * Risoluzione dell'interessato: email → utente WordPress (`get_user_by`).
 * Il log accessi viene cercato anche per nome utente/email digitati nei
 * tentativi falliti, quindi anche senza account.
 *
 * Tre exporter e tre eraser, ciascuno con paginazione propria:
 *  - area personale: preferenze, layout, user meta, conferme di lettura,
 *    dispositivi noti, stato 2FA, anagrafica classi e abilitazioni;
 *  - notifiche;
 *  - log accessi.
 *
 * Conservazioni (items_retained + messaggio):
 *  - log accessi: conservato fino alla scadenza della finestra di sicurezza
 *    (filter `cbg_ap_dsar_erase_access_log` per cancellarlo subito);
 *  - configurazione 2FA: conservata finché l'account esiste;
 *  - anagrafica classi, abilitazioni news, registro import: dato
 *    istituzionale gestito dalla segreteria.
 *
 * @package CBG_AP
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Privacy_DSAR
 */
class CBG_AP_Privacy_DSAR {

	/**
	 * Righe per pagina di export.
	 */
	const PER_PAGE = 100;

	/**
	 * Righe cancellate per chiamata dell'eraser.
	 */
	const ERASE_BATCH = 1000;

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public static function init() {
		// Canale primario: DB Privacy Hub.
		add_filter( 'dbph_user_data_exporters', array( __CLASS__, 'register_exporters_via_hub' ) );
		add_filter( 'dbph_user_data_erasers', array( __CLASS__, 'register_erasers_via_hub' ) );

		// Fallback: WordPress core (solo se l'Hub non c'è).
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );

		// L'Hub riconosce i marker XXX_DSAR_AVAILABLE solo per prefissi noti:
		// per CBG_AP_DSAR_AVAILABLE serve il filter dedicato.
		add_filter( 'dbph_dsar_available', '__return_true' );

		// Cancellazione account → cancellazione dati per-utente.
		add_action( 'deleted_user', array( __CLASS__, 'purge_user' ), 10, 1 );
	}

	/* =====================================================================
	 * Definizioni
	 * ================================================================== */

	/**
	 * Exporter: slug => label, callback.
	 *
	 * @return array
	 */
	private static function exporter_definitions() {
		return array(
			'cbg-ap-area-personale' => array( self::label( 'area' ), array( __CLASS__, 'export_personal_area' ) ),
			'cbg-ap-notifications'  => array( self::label( 'notifications' ), array( __CLASS__, 'export_notifications' ) ),
			'cbg-ap-access-log'     => array( self::label( 'access_log' ), array( __CLASS__, 'export_access_log' ) ),
		);
	}

	/**
	 * Eraser: slug => label, callback.
	 *
	 * @return array
	 */
	private static function eraser_definitions() {
		return array(
			'cbg-ap-area-personale' => array( self::label( 'area' ), array( __CLASS__, 'erase_personal_area' ) ),
			'cbg-ap-notifications'  => array( self::label( 'notifications' ), array( __CLASS__, 'erase_notifications' ) ),
			'cbg-ap-access-log'     => array( self::label( 'access_log' ), array( __CLASS__, 'erase_access_log' ) ),
		);
	}

	/**
	 * Etichette dei gruppi.
	 *
	 * @param string $key Chiave gruppo.
	 * @return string
	 */
	private static function label( $key ) {
		switch ( $key ) {
			case 'notifications':
				return __( 'DB Area Personale — Notifiche', 'cbg-ap' );
			case 'access_log':
				return __( 'DB Area Personale — Log accessi', 'cbg-ap' );
			default:
				return __( 'DB Area Personale — Area personale', 'cbg-ap' );
		}
	}

	/* =====================================================================
	 * Registrazione: canale Hub
	 * ================================================================== */

	/**
	 * Exporter sul filter dell'Hub (chiave `label`).
	 *
	 * @param array $exporters Exporter registrati.
	 * @return array
	 */
	public static function register_exporters_via_hub( $exporters ) {
		foreach ( self::exporter_definitions() as $slug => $def ) {
			$exporters[ $slug ] = array(
				'label'    => $def[0],
				'callback' => $def[1],
			);
		}
		return $exporters;
	}

	/**
	 * Eraser sul filter dell'Hub (chiave `label`).
	 *
	 * @param array $erasers Eraser registrati.
	 * @return array
	 */
	public static function register_erasers_via_hub( $erasers ) {
		foreach ( self::eraser_definitions() as $slug => $def ) {
			$erasers[ $slug ] = array(
				'label'    => $def[0],
				'callback' => $def[1],
			);
		}
		return $erasers;
	}

	/* =====================================================================
	 * Registrazione: fallback core
	 * ================================================================== */

	/**
	 * Exporter core, solo senza Hub.
	 *
	 * @param array $exporters Exporter registrati.
	 * @return array
	 */
	public static function register_exporters( $exporters ) {
		if ( class_exists( 'DBPH_DSAR' ) ) {
			return $exporters;
		}
		foreach ( self::exporter_definitions() as $slug => $def ) {
			$exporters[ $slug ] = array(
				'exporter_friendly_name' => $def[0],
				'callback'               => $def[1],
			);
		}
		return $exporters;
	}

	/**
	 * Eraser core, solo senza Hub.
	 *
	 * @param array $erasers Eraser registrati.
	 * @return array
	 */
	public static function register_erasers( $erasers ) {
		if ( class_exists( 'DBPH_DSAR' ) ) {
			return $erasers;
		}
		foreach ( self::eraser_definitions() as $slug => $def ) {
			$erasers[ $slug ] = array(
				'eraser_friendly_name' => $def[0],
				'callback'             => $def[1],
			);
		}
		return $erasers;
	}

	/* =====================================================================
	 * Export
	 * ================================================================== */

	/**
	 * Area personale. Pagina 1: tutti i dati a riga singola/limitata;
	 * le conferme di lettura (potenzialmente molte) sono paginate su tutte le pagine.
	 *
	 * @param string $email_address Email dell'interessato.
	 * @param int    $page          Pagina (1-based).
	 * @return array{data: array, done: bool}
	 */
	public static function export_personal_area( $email_address, $page = 1 ) {
		global $wpdb;

		$page = max( 1, (int) $page );
		$user = self::resolve_user( $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$uid   = (int) $user->ID;
		$p     = $wpdb->prefix;
		$group = 'cbg-ap-area-personale';
		$label = self::label( 'area' );
		$data  = array();

		if ( 1 === $page ) {
			// Preferenze.
			$prefs = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cbg_ap_user_prefs WHERE user_id = %d", $uid ), ARRAY_A );
			if ( $prefs ) {
				$data[] = self::item(
					$group,
					$label,
					'prefs-' . $uid,
					array(
						__( 'Preferenze (JSON)', 'cbg-ap' ) => $prefs['prefs_json'],
						__( 'Orario digest mattino', 'cbg-ap' ) => $prefs['digest_morning_time'],
						__( 'Orario digest sera', 'cbg-ap' ) => $prefs['digest_evening_time'],
						__( 'Aggiornato il', 'cbg-ap' )   => $prefs['updated_at'],
					)
				);
			}

			// Layout dashboard.
			$layout = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cbg_ap_user_layout WHERE user_id = %d ORDER BY position ASC, id ASC", $uid ), ARRAY_A );
			foreach ( (array) $layout as $row ) {
				$data[] = self::item(
					$group,
					$label,
					'layout-' . $row['id'],
					array(
						__( 'Widget', 'cbg-ap' )             => $row['widget_id'],
						__( 'Posizione', 'cbg-ap' )          => $row['position'],
						__( 'Dimensione', 'cbg-ap' )         => $row['size'],
						__( 'Visibile', 'cbg-ap' )           => $row['visible'] ? __( 'Sì', 'cbg-ap' ) : __( 'No', 'cbg-ap' ),
						__( 'Impostazioni (JSON)', 'cbg-ap' ) => $row['settings_json'],
						__( 'Aggiornato il', 'cbg-ap' )      => $row['updated_at'],
					)
				);
			}

			// User meta del plugin.
			$meta = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT umeta_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
					$uid,
					$wpdb->esc_like( 'cbg_ap_' ) . '%'
				),
				ARRAY_A
			);
			foreach ( (array) $meta as $row ) {
				$data[] = self::item(
					$group,
					$label,
					'meta-' . $row['umeta_id'],
					array(
						__( 'Chiave', 'cbg-ap' ) => $row['meta_key'],
						__( 'Valore', 'cbg-ap' ) => $row['meta_value'],
					)
				);
			}

			// Stato 2FA (il segreto e i codici di recupero NON vengono esportati).
			$tfa = $wpdb->get_row( $wpdb->prepare( "SELECT enabled, required, enabled_at, last_used_at FROM {$p}cbg_ap_2fa_secrets WHERE user_id = %d", $uid ), ARRAY_A );
			if ( $tfa ) {
				$data[] = self::item(
					$group,
					$label,
					'2fa-' . $uid,
					array(
						__( '2FA attiva', 'cbg-ap' )        => $tfa['enabled'] ? __( 'Sì', 'cbg-ap' ) : __( 'No', 'cbg-ap' ),
						__( '2FA obbligatoria', 'cbg-ap' )  => $tfa['required'] ? __( 'Sì', 'cbg-ap' ) : __( 'No', 'cbg-ap' ),
						__( 'Attivata il', 'cbg-ap' )       => $tfa['enabled_at'],
						__( 'Ultimo utilizzo', 'cbg-ap' )   => $tfa['last_used_at'],
						__( 'Nota', 'cbg-ap' )              => __( 'Il segreto TOTP (cifrato) e i codici di recupero (hash) non sono esportati per motivi di sicurezza.', 'cbg-ap' ),
					)
				);
			}

			// Dispositivi noti.
			$devices = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cbg_ap_known_devices WHERE user_id = %d ORDER BY id ASC", $uid ), ARRAY_A );
			foreach ( (array) $devices as $row ) {
				$data[] = self::item(
					$group,
					$label,
					'device-' . $row['id'],
					array(
						__( 'Impronta dispositivo', 'cbg-ap' ) => $row['device_fingerprint'],
						__( 'IP', 'cbg-ap' )                  => $row['ip'],
						__( 'User agent', 'cbg-ap' )          => $row['user_agent'],
						__( 'Primo accesso', 'cbg-ap' )       => $row['first_seen'],
						__( 'Ultimo accesso', 'cbg-ap' )      => $row['last_seen'],
						__( 'Attendibile', 'cbg-ap' )         => $row['trusted'] ? __( 'Sì', 'cbg-ap' ) : __( 'No', 'cbg-ap' ),
					)
				);
			}

			// Abilitazioni per tipo news.
			$caps = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cbg_ap_user_news_capabilities WHERE user_id = %d ORDER BY id ASC", $uid ), ARRAY_A );
			foreach ( (array) $caps as $row ) {
				$term   = get_term( (int) $row['news_tipo_term_id'] );
				$data[] = self::item(
					$group,
					$label,
					'newscap-' . $row['id'],
					array(
						__( 'Abilitazione pubblicazione tipo news', 'cbg-ap' ) => ( $term && ! is_wp_error( $term ) ) ? $term->name : $row['news_tipo_term_id'],
						__( 'Concessa da (ID utente)', 'cbg-ap' ) => $row['granted_by'],
						__( 'Concessa il', 'cbg-ap' )             => $row['granted_at'],
					)
				);
			}

			// Appartenenza a classi (studente).
			$stud = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cbg_ap_studenti_classi WHERE user_id = %d ORDER BY id ASC", $uid ), ARRAY_A );
			foreach ( (array) $stud as $row ) {
				$data[] = self::item(
					$group,
					$label,
					'studente-classe-' . $row['id'],
					array(
						__( 'Classe (ID)', 'cbg-ap' )         => $row['classe_id'],
						__( 'Anno scolastico', 'cbg-ap' )     => $row['anno_scolastico'],
						__( 'Dal', 'cbg-ap' )                 => $row['dal'],
						__( 'Al', 'cbg-ap' )                  => $row['al'],
						__( 'Numero di registro', 'cbg-ap' )  => $row['numero_registro'],
					)
				);
			}

			// Incarichi docente.
			$doc = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cbg_ap_docenti_classi WHERE user_id = %d ORDER BY id ASC", $uid ), ARRAY_A );
			foreach ( (array) $doc as $row ) {
				$data[] = self::item(
					$group,
					$label,
					'docente-classe-' . $row['id'],
					array(
						__( 'Classe (ID)', 'cbg-ap' )       => $row['classe_id'],
						__( 'Materia', 'cbg-ap' )           => $row['materia'],
						__( 'Ore settimanali', 'cbg-ap' )   => $row['ore_settimanali'],
						__( 'Ruolo', 'cbg-ap' )             => $row['ruolo_docente'],
						__( 'Coordinatore', 'cbg-ap' )      => $row['coordinatore'] ? __( 'Sì', 'cbg-ap' ) : __( 'No', 'cbg-ap' ),
						__( 'Anno scolastico', 'cbg-ap' )   => $row['anno_scolastico'],
						__( 'Dal', 'cbg-ap' )               => $row['dal'],
						__( 'Al', 'cbg-ap' )                => $row['al'],
					)
				);
			}

			// Classi coordinate.
			$coord = $wpdb->get_results( $wpdb->prepare( "SELECT id, anno_scolastico, anno_corso, sezione, indirizzo FROM {$p}cbg_ap_classi WHERE coordinatore_user_id = %d ORDER BY id ASC", $uid ), ARRAY_A );
			foreach ( (array) $coord as $row ) {
				$data[] = self::item(
					$group,
					$label,
					'coordinatore-' . $row['id'],
					array(
						__( 'Coordinatore della classe', 'cbg-ap' ) => sprintf( '%s%s %s (%s)', $row['anno_corso'], $row['sezione'], $row['indirizzo'], $row['anno_scolastico'] ),
					)
				);
			}

			// Import eseguiti dall'utente (registro amministrativo).
			$imports = $wpdb->get_results( $wpdb->prepare( "SELECT id, import_type, filename, status, started_at FROM {$p}cbg_ap_import_log WHERE imported_by = %d ORDER BY id ASC", $uid ), ARRAY_A );
			foreach ( (array) $imports as $row ) {
				$data[] = self::item(
					$group,
					$label,
					'import-' . $row['id'],
					array(
						__( 'Import eseguito', 'cbg-ap' ) => $row['import_type'],
						__( 'File', 'cbg-ap' )            => $row['filename'],
						__( 'Stato', 'cbg-ap' )           => $row['status'],
						__( 'Data', 'cbg-ap' )            => $row['started_at'],
					)
				);
			}
		}

		// Conferme di lettura: paginate.
		$receipts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_type, object_id, read_at FROM {$p}cbg_ap_read_receipts WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$uid,
				self::PER_PAGE,
				( $page - 1 ) * self::PER_PAGE
			),
			ARRAY_A
		);
		$receipts = (array) $receipts;
		foreach ( $receipts as $row ) {
			$data[] = self::item(
				$group,
				$label,
				'receipt-' . $row['id'],
				array(
					__( 'Contenuto letto', 'cbg-ap' ) => $row['object_type'] . ' #' . $row['object_id'],
					__( 'Letto il', 'cbg-ap' )        => $row['read_at'],
				)
			);
		}

		return array(
			'data' => $data,
			'done' => count( $receipts ) < self::PER_PAGE,
		);
	}

	/**
	 * Notifiche, paginate.
	 *
	 * @param string $email_address Email dell'interessato.
	 * @param int    $page          Pagina (1-based).
	 * @return array{data: array, done: bool}
	 */
	public static function export_notifications( $email_address, $page = 1 ) {
		global $wpdb;

		$page = max( 1, (int) $page );
		$user = self::resolve_user( $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cbg_ap_notifications WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				(int) $user->ID,
				self::PER_PAGE,
				( $page - 1 ) * self::PER_PAGE
			),
			ARRAY_A
		);

		$data  = array();
		$label = self::label( 'notifications' );
		foreach ( $rows as $row ) {
			$data[] = self::item(
				'cbg-ap-notifications',
				$label,
				'notification-' . $row['id'],
				array(
					__( 'Tipo', 'cbg-ap' )              => $row['type'],
					__( 'Contenuto (JSON)', 'cbg-ap' )  => $row['payload_json'],
					__( 'Creata il', 'cbg-ap' )         => $row['created_at'],
					__( 'Letta il', 'cbg-ap' )          => $row['read_at'],
					__( 'Modalità email', 'cbg-ap' )    => $row['email_mode'],
					__( 'Email inviata il', 'cbg-ap' )  => $row['sent_email_at'],
					__( 'Tentativi di invio', 'cbg-ap' ) => $row['delivery_attempts'],
				)
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Log accessi, paginato. Cerca per user_id e per nome utente/email
	 * (tentativi falliti), anche se l'email non corrisponde a un account.
	 *
	 * @param string $email_address Email dell'interessato.
	 * @param int    $page          Pagina (1-based).
	 * @return array{data: array, done: bool}
	 */
	public static function export_access_log( $email_address, $page = 1 ) {
		global $wpdb;

		$page  = max( 1, (int) $page );
		$where = self::access_log_where( $email_address );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cbg_ap_access_log WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where è già preparato.
				self::PER_PAGE,
				( $page - 1 ) * self::PER_PAGE
			),
			ARRAY_A
		);

		$data  = array();
		$label = self::label( 'access_log' );
		foreach ( $rows as $row ) {
			$data[] = self::item(
				'cbg-ap-access-log',
				$label,
				'access-' . $row['id'],
				array(
					__( 'Data e ora', 'cbg-ap' )   => $row['created_at'],
					__( 'Evento', 'cbg-ap' )       => $row['action'],
					__( 'Metodo', 'cbg-ap' )       => $row['auth_method'],
					__( 'Nome utente', 'cbg-ap' )  => $row['username'],
					__( 'IP', 'cbg-ap' )           => $row['ip'],
					__( 'User agent', 'cbg-ap' )   => $row['user_agent'],
					__( 'Dettagli (JSON)', 'cbg-ap' ) => $row['details_json'],
				)
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/* =====================================================================
	 * Erase
	 * ================================================================== */

	/**
	 * Area personale: cancella preferenze, layout, user meta cbg_ap_*,
	 * conferme di lettura e dispositivi noti. Conserva (e segnala) 2FA,
	 * abilitazioni news, anagrafica classi e registro import.
	 *
	 * @param string $email_address Email dell'interessato.
	 * @param int    $page          Pagina (non usata: una sola passata).
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public static function erase_personal_area( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- firma del core.
		global $wpdb;

		$user = self::resolve_user( $email_address );
		if ( ! $user ) {
			return self::erase_response( 0, false, array(), true );
		}

		$uid     = (int) $user->ID;
		$p       = $wpdb->prefix;
		$removed = 0;

		$removed += (int) $wpdb->delete( "{$p}cbg_ap_user_prefs", array( 'user_id' => $uid ), array( '%d' ) );
		$removed += (int) $wpdb->delete( "{$p}cbg_ap_user_layout", array( 'user_id' => $uid ), array( '%d' ) );
		$removed += (int) $wpdb->delete( "{$p}cbg_ap_read_receipts", array( 'user_id' => $uid ), array( '%d' ) );
		$removed += (int) $wpdb->delete( "{$p}cbg_ap_known_devices", array( 'user_id' => $uid ), array( '%d' ) );

		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$uid,
				$wpdb->esc_like( 'cbg_ap_' ) . '%'
			)
		);
		foreach ( array_unique( (array) $meta_keys ) as $key ) {
			if ( delete_user_meta( $uid, $key ) ) {
				++$removed;
			}
		}

		$retained = false;
		$messages = array();

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}cbg_ap_2fa_secrets WHERE user_id = %d LIMIT 1", $uid ) ) ) {
			$retained   = true;
			$messages[] = __( 'DB Area Personale: la configurazione dell\'autenticazione a due fattori è conservata finché l\'account esiste (sicurezza, art. 32 GDPR) e viene cancellata con l\'account.', 'cbg-ap' );
		}

		$registry = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cbg_ap_studenti_classi WHERE user_id = %d", $uid ) )
			+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cbg_ap_docenti_classi WHERE user_id = %d", $uid ) )
			+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cbg_ap_classi WHERE coordinatore_user_id = %d", $uid ) )
			+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cbg_ap_user_news_capabilities WHERE user_id = %d", $uid ) )
			+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cbg_ap_import_log WHERE imported_by = %d", $uid ) );
		if ( $registry > 0 ) {
			$retained   = true;
			$messages[] = __( 'DB Area Personale: anagrafica classi, incarichi, abilitazioni alla pubblicazione e registro degli import sono dati istituzionali gestiti dalla segreteria e non vengono cancellati automaticamente: valutare la richiesta con il titolare.', 'cbg-ap' );
		}

		return self::erase_response( $removed, $retained, $messages, true );
	}

	/**
	 * Notifiche: cancellazione a blocchi.
	 *
	 * @param string $email_address Email dell'interessato.
	 * @param int    $page          Pagina (le righe cancellate escono dalla finestra).
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public static function erase_notifications( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- firma del core.
		global $wpdb;

		$user = self::resolve_user( $email_address );
		if ( ! $user ) {
			return self::erase_response( 0, false, array(), true );
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}cbg_ap_notifications WHERE user_id = %d LIMIT %d",
				(int) $user->ID,
				self::ERASE_BATCH
			)
		);
		$deleted = (int) $deleted;

		return self::erase_response( $deleted, false, array(), $deleted < self::ERASE_BATCH );
	}

	/**
	 * Log accessi: conservato per la finestra di sicurezza, salvo filter.
	 *
	 * @param string $email_address Email dell'interessato.
	 * @param int    $page          Pagina (le righe cancellate escono dalla finestra).
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public static function erase_access_log( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- firma del core.
		global $wpdb;

		$table = $wpdb->prefix . 'cbg_ap_access_log';
		$where = self::access_log_where( $email_address );

		/**
		 * Filter: cancellare subito il log accessi su richiesta art. 17?
		 *
		 * Default false: il log è conservato per la finestra di sicurezza
		 * (legittimo interesse / art. 32) e cancellato automaticamente alla
		 * scadenza. Restituire true per cancellarlo alla richiesta.
		 *
		 * @since 1.1.0
		 * @param bool   $erase         Default false.
		 * @param string $email_address Email dell'interessato.
		 */
		if ( apply_filters( 'cbg_ap_dsar_erase_access_log', false, $email_address ) ) {
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE {$where} LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where è già preparato.
					self::ERASE_BATCH
				)
			);
			return self::erase_response( $deleted, false, array(), $deleted < self::ERASE_BATCH );
		}

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where è già preparato.
		if ( 0 === $count ) {
			return self::erase_response( 0, false, array(), true );
		}

		$months   = class_exists( 'CBG_AP_Access_Log_Retention' ) ? CBG_AP_Access_Log_Retention::retention_months() : 12;
		$messages = array(
			$months > 0
				? sprintf(
					/* translators: 1: numero righe, 2: mesi */
					__( 'DB Area Personale: %1$d eventi del log accessi sono conservati per finalità di sicurezza (art. 6.1.f / art. 32 GDPR) e saranno cancellati automaticamente allo scadere dei %2$d mesi dalla registrazione.', 'cbg-ap' ),
					$count,
					$months
				)
				: sprintf(
					/* translators: %d: numero righe */
					__( 'DB Area Personale: %d eventi del log accessi sono conservati per finalità di sicurezza; la pulizia automatica è disattivata, valutare la cancellazione manuale.', 'cbg-ap' ),
					$count
				),
		);

		return self::erase_response( 0, true, $messages, true );
	}

	/**
	 * Cancellazione dell'account WordPress: rimuove i dati per-utente.
	 * Il log accessi resta fino alla sua scadenza; i dati anagrafici
	 * (classi, import) restano alla segreteria.
	 *
	 * @param int $user_id ID utente cancellato.
	 * @return void
	 */
	public static function purge_user( $user_id ) {
		global $wpdb;

		$uid = (int) $user_id;
		if ( $uid <= 0 ) {
			return;
		}

		$tables = array(
			'cbg_ap_user_prefs',
			'cbg_ap_user_layout',
			'cbg_ap_notifications',
			'cbg_ap_read_receipts',
			'cbg_ap_known_devices',
			'cbg_ap_2fa_secrets',
			'cbg_ap_user_news_capabilities',
		);
		foreach ( $tables as $table ) {
			$wpdb->delete( $wpdb->prefix . $table, array( 'user_id' => $uid ), array( '%d' ) );
		}
	}

	/* =====================================================================
	 * Helper
	 * ================================================================== */

	/**
	 * Email → utente WordPress.
	 *
	 * @param string $email_address Email.
	 * @return WP_User|null
	 */
	private static function resolve_user( $email_address ) {
		$email = strtolower( trim( (string) $email_address ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		$user = get_user_by( 'email', $email );
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Clausola WHERE (già preparata) per il log accessi.
	 *
	 * @param string $email_address Email.
	 * @return string
	 */
	private static function access_log_where( $email_address ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email_address ) );
		$names = array( '' !== $email ? $email : '-' );
		$user  = self::resolve_user( $email );

		if ( $user ) {
			$names[] = strtolower( $user->user_login );
			$names   = array_values( array_unique( $names ) );

			return $wpdb->prepare(
				'( user_id = %d OR LOWER(username) IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ') )',
				array_merge( array( (int) $user->ID ), $names )
			);
		}

		return $wpdb->prepare( 'LOWER(username) = %s', $names[0] );
	}

	/**
	 * Voce di export nel formato core.
	 *
	 * @param string $group_id    ID gruppo.
	 * @param string $group_label Etichetta gruppo.
	 * @param string $item_id     ID elemento.
	 * @param array  $fields      Nome => valore.
	 * @return array
	 */
	private static function item( $group_id, $group_label, $item_id, array $fields ) {
		$data = array();
		foreach ( $fields as $name => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$data[] = array(
				'name'  => (string) $name,
				'value' => (string) $value,
			);
		}

		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}

	/**
	 * Risposta eraser nel formato core.
	 *
	 * @param int   $removed  Elementi rimossi.
	 * @param bool  $retained Elementi conservati.
	 * @param array $messages Messaggi.
	 * @param bool  $done     Fine paginazione.
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	private static function erase_response( $removed, $retained, array $messages, $done ) {
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => (bool) $retained,
			'messages'       => $messages,
			'done'           => (bool) $done,
		);
	}
}

add_action( 'plugins_loaded', array( 'CBG_AP_Privacy_DSAR', 'init' ), 10 );
