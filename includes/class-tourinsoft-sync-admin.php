<?php
/**
 * Page d'administration du plugin.
 *
 * @package Tourinsoft_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page « Tourinsoft » : bouton de synchronisation et affichage des résultats.
 */
class Tourinsoft_Sync_Admin {

	/**
	 * Slug de la page d'admin.
	 */
	const PAGE_SLUG = 'tourinsoft-sync';

	/**
	 * Action admin-post et nom du nonce.
	 */
	const ACTION = 'tourinsoft_sync_run';

	/**
	 * Nombre de lignes de log affichées.
	 */
	const LOG_LINES = 100;

	/**
	 * Enregistre les hooks d'admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_sync' ) );
	}

	/**
	 * Ajoute la page au menu de l'admin.
	 */
	public function add_menu() {
		add_menu_page(
			'Tourinsoft Sync',
			'Tourinsoft',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-update',
			80
		);
	}

	/**
	 * Traite le clic sur « Synchroniser », puis redirige vers la page.
	 */
	public function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Vous n’avez pas les droits suffisants.' );
		}

		check_admin_referer( self::ACTION );

		Tourinsoft_Sync::instance()->sync();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Affiche la page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$lines = Tourinsoft_Sync_Logger::get_last_lines( self::LOG_LINES );

		// Date du prochain import automatique (false si la tâche n'est pas planifiée)
		$next_run = Tourinsoft_Sync_Cron::next_run();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( 'Synchroniser', 'primary', 'submit', false ); ?>
			</form>

			<p>
				<?php if ( $next_run ) : ?>
					Prochain import automatique : <strong><?php echo esc_html( wp_date( 'd/m/Y à H:i', $next_run ) ); ?></strong>
				<?php else : ?>
					Aucun import automatique n'est planifié.
				<?php endif; ?>
			</p>

			<h2>Journal (<?php echo (int) self::LOG_LINES; ?> dernières lignes)</h2>
			<div class="card" style="max-width: none;">
				<?php if ( empty( $lines ) ) : ?>
					<p>Le journal est vide.</p>
				<?php else : ?>
					<pre style="white-space: pre-wrap; max-height: 500px; overflow: auto; margin: 0;"><?php
					foreach ( (array) $lines as $line ) {
						// Couleur selon le niveau : rouge pour les erreurs, orange pour les avertissements.
						// Le résumé de fin d'import est en gras pour être repéré facilement.
						if ( false !== strpos( $line, '[ERROR]' ) ) {
							$style = 'color: #d63638;';
						} elseif ( false !== strpos( $line, '[WARNING]' ) ) {
							$style = 'color: #b26200;';
						} elseif ( false !== strpos( $line, 'Fin de la synchronisation' ) ) {
							$style = 'font-weight: 600;';
						} else {
							$style = '';
						}

						echo '<span style="' . esc_attr( $style ) . '">' . esc_html( $line ) . "</span>\n";
					}
					?></pre>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
