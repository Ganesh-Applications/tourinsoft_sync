<?php
/**
 * Intégration ACF (réglages, champs).
 *
 * @package Tourinsoft_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regroupe les hooks ACF du plugin.
 *
 * Instanciée uniquement si ACF Pro est actif (voir Tourinsoft_Sync::__construct()).
 */
class Tourinsoft_Sync_Acf {

	/**
	 * Enregistre les hooks ACF.
	 */
	public function __construct() {
                add_action('acf/render_field_settings', [$this, 'ts_render_field_path_setting']);
                add_filter('acf/post_type/additional_settings_tabs', [$this, 'ts_add_post_type_tab']);
                add_action('acf/post_type/render_settings_tab/tourinsoft', [$this, 'ts_render_post_type_tab']);
	}

        /**
         * Ajoute un onglet « Tourinsoft » aux réglages des post types ACF.
         */
        public function ts_add_post_type_tab(array $tabs): array
        {
                $tabs['tourinsoft'] = 'Tourinsoft';

                return $tabs;
        }

        /**
         * Affiche le réglage « Flux Tourinsoft » dans l'onglet Tourinsoft.
         *
         * Le prefix « acf_post_type » suffit : ACF enregistre la valeur avec le post type.
         */
        public function ts_render_post_type_tab(array $acf_post_type): void
        {
                acf_render_field_wrap([
                        'type' => 'text',
                        'name' => 'tourinsoft_flux',
                        'key' => 'tourinsoft_flux',
                        'prefix' => 'acf_post_type',
                        'value' => $acf_post_type['tourinsoft_flux'] ?? '',
                        'label' => 'Flux Tourinsoft',
                ], 'div', 'field');
        }

        /**
         * Ajoute un champ de réglage « Path Tourinsoft » aux champs ACF.
         */
        public function ts_render_field_path_setting(array $field): void
        {
                acf_render_field_setting($field, [
                        'label' => 'Path Tourinsoft',
                        'name' => 'tourinsoft_path',
                        'type' => 'text',
                ]);
        }
}
