<?php
/**
 * Gestione capability dinamiche.
 *
 * Implementa la meta-capability `cbg_ap_publish_news_type` che traduce
 * `current_user_can( 'cbg_ap_publish_news_type', $term_id )` in:
 *
 *   1. l'utente ha la capability base `cbg_ap_publish_news`, E
 *   2. esiste una riga in `cbg_ap_user_news_capabilities` per
 *      (user_id, news_tipo_term_id).
 *
 * Decisione architettturale (vedi documento requisiti v1.2 §3.2,
 * opzione 2 scelta in design review): le capability granulari per tipo
 * news non sono cap WordPress hardcoded; vivono nella tabella e vengono
 * valutate dinamicamente. Vantaggi:
 *   - L'admin può aggiungere un nuovo tipo news senza migrazione cap.
 *   - L'assegnazione pubblicatori è puramente data-driven.
 *   - L'audit chi-può-pubblicare-cosa è una semplice SELECT.
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Capabilities
 */
class CBG_AP_Capabilities {

	/**
	 * Cache in-memory per request: user_id => term_id[] dei tipi autorizzati.
	 *
	 * @var array<int, int[]>
	 */
	protected $authorized_terms_cache = array();

	/**
	 * Hook di entry point: chiamato dal loader del plugin.
	 *
	 * @return void
	 */
	public function init() {
		$plugin = cbg_ap();
		if ( $plugin ) {
			$plugin->register_module( 'capabilities', $this );
		}

		// Mappa la meta-cap `cbg_ap_publish_news_type` su check concreti.
		add_filter( 'map_meta_cap', array( $this, 'map_publish_news_type' ), 10, 4 );

		// Invalida la cache quando cambiano le righe della tabella.
		add_action( 'cbg_ap_news_capability_granted', array( $this, 'invalidate_user_cache' ), 10, 1 );
		add_action( 'cbg_ap_news_capability_revoked', array( $this, 'invalidate_user_cache' ), 10, 1 );
	}

	/**
	 * Filter map_meta_cap: trasforma la richiesta della meta-cap
	 * `cbg_ap_publish_news_type` nel check effettivo.
	 *
	 * Pattern WP standard: ritorniamo l'array di "primitive caps" che
	 * l'utente DEVE possedere per superare il controllo. In aggiunta,
	 * facciamo il check dinamico sulla tabella e, se fallisce, ritorniamo
	 * `do_not_allow` (capability che nessuno ha mai).
	 *
	 * @param string[] $caps    Capability primitive richieste.
	 * @param string   $cap     Capability richiesta (es. la meta-cap).
	 * @param int      $user_id ID utente.
	 * @param array    $args    Argomenti extra; $args[0] = term_id del tipo news.
	 * @return string[]
	 */
	public function map_publish_news_type( $caps, $cap, $user_id, $args ) {
		if ( 'cbg_ap_publish_news_type' !== $cap ) {
			return $caps;
		}

		// Manca il term_id → nego per sicurezza.
		if ( empty( $args[0] ) ) {
			return array( 'do_not_allow' );
		}

		$term_id = (int) $args[0];

		// Administrator ottiene tutto.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return array( 'cbg_ap_publish_news' );
		}

		// Check sulla tabella: il pubblicatore è autorizzato per questo tipo?
		if ( ! $this->user_has_news_type_capability( $user_id, $term_id ) ) {
			return array( 'do_not_allow' );
		}

		// Richiede comunque la capability base.
		return array( 'cbg_ap_publish_news' );
	}

	/**
	 * Verifica se l'utente ha autorizzazione a pubblicare un dato tipo news.
	 *
	 * Legge dalla tabella `cbg_ap_user_news_capabilities`, con cache
	 * per-request per evitare query duplicate nello stesso bootstrap.
	 *
	 * @param int $user_id ID utente.
	 * @param int $term_id ID del termine `cbg_news_tipo`.
	 * @return bool
	 */
	public function user_has_news_type_capability( $user_id, $term_id ) {
		$user_id = (int) $user_id;
		$term_id = (int) $term_id;

		if ( $user_id <= 0 || $term_id <= 0 ) {
			return false;
		}

		$authorized = $this->get_authorized_news_types_for_user( $user_id );
		return in_array( $term_id, $authorized, true );
	}

	/**
	 * Restituisce gli ID dei tipi news autorizzati per un utente.
	 *
	 * @param int $user_id ID utente.
	 * @return int[]
	 */
	public function get_authorized_news_types_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( isset( $this->authorized_terms_cache[ $user_id ] ) ) {
			return $this->authorized_terms_cache[ $user_id ];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cbg_ap_user_news_capabilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT news_tipo_term_id FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);

		$ids = array_map( 'intval', (array) $ids );

		$this->authorized_terms_cache[ $user_id ] = $ids;
		return $ids;
	}

	/**
	 * Concede a un utente la capability di pubblicare un dato tipo news.
	 *
	 * Idempotente (ON DUPLICATE = no-op grazie all'UNIQUE KEY in schema).
	 *
	 * @param int $user_id    ID utente destinatario.
	 * @param int $term_id    Term ID di `cbg_news_tipo`.
	 * @param int $granted_by ID di chi assegna (per audit). Default user corrente.
	 * @return bool True se inserito o già presente, false su errore.
	 */
	public function grant_news_type_to_user( $user_id, $term_id, $granted_by = 0 ) {
		$user_id    = (int) $user_id;
		$term_id    = (int) $term_id;
		$granted_by = (int) $granted_by ?: get_current_user_id();

		if ( $user_id <= 0 || $term_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cbg_ap_user_news_capabilities';

		// INSERT IGNORE per gestire idempotenza con UNIQUE KEY.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				 (user_id, news_tipo_term_id, granted_by, granted_at)
				 VALUES (%d, %d, %d, %s)",
				$user_id,
				$term_id,
				$granted_by,
				current_time( 'mysql' )
			)
		);

		if ( false === $result ) {
			return false;
		}

		// Concede anche la capability base se mancante.
		$user = get_user_by( 'id', $user_id );
		if ( $user instanceof WP_User && ! user_can( $user, 'cbg_ap_publish_news' ) ) {
			$user->add_cap( 'cbg_ap_publish_news' );
		}

		do_action( 'cbg_ap_news_capability_granted', $user_id, $term_id, $granted_by );
		return true;
	}

	/**
	 * Revoca a un utente la capability di pubblicare un dato tipo news.
	 *
	 * Se l'utente perde l'ultima autorizzazione, viene RIMOSSA anche la
	 * capability base `cbg_ap_publish_news` per coerenza.
	 *
	 * @param int $user_id ID utente.
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public function revoke_news_type_from_user( $user_id, $term_id ) {
		$user_id = (int) $user_id;
		$term_id = (int) $term_id;

		if ( $user_id <= 0 || $term_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cbg_ap_user_news_capabilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete(
			$table,
			array( 'user_id' => $user_id, 'news_tipo_term_id' => $term_id ),
			array( '%d', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		// Se non restano altre autorizzazioni, rimuovi la cap base.
		$remaining = $this->get_authorized_news_types_for_user( $user_id );
		if ( empty( $remaining ) ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user instanceof WP_User ) {
				$user->remove_cap( 'cbg_ap_publish_news' );
			}
		}

		do_action( 'cbg_ap_news_capability_revoked', $user_id, $term_id );
		return true;
	}

	/**
	 * Invalida la cache in-memory per un utente.
	 *
	 * @param int $user_id ID utente.
	 * @return void
	 */
	public function invalidate_user_cache( $user_id ) {
		unset( $this->authorized_terms_cache[ (int) $user_id ] );
	}
}

// Istanziazione: stesso pattern usato per Roles (vedi class-cbg-ap-roles.php).
add_action(
	'plugins_loaded',
	static function () {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new CBG_AP_Capabilities();
			$instance->init();
		}
	},
	10
);
