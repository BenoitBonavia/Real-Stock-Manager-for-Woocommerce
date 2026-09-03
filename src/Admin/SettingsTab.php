<?php
/**
 * Onglet de réglages WooCommerce.
 *
 * @package RealStockManager
 */

namespace RSMW\Admin;

use RSMW\Modules\ModuleInterface;
use RSMW\Plugin;
use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Réglages → Stocks réels.
 *
 * Les identifiants de champs servent directement de noms d'options : ils
 * doivent donc rester préfixés par `rsmw_` (cf. Settings::PREFIX).
 */
final class SettingsTab extends \WC_Settings_Page {

	/**
	 * Déclare l'onglet auprès de WooCommerce.
	 */
	public function __construct() {
		$this->id    = Admin::SETTINGS_TAB;
		$this->label = __( 'Stocks réels', 'real-stock-manager-for-woocommerce' );

		parent::__construct();
	}

	/**
	 * Sous-sections de l'onglet.
	 *
	 * @return array<string, string>
	 */
	protected function get_own_sections(): array {
		return array(
			''        => __( 'Général', 'real-stock-manager-for-woocommerce' ),
			'modules' => __( 'Modules', 'real-stock-manager-for-woocommerce' ),
		);
	}

	/**
	 * Champs de la section par défaut.
	 *
	 * @return array
	 */
	protected function get_settings_for_default_section(): array {
		$settings = array(
			array(
				'title' => __( 'Réglages généraux', 'real-stock-manager-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Comportement global de la gestion des stocks réels.', 'real-stock-manager-for-woocommerce' ),
				'id'    => Settings::PREFIX . 'general_options',
			),
			array(
				'title'    => __( 'Journalisation', 'real-stock-manager-for-woocommerce' ),
				'desc'     => __( 'Consigner les opérations de stock dans les journaux WooCommerce.', 'real-stock-manager-for-woocommerce' ),
				'desc_tip' => __( 'Visible dans WooCommerce → État → Journaux, source « real-stock-manager ».', 'real-stock-manager-for-woocommerce' ),
				'id'       => Settings::PREFIX . 'enable_logging',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'general_options',
			),
		);

		return $settings;
	}

	/**
	 * Champs de la section « Modules » : une case à cocher par module déclaré.
	 *
	 * @return array
	 */
	protected function get_settings_for_modules_section(): array {
		$settings = array(
			array(
				'title' => __( 'Modules', 'real-stock-manager-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Activez individuellement les règles de gestion de stock.', 'real-stock-manager-for-woocommerce' ),
				'id'    => Settings::PREFIX . 'module_options',
			),
		);

		foreach ( $this->get_declared_modules() as $module ) {
			$settings[] = array(
				'title'   => $module->get_title(),
				'desc'    => __( 'Activer', 'real-stock-manager-for-woocommerce' ),
				'id'      => Settings::PREFIX . 'module_' . $module->get_id() . '_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			);
		}

		if ( 1 === count( $settings ) ) {
			$settings[] = array(
				'title' => '',
				'type'  => 'info',
				'text'  => __( 'Aucun module déclaré pour le moment. Ajoutez vos classes dans src/Modules/ puis référencez-les dans Plugin::get_module_classes().', 'real-stock-manager-for-woocommerce' ),
				'id'    => Settings::PREFIX . 'module_empty_notice',
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => Settings::PREFIX . 'module_options',
		);

		return $settings;
	}

	/**
	 * Instancie tous les modules déclarés, actifs ou non, pour l'affichage.
	 *
	 * @return ModuleInterface[]
	 */
	private function get_declared_modules(): array {
		$modules = array();

		foreach ( Plugin::get_module_classes() as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			$module = new $class_name();

			if ( $module instanceof ModuleInterface ) {
				$modules[] = $module;
			}
		}

		return $modules;
	}
}
