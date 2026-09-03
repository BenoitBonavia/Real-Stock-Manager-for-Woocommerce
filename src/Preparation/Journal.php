<?php
/**
 * Journal des mouvements de stock.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Conserve les derniers mouvements de stock physique, affichés en bas de la page
 * « Gestion stock ». Volontairement borné : c'est un aide-mémoire d'exploitation,
 * pas une comptabilité.
 */
final class Journal {

	/**
	 * Nombre de mouvements conservés.
	 */
	public const MAX_ENTRIES = 40;

	/**
	 * Mouvements enregistrés, du plus récent au plus ancien.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		$entries = get_option( Legacy::JOURNAL_OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Enregistre un mouvement.
	 *
	 * @param array $entry Mouvement.
	 */
	public static function add( array $entry ): void {
		$entries = self::all();

		array_unshift( $entries, $entry );

		update_option( Legacy::JOURNAL_OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Nombre de mouvements enregistrés. Sert au panneau de diagnostic.
	 *
	 * @return int
	 */
	public static function count(): int {
		return count( self::all() );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
