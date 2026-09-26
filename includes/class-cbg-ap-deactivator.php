<?php
/**
 * Deactivator: operazioni alla disattivazione del plugin.
 *
 * Principio: la disattivazione è REVERSIBILE. Non vengono cancellati
 * dati, tabelle, ruoli, capability. Solo schedulazioni e cache vengono
 * pulite per evitare lavoro inutile mentre il plugin è inattivo.
 *
 * La cancellazione completa avviene solo via uninstall.php (eliminazione plugin).
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Deactivator
 */
class CBG_AP_Deactivator {

	/**
	 * Entry point invocato da register_deactivation_hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::unschedule_jobs();
		self::clear_caches();
		flush_rewrite_rules();
	}

	/**
	 * Rimuove tutti i job ricorrenti registrati su Action Scheduler.
	 *
	 * Sicuro anche se Action Scheduler non è caricato (verifica function_exists).
	 * I job già in coda per esecuzione singola vengono lasciati: in genere
	 * sono notifiche da spedire, ed è giusto che partano comunque al prossimo tick
	 * se il plugin viene riattivato a breve.
	 *
	 * @return void
	 */
	protected static function unschedule_jobs() {
		// Pulizia log accessi (1.1.0): evento WP-Cron, indipendente da Action Scheduler.
		wp_clear_scheduled_hook( 'cbg_ap_cleanup_access_log' );

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$recurring_hooks = array(
			'cbg_ap_process_notification_queue',
			'cbg_ap_digest_tick',
			'cbg_ap_sync_workspace_groups',
			'cbg_ap_sync_workspace_classes',
			'cbg_ap_sync_gestionescuola',
			'cbg_ap_cleanup_access_log',
			'cbg_ap_cleanup_expired_sessions',
		);

		foreach ( $recurring_hooks as $hook ) {
			as_unschedule_all_actions( $hook );
		}
	}

	/**
	 * Pulisce le cache transient del plugin.
	 *
	 * @return void
	 */
	protected static function clear_caches() {
		global $wpdb;

		// Tutti i transient con prefisso `cbg_ap_`. Usiamo query diretta
		// perché WP non offre un'API "delete by prefix".
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_cbg_ap_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_cbg_ap_' ) . '%'
			)
		);
		// phpcs:enable
	}
}
