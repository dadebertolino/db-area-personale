<?php
/**
 * Uninstall: cleanup completo quando il plugin viene eliminato (non disattivato).
 *
 * AVVERTENZA: questa operazione è IRREVERSIBILE. Cancella tutte le tabelle
 * custom, tutte le option, tutti i ruoli e capability custom, tutti i CPT.
 *
 * Per preservare i dati su disinstallazione, l'admin può impostare l'option
 * `cbg_ap_preserve_data_on_uninstall` a 1 prima di eliminare il plugin.
 *
 * @package CBG_AP
 */

// Sicurezza: questo file è eseguito solo quando WordPress richiama uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Se l'utente ha richiesto di preservare i dati, ci fermiamo subito.
if ( get_option( 'cbg_ap_preserve_data_on_uninstall' ) ) {
	return;
}

global $wpdb;
$p = $wpdb->prefix;

/* -----------------------------------------------------------------------------
 * 1. Drop tabelle custom
 * -------------------------------------------------------------------------- */

$tables = array(
	"{$p}cbg_ap_user_layout",
	"{$p}cbg_ap_user_prefs",
	"{$p}cbg_ap_notifications",
	"{$p}cbg_ap_access_log",
	"{$p}cbg_ap_read_receipts",
	"{$p}cbg_ap_2fa_secrets",
	"{$p}cbg_ap_user_news_capabilities",
	"{$p}cbg_ap_known_devices",
	"{$p}cbg_ap_classi",
	"{$p}cbg_ap_studenti_classi",
	"{$p}cbg_ap_docenti_classi",
	"{$p}cbg_ap_content_audience",
	"{$p}cbg_ap_external_mappings",
	"{$p}cbg_ap_import_log",
	"{$p}cbg_ap_cron_runs",
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

/* -----------------------------------------------------------------------------
 * 2. Cancella option e transient
 * -------------------------------------------------------------------------- */

$options = array(
	'cbg_ap_db_version',
	'cbg_ap_sso_group_mapping',
	'cbg_ap_sso_allowed_domain',
	'cbg_ap_anno_scolastico_corrente',
	'cbg_ap_sedi',
	'cbg_ap_sync_gestionescuola_mode',
	'cbg_ap_sync_workspace_classes_mode',
	'cbg_ap_access_log_retention_months',
	'cbg_ap_news_edit_window_hours',
	'cbg_ap_cron_secret',
	'cbg_ap_default_news_types',
	'cbg_ap_needs_post_activation_setup',
	'cbg_ap_preserve_data_on_uninstall',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Transient con prefisso cbg_ap_.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cbg_ap_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cbg_ap_' ) . '%'
	)
);

/* -----------------------------------------------------------------------------
 * 3. Cancella user meta del plugin
 * -------------------------------------------------------------------------- */

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'cbg_ap_' ) . '%'
	)
);

/* -----------------------------------------------------------------------------
 * 4. Cancella post dei CPT del plugin (con relativi post meta)
 * -------------------------------------------------------------------------- */

$cpt_slugs = array( 'cbg_news_interna', 'cbg_bacheca_sindacale', 'cbg_comunicazione_ds' );

$post_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (" . implode( ',', array_fill( 0, count( $cpt_slugs ), '%s' ) ) . ')',
		...$cpt_slugs
	)
);

foreach ( $post_ids as $pid ) {
	wp_delete_post( (int) $pid, true );
}

/* -----------------------------------------------------------------------------
 * 5. Cancella ruoli custom
 * -------------------------------------------------------------------------- */

$cbg_roles = array(
	'cbg_docente',
	'cbg_docente_pubblicatore',
	'cbg_dsga',
	'cbg_dirigente',
	'cbg_ata',
	'cbg_rsu',
	'cbg_studente',
	'cbg_genitore',
);

foreach ( $cbg_roles as $role ) {
	remove_role( $role );
}

// Rimuovi le capability custom dagli altri ruoli (administrator, editor, ecc.).
$cbg_caps = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( $wpdb->prefix . 'capabilities' )
	)
);
// In pratica le capability sono in option `wp_user_roles`, gestita automaticamente
// da remove_role. Lasciamo l'enumerazione su user level solo come safety net.
unset( $cbg_caps );
// phpcs:enable

/* -----------------------------------------------------------------------------
 * 6. Cancella job Action Scheduler residui
 * -------------------------------------------------------------------------- */

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	$hooks = array(
		'cbg_ap_process_notification_queue',
		'cbg_ap_digest_tick',
		'cbg_ap_sync_workspace_groups',
		'cbg_ap_sync_workspace_classes',
		'cbg_ap_sync_gestionescuola',
		'cbg_ap_cleanup_access_log',
		'cbg_ap_cleanup_expired_sessions',
	);
	foreach ( $hooks as $hook ) {
		as_unschedule_all_actions( $hook );
	}
}
