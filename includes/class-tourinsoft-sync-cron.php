<?php
/**
 * Import automatique (tâche planifiée WP-Cron).
 *
 * @package Tourinsoft_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Planifie l'import quotidien et le lance à l'heure prévue.
 *
 * L'import lui-même (et le verrou qui empêche deux imports en même temps)
 * reste dans Tourinsoft_Sync::sync() : cette classe ne gère que le « quand ».
 */
class Tourinsoft_Sync_Cron {

	/**
	 * Nom de la tâche planifiée.
	 */
	const HOOK = 'tourinsoft_sync_cron';

	/**
	 * Heure de l'import automatique (heure du site, réglée dans « Réglages → Général »).
	 * La nuit, pour ne pas ralentir le site pendant la journée.
	 */
	const TIME = '03:00';

	/**
	 * Enregistre les hooks.
	 */
	public function __construct() {
		// À l'heure prévue, WP-Cron déclenche ce hook : on lance l'import.
		add_action( self::HOOK, array( $this, 'run' ) );

		// Planifie la tâche si elle ne l'est pas encore. Utile si le plugin était
		// déjà actif avant l'ajout du cron (le hook d'activation n'est alors pas rejoué).
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Lance l'import (appelée par WP-Cron).
	 */
	public function run() {
		Tourinsoft_Sync_Logger::info( 'Import automatique' );
		Tourinsoft_Sync::instance()->sync();
	}

	/**
	 * Planifie l'import automatique quotidien, s'il ne l'est pas déjà.
	 *
	 * Premier passage au prochain TIME (aujourd'hui si l'heure n'est pas
	 * encore passée, sinon demain), puis toutes les 24 heures.
	 */
	public static function schedule() {
		if ( self::next_run() ) {
			return;
		}

		// wp_timezone() : fuseau horaire du site (et non celui du serveur, souvent UTC)
		$next_run = new DateTimeImmutable( 'today ' . self::TIME, wp_timezone() );

		if ( $next_run->getTimestamp() <= time() ) {
			$next_run = $next_run->modify( '+1 day' );
		}

		wp_schedule_event( $next_run->getTimestamp(), 'daily', self::HOOK );
	}

	/**
	 * Supprime l'import automatique (à la désactivation du plugin).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Date du prochain import automatique.
	 *
	 * @return int|false Timestamp, ou false si aucun import n'est planifié.
	 */
	public static function next_run() {
		return wp_next_scheduled( self::HOOK );
	}
}
