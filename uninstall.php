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
	'cbg_ap_roles_version',
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

// Rimuovi le capability custom CBG da TUTTI i ruoli (incluso administrator,
// editor, ecc.). Non possiamo "caricare" il file role-definitions in modalità
// uninstall (il plugin è già disattivato), quindi enumeriamo gli slug cap
// hardcoded — devono restare allineati con includes/roles/role-definitions.php.
$cbg_caps_to_remove = array(
	'cbg_ap_access_area_personale',
	'cbg_ap_publish_news',
	'cbg_ap_publish_bacheca_sindacale',
	'cbg_ap_publish_comunicazione_ds',
	'cbg_ap_approve_news',
	'cbg_ap_approve_assenze',
	'cbg_ap_approve_moduli',
	'cbg_ap_manage_classi',
	'cbg_ap_manage_news_types',
	'cbg_ap_manage_sso_mapping',
	'cbg_ap_manage_capabilities',
	'cbg_ap_view_access_log',
	'cbg_ap_import_data',
	'cbg_ap_manage_system',
	'cbg_ap_force_2fa_for_role',
);

$wp_roles = wp_roles();
foreach ( $wp_roles->roles as $role_slug => $role_data ) {
	$role = get_role( $role_slug );
	if ( ! $role ) {
		continue;
	}
	foreach ( $cbg_caps_to_remove as $cap ) {
		if ( $role->has_cap( $cap ) ) {
			$role->remove_cap( $cap );
		}
	}
}
// phpcs:enable

/* -----------------------------------------------------------------------------
 * 6. Cancella eventi WP-Cron e job Action Scheduler residui
 * -------------------------------------------------------------------------- */

// Pulizia log accessi (1.1.0): evento WP-Cron giornaliero.
wp_clear_scheduled_hook( 'cbg_ap_cleanup_access_log' );

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
