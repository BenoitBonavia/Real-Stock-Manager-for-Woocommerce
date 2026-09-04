<?php
/**
 * Détection des snippets de précommande que ce module remplace.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Met le module Précommandes en veille tant que les snippets sont chargés.
 *
 * GARDE VOLONTAIREMENT INDÉPENDANTE de celle du module Préparation. Les deux
 * listes de sentinelles doivent rester séparées : `Plugin::boot()` utilise la
 * garde de Préparation pour conditionner à la fois ce module ET l'enregistrement
 * du statut « À empaqueter ». Mutualiser les listes mettrait donc toute la
 * préparation en veille dès que le seul snippet de précommande serait actif, et
 * les commandes « À empaqueter » disparaîtraient des écrans d'administration.
 *
 * @see \RSMW\Preparation\SnippetGuard
 */
final class SnippetGuard {

	/**
	 * Fonctions des cinq snippets servant de témoin de présence.
	 *
	 * Une par snippet, pour détecter aussi le cas où le marchand n'en aurait
	 * désactivé qu'une partie.
	 *
	 * @var string[]
	 */
	private const SENTINELS = array(
		'enregistrer_statut_precommande',
		'mh_preorder_get_raw_date',
		'mh_preorder_availability_text',
		'ds_change_sale_text',
		'auto_attribuer_statut_precommande_restock',
		'add_a_traiter_custom_order_view',
	);

	/**
	 * Un des snippets est-il chargé sur cette requête ?
	 *
	 * @return bool
	 */
	public static function snippet_is_active(): bool {
		foreach ( self::SENTINELS as $function ) {
			if ( function_exists( $function ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Nom des fonctions effectivement détectées.
	 *
	 * @return string[]
	 */
	public static function detected_functions(): array {
		return array_values( array_filter( self::SENTINELS, 'function_exists' ) );
	}

	/**
	 * Affiche l'avertissement de coexistence.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> — %s</p><p>%s</p></div>',
			esc_html__( 'Real Stock Manager for WooCommerce', 'real-stock-manager-for-woocommerce' ),
			esc_html__(
				'Les snippets de précommande sont toujours actifs : le module du plugin reste en veille pour éviter les doublons.',
				'real-stock-manager-for-woocommerce'
			),
			esc_html__(
				'Désactivez-les, puis rechargez cette page. Vos dates d’expédition et vos commandes en « Précommande » sont conservées : le plugin utilise exactement les mêmes données.',
				'real-stock-manager-for-woocommerce'
			)
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
