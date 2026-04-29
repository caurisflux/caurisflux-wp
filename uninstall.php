<?php
/**
 * Routine de désinstallation — déclenchée uniquement quand l'utilisateur
 * supprime explicitement le plugin (pas sur deactivate).
 *
 * On nettoie les options et les transients de dédoublonnage des webhooks.
 * Les meta de commandes (_caurisflux_*) sont CONSERVÉS pour audit/comptabilité.
 *
 * @package CaurisFlux
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Suppression des settings WooCommerce.
delete_option( 'woocommerce_caurisflux_settings' );

// Nettoyage des transients de dédoublonnage webhooks.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_caurisflux_evt_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_caurisflux_evt_%'" );
