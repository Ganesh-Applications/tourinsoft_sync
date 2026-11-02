<?php
/**
 * Classe principale du plugin.
 *
 * @package Tourinsoft_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Point d'entrée du plugin (singleton).
 */
final class Tourinsoft_Sync {

	/**
	 * Instance unique.
	 *
	 * @var Tourinsoft_Sync|null
	 */
	private static $instance = null;

	/**
	 * Retourne l'instance unique.
	 *
	 * @return Tourinsoft_Sync
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Enregistre les hooks.
	 */
	private function __construct() {
		if ( is_admin() ) {
			new Tourinsoft_Sync_Admin();
		}

		// ACF Pro est requis (acf_add_options_page n'existe pas dans la version gratuite).
		if ( function_exists( 'acf_add_options_page' ) ) {
			new Tourinsoft_Sync_Acf();
		} else {
			add_action( 'admin_notices', array( $this, 'acf_missing_notice' ) );
		}
	}

	/**
	 * Avertit dans l'admin quand ACF Pro n'est pas actif.
	 */
	public function acf_missing_notice() {
		echo '<div class="notice notice-error"><p>Tourinsoft Sync nécessite le plugin Advanced Custom Fields PRO.</p></div>';
	}

	/**
	 * Lance la synchronisation.
	 */
        public function sync() {
                Tourinsoft_Sync_Logger::info( 'Début de la synchronisation' );

                try {
                        ( new Tourinsoft_Sync_Importer() )->import();
                } catch ( Exception $e ) {
                        Tourinsoft_Sync_Logger::error( $e->getMessage() );
                }

                Tourinsoft_Sync_Logger::info( 'Fin de la synchronisation' );
        }

        /**
	 * Exécuté à l'activation du plugin.
	 */
	public static function activate() {
		// Créer les options, tables, tâches cron…
	}

	/**
	 * Exécuté à la désactivation du plugin.
	 */
	public static function deactivate() {
		// Supprimer les tâches cron planifiées…
	}
}
