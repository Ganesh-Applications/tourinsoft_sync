<?php
/**
 * Nettoyage à la suppression du plugin.
 *
 * @package Tourinsoft_Sync
 */

// Exécuté uniquement par WordPress lors de la suppression.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Supprimer les options et données du plugin ici, ex. :
// delete_option( 'tourinsoft_sync_settings' );
