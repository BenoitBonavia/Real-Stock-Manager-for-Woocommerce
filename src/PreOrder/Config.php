<?php
/**
 * Réglages du module Précommandes.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

use RSMW\Preparation\Config as PreparationConfig;
use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture des réglages propres aux précommandes.
 */
final class Config {

	/** Clé du réglage de bascule automatique de statut. */
	public const KEY_AUTO_STATUS = 'preorder_auto_status';

	/**
	 * La bascule automatique vers le statut « Précommande » est-elle demandée ?
	 *
	 * Répond à l'intention du marchand, pas à la faisabilité : voir
	 * {@see self::auto_status_is_operative()} pour savoir si elle a réellement lieu.
	 *
	 * @return bool
	 */
	public static function auto_status(): bool {
		return Settings::get_bool( self::KEY_AUTO_STATUS, false );
	}

	/**
	 * Le statut « Précommande » fait-il partie du périmètre de préparation ?
	 *
	 * C'est la condition sans laquelle la bascule serait NUISIBLE. Une commande
	 * placée dans un statut non suivi sort du circuit : elle disparaît de la table
	 * des besoins, l'attribution du stock ne la sert plus, et rien ne la ramènera
	 * jamais en « À empaqueter » — elle resterait bloquée en « Précommande ».
	 *
	 * @return bool
	 */
	public static function status_is_tracked(): bool {
		return in_array( Legacy::STATUS_SLUG, PreparationConfig::statuses(), true );
	}

	/**
	 * La bascule automatique a-t-elle effectivement lieu ?
	 *
	 * @return bool
	 */
	public static function auto_status_is_operative(): bool {
		return self::auto_status() && self::status_is_tracked();
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
