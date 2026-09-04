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
	 *
	 * Relevé de 40 à 200 avec l'arrivée de la réception en lot : un colis de
	 * quinze références écrit jusqu'à trente entrées d'un coup et effaçait
	 * l'historique complet en une validation.
	 */
	public const MAX_ENTRIES = 200;

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
	 * Les entrées portent désormais `product` (identifiant de la référence) et
	 * `batch` (identifiant de réception) : sans le premier, le journal n'était
	 * qu'un libellé d'affichage, ni requêtable ni cumulable ; le second permet de
	 * rattacher entre elles les lignes d'un même colis.
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
