<?php
/**
 * Loader hook/filter centralizzato.
 *
 * Raccoglie le registrazioni di action/filter dei moduli e le applica
 * in un'unica passata dopo l'inizializzazione. Pattern derivato dal
 * WordPress Plugin Boilerplate, adattato al DB WP Plugin Standard.
 *
 * @package CBG_AP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBG_AP_Loader
 *
 * Non è un singleton: ogni modulo può istanziarne uno proprio se vuole
 * isolare le proprie registrazioni; il plugin principale ne usa uno globale.
 */
class CBG_AP_Loader {

	/**
	 * Action queue.
	 *
	 * @var array<int, array{hook:string, component:?object, callback:callable|string, priority:int, accepted_args:int}>
	 */
	protected $actions = array();

	/**
	 * Filter queue.
	 *
	 * @var array<int, array{hook:string, component:?object, callback:callable|string, priority:int, accepted_args:int}>
	 */
	protected $filters = array();

	/**
	 * Shortcode queue.
	 *
	 * @var array<int, array{tag:string, component:?object, callback:callable|string}>
	 */
	protected $shortcodes = array();

	/**
	 * Aggiunge un'action alla coda.
	 *
	 * @param string         $hook          Nome dell'action.
	 * @param object|null    $component     Oggetto su cui invocare il metodo, o null per callable.
	 * @param callable|string $callback     Metodo (se $component) o callable libero.
	 * @param int            $priority      Priorità WordPress.
	 * @param int            $accepted_args Numero argomenti.
	 * @return void
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Aggiunge un filter alla coda.
	 *
	 * @param string         $hook          Nome del filter.
	 * @param object|null    $component     Oggetto.
	 * @param callable|string $callback     Metodo o callable.
	 * @param int            $priority      Priorità.
	 * @param int            $accepted_args Numero argomenti.
	 * @return void
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Aggiunge uno shortcode alla coda.
	 *
	 * @param string         $tag       Tag dello shortcode.
	 * @param object|null    $component Oggetto.
	 * @param callable|string $callback Metodo o callable.
	 * @return void
	 */
	public function add_shortcode( $tag, $component, $callback ) {
		$this->shortcodes[] = array(
			'tag'       => $tag,
			'component' => $component,
			'callback'  => $callback,
		);
	}

	/**
	 * Helper interno di accodamento.
	 *
	 * @param array          $queue         Coda corrente.
	 * @param string         $hook          Hook.
	 * @param object|null    $component     Oggetto.
	 * @param callable|string $callback     Callback.
	 * @param int            $priority      Priorità.
	 * @param int            $accepted_args Argomenti.
	 * @return array
	 */
	protected function add( $queue, $hook, $component, $callback, $priority, $accepted_args ) {
		$queue[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $queue;
	}

	/**
	 * Risolve un callback registrato in qualcosa che WordPress può chiamare.
	 *
	 * @param object|null    $component Oggetto, o null se callable libero.
	 * @param callable|string $callback Metodo (nome) o callable.
	 * @return callable
	 */
	protected function resolve_callback( $component, $callback ) {
		if ( null !== $component ) {
			return array( $component, $callback );
		}
		return $callback;
	}

	/**
	 * Registra tutti gli hook accumulati.
	 *
	 * @return void
	 */
	public function run() {
		foreach ( $this->filters as $h ) {
			add_filter( $h['hook'], $this->resolve_callback( $h['component'], $h['callback'] ), $h['priority'], $h['accepted_args'] );
		}

		foreach ( $this->actions as $h ) {
			add_action( $h['hook'], $this->resolve_callback( $h['component'], $h['callback'] ), $h['priority'], $h['accepted_args'] );
		}

		foreach ( $this->shortcodes as $s ) {
			add_shortcode( $s['tag'], $this->resolve_callback( $s['component'], $s['callback'] ) );
		}
	}
}
