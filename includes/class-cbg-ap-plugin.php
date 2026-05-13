<?php
/**
 * Plugin singleton principale.
 *
 * Orchestra il caricamento di tutti i moduli del plugin attraverso
 * il pattern singleton + loader hook centralizzato.
 *
 * I moduli sono caricati in modo difensivo (file_exists / class_exists):
 * questo permette di costruire il plugin incrementalmente senza fatal error
 * durante lo sviluppo, e di disabilitare moduli singoli via filter
 * `cbg_ap_load_module_{name}` in produzione se necessario.
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Plugin
 */
final class CBG_AP_Plugin {

	/**
	 * Istanza singleton.
	 *
	 * @var CBG_AP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Loader hook/filter centralizzato.
	 *
	 * @var CBG_AP_Loader
	 */
	protected $loader;

	/**
	 * Service container minimale: chiave → istanza modulo.
	 *
	 * @var array<string, object>
	 */
	protected $modules = array();

	/**
	 * Accessor singleton.
	 *
	 * @return CBG_AP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Costruttore privato: instanzia il loader.
	 */
	private function __construct() {
		$this->loader = new CBG_AP_Loader();
	}

	/** Disabilita clone e wakeup per garantire singleton stretto. */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Clonare il plugin non è consentito.', 'cbg-ap' ), '1.0.0' );
	}

	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Deserializzare il plugin non è consentito.', 'cbg-ap' ), '1.0.0' );
	}

	/**
	 * Bootstrap dei moduli e registrazione degli hook.
	 *
	 * @return void
	 */
	public function run() {
		$this->setup_i18n();
		$this->load_modules();
		$this->post_activation_setup();
		$this->loader->run();
	}

	/**
	 * Accessor pubblico al loader (utile a moduli che si registrano da soli).
	 *
	 * @return CBG_AP_Loader
	 */
	public function loader() {
		return $this->loader;
	}

	/**
	 * Recupera un modulo dal container.
	 *
	 * @param string $key Identificatore modulo.
	 * @return object|null
	 */
	public function module( $key ) {
		return $this->modules[ $key ] ?? null;
	}

	/**
	 * Registra un modulo nel container.
	 *
	 * @param string $key      Identificatore.
	 * @param object $instance Istanza modulo.
	 * @return void
	 */
	public function register_module( $key, $instance ) {
		$this->modules[ $key ] = $instance;
	}

	/**
	 * Setup internazionalizzazione.
	 *
	 * @return void
	 */
	protected function setup_i18n() {
		$i18n = new CBG_AP_I18n();
		// WP 6.7+ richiede load_plugin_textdomain su `init`, non più su `plugins_loaded`.
		$this->loader->add_action( 'init', $i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Carica tutti i moduli applicativi.
	 *
	 * Ogni modulo è caricato tramite `maybe_require` + filter `cbg_ap_load_module_*`
	 * per consentire opt-out granulare. I moduli che non esistono ancora vengono
	 * saltati silenziosamente: il plugin resta funzionante in fase di sviluppo.
	 *
	 * @return void
	 */
	protected function load_modules() {
		$this->maybe_require( 'db/class-cbg-ap-db.php' );
		$this->maybe_require( 'db/class-cbg-ap-migrations.php' );

		$this->maybe_require( 'roles/class-cbg-ap-roles.php' );
		$this->maybe_require( 'roles/class-cbg-ap-capabilities.php' );

		$this->maybe_require( 'auth/class-cbg-ap-auth.php' );
		$this->maybe_require( 'auth/class-cbg-ap-google-sso.php' );
		$this->maybe_require( 'auth/class-cbg-ap-totp.php' );
		$this->maybe_require( 'auth/class-cbg-ap-stepup.php' );
		$this->maybe_require( 'auth/class-cbg-ap-session-manager.php' );
		$this->maybe_require( 'auth/class-cbg-ap-device-tracker.php' );
		$this->maybe_require( 'auth/class-cbg-ap-access-log.php' );

		$this->maybe_require( 'cpt/class-cbg-ap-taxonomy-news-tipo.php' );
		$this->maybe_require( 'cpt/class-cbg-ap-cpt-news.php' );
		$this->maybe_require( 'cpt/class-cbg-ap-cpt-bacheca-sindacale.php' );
		$this->maybe_require( 'cpt/class-cbg-ap-cpt-comunicazione-ds.php' );

		$this->maybe_require( 'classi/class-cbg-ap-classi.php' );
		$this->maybe_require( 'classi/class-cbg-ap-studenti-classi.php' );
		$this->maybe_require( 'classi/class-cbg-ap-docenti-classi.php' );
		$this->maybe_require( 'classi/class-cbg-ap-csv-importer.php' );

		$this->maybe_require( 'audience/class-cbg-ap-audience-resolver.php' );
		$this->maybe_require( 'audience/class-cbg-ap-audience-matcher.php' );
		$this->maybe_require( 'audience/class-cbg-ap-audience-summarizer.php' );
		$this->maybe_require( 'audience/class-cbg-ap-audience-query.php' );

		$this->maybe_require( 'widgets/class-cbg-ap-widget-registry.php' );
		$this->maybe_require( 'widgets/class-cbg-ap-widget-renderer.php' );
		$this->maybe_require( 'widgets/class-cbg-ap-widget-base.php' );

		$this->maybe_require( 'notifications/class-cbg-ap-notifications.php' );
		$this->maybe_require( 'notifications/class-cbg-ap-notification-dispatcher.php' );
		$this->maybe_require( 'notifications/class-cbg-ap-digest-scheduler.php' );

		$this->maybe_require( 'cron/class-cbg-ap-cron.php' );
		$this->maybe_require( 'cron/class-cbg-ap-cron-tick-handler.php' );
		$this->maybe_require( 'cron/class-cbg-ap-job-registry.php' );

		$this->maybe_require( 'pwa/class-cbg-ap-pwa.php' );

		$this->maybe_require( 'rest/class-cbg-ap-rest.php' );

		$this->maybe_require( 'frontend/class-cbg-ap-router.php' );
		$this->maybe_require( 'frontend/class-cbg-ap-template-loader.php' );

		$this->maybe_require( 'admin/class-cbg-ap-admin.php' );

		$this->maybe_require( 'integrations/class-cbg-ap-integration-segreteria.php' );
		$this->maybe_require( 'integrations/class-cbg-ap-integration-gestionescuola.php' );
		$this->maybe_require( 'integrations/class-cbg-ap-integration-moduli-scuola.php' );
		$this->maybe_require( 'integrations/class-cbg-ap-integration-workspace-classes.php' );

		$this->maybe_require( 'helpers/functions-template.php' );
		$this->maybe_require( 'helpers/functions-permissions.php' );
		$this->maybe_require( 'helpers/functions-classi.php' );
		$this->maybe_require( 'helpers/functions-sanitization.php' );
	}

	/**
	 * Carica un file da `includes/` se esiste e se non è stato disabilitato via filter.
	 *
	 * @param string $relative_path Path relativo a includes/.
	 * @return void
	 */
	protected function maybe_require( $relative_path ) {
		$module_key = pathinfo( $relative_path, PATHINFO_FILENAME );

		/**
		 * Filter: consente di disabilitare il caricamento di un modulo specifico.
		 *
		 * @param bool   $load          Default true.
		 * @param string $relative_path Path relativo.
		 * @param string $module_key    Nome file senza estensione.
		 */
		$load = apply_filters( 'cbg_ap_load_module', true, $relative_path, $module_key );
		$load = apply_filters( 'cbg_ap_load_module_' . $module_key, $load );

		if ( ! $load ) {
			return;
		}

		$full = CBG_AP_INCLUDES_DIR . $relative_path;
		if ( file_exists( $full ) ) {
			require_once $full;
		}
	}

	/**
	 * Esegue setup residui marcati dall'Activator.
	 *
	 * L'attivazione si limita a creare tabelle e option; alcune operazioni
	 * (registrazione termini tassonomia, schedule Action Scheduler, ecc.)
	 * richiedono che i moduli del plugin siano già caricati. Vengono quindi
	 * differite al primo bootstrap successivo all'attivazione.
	 *
	 * @return void
	 */
	protected function post_activation_setup() {
		if ( ! get_option( 'cbg_ap_needs_post_activation_setup' ) ) {
			return;
		}

		$this->loader->add_action(
			'init',
			null,
			static function () {
				/**
				 * Hook: i moduli possono agganciarsi qui per completare
				 * setup post-attivazione (registrazione job AS, seed termini, ecc.).
				 */
				do_action( 'cbg_ap_post_activation_setup' );

				delete_option( 'cbg_ap_needs_post_activation_setup' );
			},
			999
		);
	}
}
