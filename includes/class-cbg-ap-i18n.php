<?php
/**
 * Internationalization handler.
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_I18n
 *
 * Carica il text domain da /languages. Le traduzioni JSON per gli script
 * frontend andranno caricate separatamente nei moduli che enqueueano script.
 */
class CBG_AP_I18n {

	/**
	 * Carica il text domain `cbg-ap`.
	 *
	 * Hook: init (default priorità 10) — segue le raccomandazioni WP 6.7+
	 * dopo cui il caricamento su `plugins_loaded` genera notice di deprecation.
	 *
	 * @return void
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			CBG_AP_TEXT_DOMAIN,
			false,
			dirname( CBG_AP_PLUGIN_BASE ) . '/languages/'
		);
	}
}
