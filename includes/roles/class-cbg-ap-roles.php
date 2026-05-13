<?php
/**
 * Registrazione e sincronizzazione dei ruoli custom CBG.
 *
 * Idempotente: al bootstrap controlla `cbg_ap_roles_version` contro
 * `CBG_AP_ROLES_VERSION`. Se diversa, riapplica le definizioni di
 * `role-definitions.php` ai ruoli esistenti e crea quelli mancanti.
 *
 * Cumulabilità: WordPress supporta nativamente più ruoli per utente via
 * `$user->add_role($role)`. Le capability di tutti i ruoli si sommano
 * (union); `current_user_can()` ritorna true se anche solo uno dei ruoli
 * concede la capability. Questo soddisfa il vincolo §2.1 del documento
 * di requisiti ("un singolo utente può cumulare più ruoli").
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Roles
 */
class CBG_AP_Roles {

	/**
	 * Definizioni caricate da role-definitions.php.
	 *
	 * @var array|null
	 */
	protected $definitions = null;

	/**
	 * Hook di entry point: chiamato dal loader del plugin.
	 *
	 * @return void
	 */
	public function init() {
		$plugin = cbg_ap();
		if ( $plugin ) {
			$plugin->register_module( 'roles', $this );
		}

		// Sincronizzazione ruoli: gira UNA volta al primo bootstrap dopo install
		// o dopo bump di CBG_AP_ROLES_VERSION. Su tutti gli altri bootstrap è
		// no-op (early return su version match).
		add_action( 'init', array( $this, 'maybe_sync_roles' ), 1 );
	}

	/**
	 * Carica e cache le definizioni dichiarative.
	 *
	 * @return array
	 */
	public function get_definitions() {
		if ( null === $this->definitions ) {
			$this->definitions = require CBG_AP_INCLUDES_DIR . 'roles/role-definitions.php';
		}
		return $this->definitions;
	}

	/**
	 * Restituisce l'elenco delle capability custom CBG.
	 *
	 * @return array<string, string> Mapping cap_slug => human_label
	 */
	public function get_capabilities() {
		$defs = $this->get_definitions();
		return isset( $defs['capabilities'] ) ? $defs['capabilities'] : array();
	}

	/**
	 * Restituisce gli slug dei ruoli custom CBG.
	 *
	 * @return string[]
	 */
	public function get_role_slugs() {
		$defs = $this->get_definitions();
		return isset( $defs['roles'] ) ? array_keys( $defs['roles'] ) : array();
	}

	/**
	 * Verifica versione corrente vs target; se diversa, sincronizza.
	 *
	 * @return void
	 */
	public function maybe_sync_roles() {
		$installed = get_option( 'cbg_ap_roles_version', '0.0.0' );

		if ( version_compare( $installed, CBG_AP_ROLES_VERSION, '>=' ) ) {
			return; // Già allineati.
		}

		$this->sync_roles();
		update_option( 'cbg_ap_roles_version', CBG_AP_ROLES_VERSION, false );
	}

	/**
	 * Forza la sincronizzazione (chiamata anche dall'Activator).
	 *
	 * Crea i ruoli mancanti, aggiorna quelli esistenti, concede tutte
	 * le capability CBG ad administrator. NON cancella ruoli o capability
	 * — la rimozione avviene solo via uninstall.
	 *
	 * @return void
	 */
	public function sync_roles() {
		$defs = $this->get_definitions();

		if ( ! isset( $defs['roles'] ) ) {
			return;
		}

		foreach ( $defs['roles'] as $role_slug => $role_def ) {
			$this->register_or_update_role( $role_slug, $role_def );
		}

		// Garantisce ad administrator tutte le capability CBG.
		$this->grant_admin_capabilities();

		/**
		 * Fired dopo la sincronizzazione dei ruoli.
		 *
		 * @param string $version Versione appena applicata.
		 */
		do_action( 'cbg_ap_roles_synced', CBG_AP_ROLES_VERSION );
	}

	/**
	 * Registra un ruolo o ne aggiorna le capability.
	 *
	 * Strategia:
	 *  - Se il ruolo non esiste, lo crea con le cap di default (eventualmente
	 *    ereditate da `inherits_from`).
	 *  - Se esiste già, NON lo ricrea (preserva eventuali cap aggiunte
	 *    manualmente dall'admin), ma applica le cap dichiarate qui via
	 *    add_cap/remove_cap.
	 *
	 * @param string $slug    Slug ruolo (es. 'cbg_docente').
	 * @param array  $def     Definizione: label, inherits_from, capabilities.
	 * @return void
	 */
	protected function register_or_update_role( $slug, $def ) {
		$role = get_role( $slug );

		// Capability iniziali: union tra quelle ereditate e quelle dichiarate.
		$initial_caps = array();

		if ( ! empty( $def['inherits_from'] ) ) {
			$parent = get_role( $def['inherits_from'] );
			if ( $parent ) {
				$initial_caps = $parent->capabilities;
			}
		}

		if ( ! empty( $def['capabilities'] ) ) {
			foreach ( $def['capabilities'] as $cap => $granted ) {
				$initial_caps[ $cap ] = (bool) $granted;
			}
		}

		if ( ! $role ) {
			// Creazione ex-novo.
			add_role( $slug, $def['label'], $initial_caps );
			return;
		}

		// Ruolo esistente: applica solo le capability dichiarate in questo file,
		// senza toccare quelle aggiunte dall'amministratore.
		if ( ! empty( $def['capabilities'] ) ) {
			foreach ( $def['capabilities'] as $cap => $granted ) {
				if ( $granted ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Garantisce ad administrator tutte le capability custom CBG.
	 *
	 * @return void
	 */
	protected function grant_admin_capabilities() {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		$defs = $this->get_definitions();
		$caps = isset( $defs['administrator_capabilities'] ) ? $defs['administrator_capabilities'] : array();

		foreach ( $caps as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Verifica se uno slug è un ruolo custom CBG.
	 *
	 * @param string $role_slug Slug ruolo.
	 * @return bool
	 */
	public function is_cbg_role( $role_slug ) {
		return in_array( $role_slug, $this->get_role_slugs(), true );
	}

	/**
	 * Restituisce i ruoli CBG di un utente (esclusi `administrator`, `editor`, ecc.).
	 *
	 * Utile per UI dove vogliamo mostrare solo l'identità "CBG" dell'utente.
	 *
	 * @param int|WP_User $user User ID o oggetto.
	 * @return string[]
	 */
	public function get_user_cbg_roles( $user ) {
		if ( is_numeric( $user ) ) {
			$user = get_user_by( 'id', (int) $user );
		}
		if ( ! $user instanceof WP_User ) {
			return array();
		}
		return array_values( array_intersect( $user->roles, $this->get_role_slugs() ) );
	}
}

// Istanziazione: agganciata a `plugins_loaded` priorità 10 (il bootstrap principale
// gira a priorità 5, quindi cbg_ap() è già disponibile qui). Singolare: `static`
// flag previene doppia istanziazione se il file viene caricato due volte.
add_action(
	'plugins_loaded',
	static function () {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new CBG_AP_Roles();
			$instance->init();
		}
	},
	10
);
