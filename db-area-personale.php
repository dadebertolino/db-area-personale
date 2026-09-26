<?php
/**
 * Plugin Name:       DB Area Personale
 * Plugin URI:        https://www.davidebertolino.it/progetti/db-area-personale/
 * Description:       Portale interno unificato per il personale e gli studenti dell'IIS Cigna-Baruffi-Garelli. Sostituisce la bacheca WordPress per ruoli non amministrativi con una dashboard a widget, integra SSO Google Workspace, anagrafica classi, news interne, bacheca sindacale, approvazioni e notifiche.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Davide Bertolino
 * Author URI:        https://www.davidebertolino.it
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cbg-ap
 * Domain Path:       /languages
 * Network:           false
 *
 * @package CBG_AP
 */

/*
 * Privacy capabilities (per references/PRIVACY-INTEGRATION.md):
 *  - Personal data:        YES — log accessi (wp_cbg_ap_access_log: user_id, username,
 *                          IP, user agent), preferenze/layout/conferme di lettura,
 *                          notifiche, dispositivi noti, stato 2FA, anagrafica classi,
 *                          user meta cbg_ap_*
 *  - Third-party scripts:  NO — nessuno script esterno sul frontend (l'SSO Google è
 *                          un redirect OIDC server-side)
 *  - User consent:         NO — nessun consenso raccolto (basi giuridiche 6.1.b/c/e/f)
 *  - DSAR-aware:           YES — CBG_AP_Privacy_DSAR (3 exporter + 3 eraser, doppio canale)
 *  - Hub-aware:            YES — CBG_AP_Privacy_Declarations su dbph_processing_register
 *                          (+ legacy dbseo_processing_register), filter dbph_dsar_available
 *  - Retention:            pulizia giornaliera log accessi (CBG_AP_Access_Log_Retention)
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* -----------------------------------------------------------------------------
 * Costanti del plugin
 * -------------------------------------------------------------------------- */

define( 'CBG_AP_VERSION',      '1.1.0' );
define( 'CBG_AP_DB_VERSION',   '1.0.0' );
define( 'CBG_AP_MIN_PHP',      '8.1' );
define( 'CBG_AP_MIN_WP',       '6.0' );

define( 'CBG_AP_PLUGIN_FILE',  __FILE__ );
define( 'CBG_AP_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'CBG_AP_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'CBG_AP_PLUGIN_BASE',  plugin_basename( __FILE__ ) );

define( 'CBG_AP_INCLUDES_DIR', CBG_AP_PLUGIN_DIR . 'includes/' );
define( 'CBG_AP_TEMPLATES_DIR', CBG_AP_PLUGIN_DIR . 'templates/' );
define( 'CBG_AP_ASSETS_URL',   CBG_AP_PLUGIN_URL . 'assets/dist/' );

define( 'CBG_AP_TEXT_DOMAIN',  'cbg-ap' );
define( 'CBG_AP_REST_NS',      'cbg-ap/v1' );
define( 'CBG_AP_FRONT_BASE',   'area-personale' );

// Marker DSAR (Capability 1). L'Hub riconosce i marker solo per prefissi noti:
// per questo prefisso la disponibilità è segnalata anche dal filter
// `dbph_dsar_available` (vedi CBG_AP_Privacy_DSAR::init()).
define( 'CBG_AP_DSAR_AVAILABLE', true );

/* -----------------------------------------------------------------------------
 * GitHub Auto-Updater (componente condiviso DB, guardato: il file può mancare
 * in build personalizzate e la classe può essere già definita da un altro plugin DB)
 * -------------------------------------------------------------------------- */

if ( file_exists( CBG_AP_INCLUDES_DIR . 'class-updater.php' ) ) {
	require_once CBG_AP_INCLUDES_DIR . 'class-updater.php';
}
if ( class_exists( 'DB_GitHub_Updater' ) ) {
	new DB_GitHub_Updater( __FILE__, 'dadebertolino', 'db-area-personale' );
}

/**
 * Registra il design system admin condiviso (db-admin-ui.css).
 *
 * Handle condiviso `db-admin-ui`: se un altro plugin DB lo ha già registrato
 * resta valido il primo (il file è identico). Accodato solo nelle schermate
 * admin del plugin (id schermata contenente `cbg-ap` / `cbg_ap`); i CSS
 * specifici del plugin devono dichiararlo come dipendenza.
 *
 * @param string $hook_suffix Hook della schermata admin.
 * @return void
 */
function cbg_ap_register_admin_ui( $hook_suffix ) {
	if ( ! wp_style_is( 'db-admin-ui', 'registered' ) ) {
		wp_register_style( 'db-admin-ui', CBG_AP_PLUGIN_URL . 'assets/css/db-admin-ui.css', array(), CBG_AP_VERSION );
	}

	if ( false !== strpos( (string) $hook_suffix, 'cbg-ap' ) || false !== strpos( (string) $hook_suffix, 'cbg_ap' ) ) {
		wp_enqueue_style( 'db-admin-ui' );
	}
}
add_action( 'admin_enqueue_scripts', 'cbg_ap_register_admin_ui', 5 );

/* -----------------------------------------------------------------------------
 * Compatibilità ambiente
 * -------------------------------------------------------------------------- */

/**
 * Verifica i requisiti minimi prima di caricare il plugin.
 *
 * Se i requisiti non sono soddisfatti, il plugin viene disattivato e mostrato
 * un avviso in admin. Mai fatale: meglio degradare con messaggio che white-screen.
 *
 * @return bool True se l'ambiente è compatibile.
 */
function cbg_ap_check_environment() {
	$errors = array();

	if ( version_compare( PHP_VERSION, CBG_AP_MIN_PHP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: PHP version required, 2: PHP version installed */
			__( 'DB Area Personale richiede PHP %1$s o superiore. Versione corrente: %2$s.', 'cbg-ap' ),
			CBG_AP_MIN_PHP,
			PHP_VERSION
		);
	}

	global $wp_version;
	if ( version_compare( $wp_version, CBG_AP_MIN_WP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: WP version required, 2: WP version installed */
			__( 'DB Area Personale richiede WordPress %1$s o superiore. Versione corrente: %2$s.', 'cbg-ap' ),
			CBG_AP_MIN_WP,
			$wp_version
		);
	}

	if ( ! empty( $errors ) ) {
		add_action(
			'admin_notices',
			static function () use ( $errors ) {
				echo '<div class="notice notice-error"><p><strong>DB Area Personale</strong></p><ul>';
				foreach ( $errors as $err ) {
					echo '<li>' . esc_html( $err ) . '</li>';
				}
				echo '</ul></div>';
			}
		);

		// Auto-disattivazione se attivo: previene errori fatali al successivo caricamento.
		add_action(
			'admin_init',
			static function () {
				if ( is_plugin_active( CBG_AP_PLUGIN_BASE ) ) {
					deactivate_plugins( CBG_AP_PLUGIN_BASE );
					// Sopprime il messaggio "Plugin activated" che altrimenti appare.
					if ( isset( $_GET['activate'] ) ) {
						unset( $_GET['activate'] );
					}
				}
			}
		);

		return false;
	}

	return true;
}

/**
 * Verifica la coesistenza col tema istituzionale (Design Scuole Italia).
 *
 * NON BLOCCANTE: il plugin funziona anche con altri temi, ma il design
 * dell'area personale e la modale di accesso sono pensati per coesistere
 * con il tema AgID delle scuole. In sua assenza emettiamo un avviso
 * informativo lasciando all'admin la decisione.
 *
 * L'avviso è "dismissable" con sopravvivenza in user meta:
 * `cbg_ap_dismissed_theme_notice = 1`.
 *
 * Riferimento: docs/theme-coexistence.md.
 *
 * @return void
 */
function cbg_ap_check_theme_compatibility() {
	// Eseguito solo in admin per non disturbare il frontend.
	if ( ! is_admin() ) {
		return;
	}

	$theme  = wp_get_theme();
	$parent = $theme->parent();

	// L'ID univoco del tema parent è il TextDomain `design_scuole_italia`.
	// Verifichiamo sia sul tema attivo che sull'eventuale parent (in caso di child theme).
	$is_scuole_theme = false;
	foreach ( array_filter( array( $theme, $parent ) ) as $t ) {
		if ( 'design_scuole_italia' === $t->get( 'TextDomain' )
			|| false !== stripos( $t->get_stylesheet(), 'design-scuole-wordpress-theme' )
		) {
			$is_scuole_theme = true;
			break;
		}
	}

	if ( $is_scuole_theme ) {
		return;
	}

	add_action(
		'admin_notices',
		static function () {
			$user_id = get_current_user_id();
			if ( $user_id && get_user_meta( $user_id, 'cbg_ap_dismissed_theme_notice', true ) ) {
				return;
			}
			?>
			<div class="notice notice-warning is-dismissible" data-cbg-ap-notice="theme">
				<p>
					<strong><?php esc_html_e( 'DB Area Personale', 'cbg-ap' ); ?></strong> —
					<?php
					esc_html_e(
						'il plugin è progettato per coesistere con il tema istituzionale Design Scuole Italia (AgID). Il tema attivo non sembra essere quello: la dashboard funzionerà comunque, ma la modale di accesso e l\'integrazione con la struttura pubblica del sito potrebbero non comportarsi come previsto.',
						'cbg-ap'
					);
					?>
				</p>
				<p>
					<a href="https://github.com/italia/design-scuole-wordpress-theme" target="_blank" rel="noopener">
						<?php esc_html_e( 'Repository del tema', 'cbg-ap' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	);

	// Handler dismiss via AJAX. Registriamo solo qui per evitare carico inutile altrove.
	add_action(
		'wp_ajax_cbg_ap_dismiss_theme_notice',
		static function () {
			check_ajax_referer( 'cbg_ap_dismiss_theme_notice', 'nonce' );
			update_user_meta( get_current_user_id(), 'cbg_ap_dismissed_theme_notice', 1 );
			wp_send_json_success();
		}
	);
}

/* -----------------------------------------------------------------------------
 * Autoload e bootstrap
 * -------------------------------------------------------------------------- */

/**
 * Carica le classi core necessarie all'attivazione e al bootstrap.
 *
 * In produzione si userà l'autoload PSR-4 di Composer (composer.json già predisposto);
 * qui carichiamo manualmente i file core per garantire il funzionamento anche
 * prima di `composer install` (es. installazione manuale via ZIP).
 *
 * @return void
 */
function cbg_ap_load_core() {
	require_once CBG_AP_INCLUDES_DIR . 'class-cbg-ap-loader.php';
	require_once CBG_AP_INCLUDES_DIR . 'class-cbg-ap-i18n.php';
	require_once CBG_AP_INCLUDES_DIR . 'class-cbg-ap-activator.php';
	require_once CBG_AP_INCLUDES_DIR . 'class-cbg-ap-deactivator.php';
	require_once CBG_AP_INCLUDES_DIR . 'class-cbg-ap-plugin.php';
}

/* -----------------------------------------------------------------------------
 * Hook di attivazione / disattivazione
 *
 * Registrati PRIMA del check ambiente: WP li invoca solo all'azione utente,
 * e l'Activator deve sempre essere richiamabile per il primo install.
 * -------------------------------------------------------------------------- */

register_activation_hook(
	__FILE__,
	static function () {
		cbg_ap_load_core();
		CBG_AP_Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		cbg_ap_load_core();
		CBG_AP_Deactivator::deactivate();
	}
);

/* -----------------------------------------------------------------------------
 * Avvio plugin
 * -------------------------------------------------------------------------- */

/**
 * Bootstrap principale. Eseguito su `plugins_loaded` priorità 5
 * per dare a moduli dipendenti (es. Action Scheduler) il tempo di registrarsi
 * e ai plugin custom dell'istituto di esporre i propri hook.
 *
 * @return void
 */
function cbg_ap_bootstrap() {
	if ( ! cbg_ap_check_environment() ) {
		return;
	}

	cbg_ap_load_core();
	CBG_AP_Plugin::instance()->run();

	// Check coesistenza tema (non bloccante).
	cbg_ap_check_theme_compatibility();
}
add_action( 'plugins_loaded', 'cbg_ap_bootstrap', 5 );

/**
 * Accessor globale all'istanza del plugin, utile a integrazioni esterne.
 *
 * @return CBG_AP_Plugin|null
 */
function cbg_ap() {
	if ( ! class_exists( 'CBG_AP_Plugin' ) ) {
		return null;
	}
	return CBG_AP_Plugin::instance();
}
