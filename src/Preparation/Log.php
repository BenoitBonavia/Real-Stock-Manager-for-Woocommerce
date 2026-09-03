<?php
/**
 * Journalisation du module de préparation.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

use RSMW\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Passe-plat vers le journal WooCommerce, conditionné au réglage de journalisation.
 *
 * Reproduit le comportement de `mh_prep_log()` : silencieux par défaut, actif
 * seulement si la journalisation est demandée.
 */
final class Log {

	/**
	 * Consigne une opération de stock.
	 *
	 * @param string $message Message.
	 * @param array  $context Contexte additionnel.
	 */
	public static function info( string $message, array $context = array() ): void {
		if ( ! Config::logging_enabled() ) {
			return;
		}

		Logger::info( $message, $context );
	}

	/**
	 * Consigne une anomalie. Toujours écrite : une erreur ne doit pas dépendre
	 * d'un réglage de confort pour être visible.
	 *
	 * @param string $message Message.
	 * @param array  $context Contexte additionnel.
	 */
	public static function error( string $message, array $context = array() ): void {
		Logger::error( $message, $context );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
