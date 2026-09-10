<?php
/**
 * Configuration du module « Besoins Back in Stock ».
 *
 * @package RealStockManager
 */

namespace RSMW\BackInStock;

use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Réglages propres au module, distincts de ceux du plugin hôte (`Host::settings()`).
 */
final class Config {

	/** Clé d'option : statuts d'inscription comptés comme demande active. */
	public const KEY_STATUSES = 'bis_statuses';

	/**
	 * Statuts comptés par défaut, si rien n'est configuré.
	 *
	 * « En attente » et « Alerte envoyée » : une personne déjà notifiée mais
	 * pas encore reconnue comme ayant acheté reste une demande ouverte tant
	 * qu'on ne sait pas qu'elle a commandé ailleurs ou renoncé.
	 */
	public const DEFAULT_STATUSES = array( Host::STATUS_SUBSCRIBED, Host::STATUS_MAILSENT );

	/**
	 * Statuts d'inscription comptés comme demande active, dédoublonnés et
	 * filtrés contre ceux réellement enregistrés par l'hôte.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		$configured = Settings::get( self::KEY_STATUSES, self::DEFAULT_STATUSES );
		$configured = is_array( $configured ) ? $configured : self::DEFAULT_STATUSES;

		// Un statut inconnu de l'extension hôte ne remonterait rien : l'écarter
		// évite un écran vide sans explication.
		$statuses = array_values( array_intersect( $configured, Host::statuses() ) );

		if ( empty( $statuses ) ) {
			$statuses = self::DEFAULT_STATUSES;
		}

		/**
		 * Filtre les statuts d'inscription comptés comme demande active.
		 *
		 * @param string[] $statuses Slugs de statut de l'extension hôte.
		 */
		return (array) apply_filters( 'rsmw_bis_statuses', $statuses );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
