<?php
/**
 * Chargeur de gabarits du module de préparation.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Sépare le balisage de la logique : les gabarits vivent dans templates/preparation/
 * et reçoivent un unique tableau `$data`.
 */
final class View {

	/**
	 * Affiche un gabarit.
	 *
	 * @param string $template Nom du gabarit, sans extension.
	 * @param array  $data     Données mises à disposition du gabarit sous `$data`.
	 */
	public static function render( string $template, array $data = array() ): void {
		$file = RSMW_PATH . 'templates/preparation/' . $template . '.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		include $file;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
