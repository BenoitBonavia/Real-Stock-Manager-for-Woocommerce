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
	 * @param string $dir      Sous-dossier de `templates/`. Les autres modules
	 *                         qui partagent le rendu et les styles du module
	 *                         Préparation — comme « Besoins Back in Stock » —
	 *                         y passent le leur plutôt que de dupliquer ce
	 *                         chargeur.
	 */
	public static function render( string $template, array $data = array(), string $dir = 'preparation' ): void {
		$file = RSMW_PATH . 'templates/' . $dir . '/' . $template . '.php';

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
