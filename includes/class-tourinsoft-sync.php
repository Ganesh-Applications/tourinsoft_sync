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
		// Ajouter les add_action() / add_filter() ici.
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
