<?php
/**
 * Helper di permission check.
 *
 * API pubblica del plugin per check di permessi, pensata per essere
 * usata da template, integrazioni con altri plugin, e moduli interni.
 *
 * Wrappa la complessità di:
 *   - check capability standard WP
 *   - check capability dinamiche per tipo news (via meta-cap)
 *   - check ruoli cumulativi
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/* -----------------------------------------------------------------------------
 * Capability statiche
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'cbg_ap_user_can_access_area' ) ) {
	/**
	 * L'utente può accedere a /area-personale/* ?
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_user_can_access_area( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return user_can( $user_id, 'cbg_ap_access_area_personale' );
	}
}

if ( ! function_exists( 'cbg_ap_user_can_publish_bacheca_sindacale' ) ) {
	/**
	 * L'utente può pubblicare in bacheca sindacale?
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_user_can_publish_bacheca_sindacale( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return user_can( $user_id, 'cbg_ap_publish_bacheca_sindacale' );
	}
}

if ( ! function_exists( 'cbg_ap_user_can_publish_comunicazione_ds' ) ) {
	/**
	 * L'utente può pubblicare comunicazioni del DS?
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_user_can_publish_comunicazione_ds( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return user_can( $user_id, 'cbg_ap_publish_comunicazione_ds' );
	}
}

if ( ! function_exists( 'cbg_ap_user_can_approve_news' ) ) {
	/**
	 * L'utente può approvare news in revisione (cap del DS)?
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_user_can_approve_news( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return user_can( $user_id, 'cbg_ap_approve_news' );
	}
}

/* -----------------------------------------------------------------------------
 * Capability dinamiche per tipo news (vedi §3.2 documento requisiti)
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'cbg_ap_user_can_publish_news_type' ) ) {
	/**
	 * L'utente può pubblicare news di un dato tipo?
	 *
	 * Implementa il check granulare data-driven della tabella
	 * `cbg_ap_user_news_capabilities`. Usa la meta-cap
	 * `cbg_ap_publish_news_type` registrata da CBG_AP_Capabilities.
	 *
	 * @param int $term_id ID del termine `cbg_news_tipo`.
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_user_can_publish_news_type( $term_id, $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return user_can( $user_id, 'cbg_ap_publish_news_type', (int) $term_id );
	}
}

if ( ! function_exists( 'cbg_ap_user_authorized_news_types' ) ) {
	/**
	 * Tipi news che l'utente è autorizzato a pubblicare.
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return int[] Array di term_id.
	 */
	function cbg_ap_user_authorized_news_types( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$caps    = cbg_ap()->module( 'capabilities' );
		if ( ! $caps ) {
			return array();
		}
		return $caps->get_authorized_news_types_for_user( $user_id );
	}
}

/* -----------------------------------------------------------------------------
 * Ruoli — check cumulativi
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'cbg_ap_user_has_role' ) ) {
	/**
	 * L'utente ha il ruolo CBG indicato? (anche se ne cumula altri)
	 *
	 * @param string $role_slug Es. 'cbg_docente'.
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_user_has_role( $role_slug, $user_id = null ) {
		$user = $user_id ? get_user_by( 'id', $user_id ) : wp_get_current_user();
		if ( ! $user instanceof WP_User || empty( $user->ID ) ) {
			return false;
		}
		return in_array( $role_slug, (array) $user->roles, true );
	}
}

if ( ! function_exists( 'cbg_ap_user_has_any_role' ) ) {
	/**
	 * L'utente ha almeno uno dei ruoli indicati?
	 *
	 * @param string[] $role_slugs Es. ['cbg_docente', 'cbg_dirigente'].
	 * @param int|null $user_id    Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_user_has_any_role( $role_slugs, $user_id = null ) {
		$user = $user_id ? get_user_by( 'id', $user_id ) : wp_get_current_user();
		if ( ! $user instanceof WP_User || empty( $user->ID ) ) {
			return false;
		}
		return count( array_intersect( (array) $role_slugs, (array) $user->roles ) ) > 0;
	}
}

if ( ! function_exists( 'cbg_ap_user_cbg_roles' ) ) {
	/**
	 * Ruoli CBG (filtrati `cbg_*`) di un utente.
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return string[]
	 */
	function cbg_ap_user_cbg_roles( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$roles   = cbg_ap()->module( 'roles' );
		if ( ! $roles ) {
			return array();
		}
		return $roles->get_user_cbg_roles( $user_id );
	}
}

if ( ! function_exists( 'cbg_ap_is_docente' ) ) {
	/**
	 * Shortcut: utente è docente (anche con altri ruoli cumulativi).
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_is_docente( $user_id = null ) {
		return cbg_ap_user_has_any_role(
			array( 'cbg_docente', 'cbg_docente_pubblicatore' ),
			$user_id
		);
	}
}

if ( ! function_exists( 'cbg_ap_is_studente' ) ) {
	/**
	 * Shortcut: utente è studente.
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_is_studente( $user_id = null ) {
		return cbg_ap_user_has_role( 'cbg_studente', $user_id );
	}
}

if ( ! function_exists( 'cbg_ap_is_dirigente' ) ) {
	/**
	 * Shortcut: utente è Dirigente.
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_is_dirigente( $user_id = null ) {
		return cbg_ap_user_has_role( 'cbg_dirigente', $user_id );
	}
}

if ( ! function_exists( 'cbg_ap_is_dsga' ) ) {
	/**
	 * Shortcut: utente è DSGA.
	 *
	 * @param int|null $user_id Default: utente corrente.
	 * @return bool
	 */
	function cbg_ap_is_dsga( $user_id = null ) {
		return cbg_ap_user_has_role( 'cbg_dsga', $user_id );
	}
}

if ( ! function_exists( 'cbg_ap_require_capability' ) ) {
	/**
	 * Verifica capability e termina con 403 se mancante.
	 *
	 * Helper per uso in endpoint frontend e admin. Per endpoint REST
	 * preferire `permission_callback`.
	 *
	 * @param string $capability Capability richiesta.
	 * @param mixed  ...$args    Argomenti aggiuntivi per meta-cap.
	 * @return void
	 */
	function cbg_ap_require_capability( $capability, ...$args ) {
		if ( ! current_user_can( $capability, ...$args ) ) {
			wp_die(
				esc_html__( 'Non hai i permessi necessari per questa operazione.', 'cbg-ap' ),
				esc_html__( 'Accesso negato', 'cbg-ap' ),
				array( 'response' => 403 )
			);
		}
	}
}
