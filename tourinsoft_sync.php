<?php
/**
 * Plugin Name:       Tourinsoft Sync
 * Plugin URI:        https://github.com/Ganesh-Applications/tourinsoft_sync
 * Description:       Synchronisation des données Tourinsoft avec WordPress.
 * Version:           0.1.0
 * Author:            Ganesh Applications
 * Text Domain:       tourinsoft_sync
 *
 * @package Tourinsoft_Sync
 */

// Bloque l'accès direct au fichier.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOURINSOFT_SYNC_VERSION', '0.1.0' );
define( 'TOURINSOFT_SYNC_FILE', __FILE__ );
define( 'TOURINSOFT_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'TOURINSOFT_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once TOURINSOFT_SYNC_PATH . 'includes/class-tourinsoft-sync.php';

register_activation_hook( __FILE__, array( 'Tourinsoft_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Tourinsoft_Sync', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Tourinsoft_Sync', 'instance' ) );
