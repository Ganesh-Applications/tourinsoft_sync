<?php
/**
 * Fichier de log du plugin.
 *
 * Utilisation n'importe où dans le code :
 *     Tourinsoft_Sync_Logger::info( 'Message' );
 *     Tourinsoft_Sync_Logger::error( 'Message' );
 *
 * @package Tourinsoft_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Écrit dans wp-content/uploads/tourinsoft-sync/ et relit les dernières lignes.
 */
class Tourinsoft_Sync_Logger {

	/**
	 * Taille maximale du fichier avant rotation (5 Mo).
	 */
	const MAX_SIZE = 5242880;

	/**
	 * Ajoute une ligne de niveau INFO.
	 *
	 * @param string $message Message.
	 */
	public static function info( $message ) {
		self::log( $message, 'INFO' );
	}

	/**
	 * Ajoute une ligne de niveau WARNING.
	 *
	 * @param string $message Message.
	 */
	public static function warning( $message ) {
		self::log( $message, 'WARNING' );
	}

	/**
	 * Ajoute une ligne de niveau ERROR.
	 *
	 * @param string $message Message.
	 */
	public static function error( $message ) {
		self::log( $message, 'ERROR' );
	}

	/**
	 * Ajoute une ligne au fichier de log.
	 *
	 * @param string $message Message.
	 * @param string $level   Niveau (INFO, WARNING, ERROR…).
	 */
	public static function log( $message, $level = 'INFO' ) {
		$file = self::get_file();
		if ( ! $file ) {
			return;
		}

		// Rotation : l'ancien log est gardé en .1 quand le fichier devient trop gros.
		if ( file_exists( $file ) && filesize( $file ) > self::MAX_SIZE ) {
			rename( $file, $file . '.1' );
		}

		$line = sprintf( "[%s] [%s] %s\n", wp_date( 'Y-m-d H:i:s' ), $level, $message );
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Retourne les dernières lignes du fichier.
	 *
	 * @param int $count Nombre de lignes.
	 * @return string[]
	 */
	public static function get_last_lines( $count = 100 ) {
		$file = self::get_file();
		if ( ! $file || ! file_exists( $file ) ) {
			return array();
		}

		// Lit seulement la fin du fichier pour rester rapide sur un gros log.
		$size   = filesize( $file );
		$chunk  = min( $size, 256 * 1024 );
		$handle = fopen( $file, 'rb' );
		fseek( $handle, -$chunk, SEEK_END );
		$content = fread( $handle, $chunk );
		fclose( $handle );

		$lines = explode( "\n", trim( $content ) );

		// Si on a coupé au milieu du fichier, la première ligne est incomplète.
		if ( $chunk < $size ) {
			array_shift( $lines );
		}

		return array_slice( $lines, -$count );
	}

	/**
	 * Chemin du fichier de log (dossier créé et protégé si besoin).
	 *
	 * @return string|false
	 */
	public static function get_file() {
		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . 'tourinsoft-sync';

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
			// Empêche l'accès au log depuis le navigateur.
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		// Nom difficile à deviner, pour les serveurs qui ignorent le .htaccess (nginx).
		return $dir . '/tourinsoft-sync-' . wp_hash( 'tourinsoft-sync-log' ) . '.log';
	}
}
