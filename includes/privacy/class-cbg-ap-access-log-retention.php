<?php
/**
 * Conservazione limitata del log accessi (art. 5.1.e GDPR).
 *
 * La tabella `{prefix}cbg_ap_access_log` contiene user_id, username, IP e
 * user agent di ogni evento di autenticazione. Questa classe cancella ogni
 * giorno le righe più vecchie della finestra di conservazione configurata.
 *
 * Finestra: option `cbg_ap_access_log_retention_months` (creata
 * dall'Activator, default 12 mesi come da documento di requisiti),
 * sovrascrivibile con il filter `cbg_ap_access_log_retention_months`.
 * Un valore 0 disattiva la pulizia (sconsigliato: va dichiarato).
 *
 * Schedulazione: evento WP-Cron giornaliero `cbg_ap_cleanup_access_log`,
 * programmato in modo idempotente su `init` e rimosso da Deactivator e
 * uninstall.php. Con `DISABLE_WP_CRON` il cron di sistema deve invocare
 * anche `wp-cron.php` finché il job non sarà migrato su Action Scheduler.
 *
 * @package CBG_AP
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Access_Log_Retention
 */
class CBG_AP_Access_Log_Retention {

	/**
	 * Hook dell'evento cron (già riservato in Deactivator/uninstall).
	 */
	const HOOK = 'cbg_ap_cleanup_access_log';

	/**
	 * Default in mesi se l'option non esiste.
	 */
	const DEFAULT_MONTHS = 12;

	/**
	 * Righe cancellate per singola query (evita lock lunghi).
	 */
	const BATCH_SIZE = 5000;

	/**
	 * Tetto di batch per singola esecuzione (5000 x 100 = 500.000 righe).
	 */
	const MAX_BATCHES = 100;

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Programma l'evento giornaliero se non è già in coda (idempotente).
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Rimuove l'evento (usato da Deactivator e uninstall).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Finestra di conservazione in mesi (0 = pulizia disattivata).
	 *
	 * @return int
	 */
	public static function retention_months() {
		$months = (int) get_option( 'cbg_ap_access_log_retention_months', self::DEFAULT_MONTHS );

		/**
		 * Filter: mesi di conservazione del log accessi.
		 *
		 * @since 1.1.0
		 * @param int $months Mesi (0 = nessuna pulizia automatica).
		 */
		$months = (int) apply_filters( 'cbg_ap_access_log_retention_months', $months );

		return max( 0, $months );
	}

	/**
	 * Data limite (ora del sito, formato MySQL): righe precedenti vengono cancellate.
	 *
	 * @param int $months Mesi di conservazione.
	 * @return string
	 */
	public static function cutoff( $months ) {
		return current_datetime()->modify( sprintf( '-%d months', (int) $months ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Esegue la pulizia. Ritorna il numero di righe cancellate.
	 *
	 * @return int
	 */
	public static function run() {
		global $wpdb;

		$months = self::retention_months();
		if ( 0 === $months ) {
			return 0;
		}

		$table   = $wpdb->prefix . 'cbg_ap_access_log';
		$cutoff  = self::cutoff( $months );
		$deleted = 0;

		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff,
					self::BATCH_SIZE
				)
			);

			if ( false === $rows ) {
				break; // Tabella assente o errore DB: riproveremo domani.
			}

			$deleted += (int) $rows;

			if ( $rows < self::BATCH_SIZE ) {
				break;
			}
		}

		/**
		 * Action: pulizia log accessi completata.
		 *
		 * @since 1.1.0
		 * @param int    $deleted Righe cancellate.
		 * @param string $cutoff  Data limite applicata.
		 */
		do_action( 'cbg_ap_access_log_cleaned', $deleted, $cutoff );

		return $deleted;
	}
}

add_action( 'plugins_loaded', array( 'CBG_AP_Access_Log_Retention', 'init' ), 10 );
