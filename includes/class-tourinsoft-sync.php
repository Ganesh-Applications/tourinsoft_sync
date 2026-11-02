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
	 * Verrou qui empêche deux imports de tourner en même temps (transient).
	 */
	const LOCK_NAME = 'tourinsoft_sync_running';

	/**
	 * Durée maximale du verrou : s'il n'est pas libéré (plantage PHP en plein import),
	 * il expire tout seul et les imports suivants peuvent reprendre.
	 */
	const LOCK_DURATION = 2 * HOUR_IN_SECONDS;

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

		// Import automatique quotidien
		new Tourinsoft_Sync_Cron();
	}

	/**
	 * Avertit dans l'admin quand ACF Pro n'est pas actif.
	 */
	public function acf_missing_notice() {
		echo '<div class="notice notice-error"><p>Tourinsoft Sync nécessite le plugin Advanced Custom Fields PRO.</p></div>';
	}

	/**
	 * Lance la synchronisation.
	 *
	 * Appelée par le bouton « Synchroniser » de l'admin et par la tâche planifiée.
	 * Si un import est déjà en cours, ne fait rien
	 */
        public function sync() {
                //-- On lève la limite de temps d'exécution de PHP quand le serveur le permet.
                // @ : certains hébergeurs désactivent set_time_limit() et afficheraient un avertissement.
                @set_time_limit( 0 );

                if ( get_transient( self::LOCK_NAME ) ) {
                        Tourinsoft_Sync_Logger::warning( 'Synchronisation annulée : un import est déjà en cours.' );
                        return;
                }

                set_transient( self::LOCK_NAME, time(), self::LOCK_DURATION );

                Tourinsoft_Sync_Logger::info( 'Début de la synchronisation' );

                try {
                        ( new Tourinsoft_Sync_Importer() )->import();
                } catch ( Exception $e ) {
                        Tourinsoft_Sync_Logger::error( $e->getMessage() );
                } finally {
                        // Toujours libérer le verrou, même si l'import a échoué
                        delete_transient( self::LOCK_NAME );
                }

                Tourinsoft_Sync_Logger::info( 'Fin de la synchronisation' );
        }

        /**
	 * Exécuté à l'activation du plugin.
	 */
	public static function activate() {
		Tourinsoft_Sync_Cron::schedule();
	}

	/**
	 * Exécuté à la désactivation du plugin.
	 */
	public static function deactivate() {
		Tourinsoft_Sync_Cron::unschedule();
	}
}
