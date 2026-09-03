<?php
/**
 * Détection du snippet WPCode que ce module remplace.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Empêche le module et le snippet de tourner en même temps.
 *
 * Les deux accrochent les mêmes hooks : les laisser cohabiter provoquerait une
 * double attribution automatique du stock, deux métabox sur la fiche commande et
 * des entrées de menu en double. Comme le snippet ne peut pas s'effacer de
 * lui-même, c'est le plugin qui se met en veille tant qu'il est détecté.
 *
 * Effet de bord recherché : le retour arrière consiste simplement à réactiver le
 * snippet, le module se remettant en veille au chargement suivant.
 *
 * DÉPENDANCE DE CALENDRIER — WPCode exécute les snippets « Exécuter partout » sur
 * `plugins_loaded` en priorité 5, et le plugin s'amorce sur ce même hook en
 * priorité 20 : les fonctions du snippet sont donc déjà déclarées au moment de la
 * détection. Abaisser la priorité d'amorçage du plugin sous 5 casserait la garde,
 * et le module s'enregistrerait en double sans aucun signal.
 */
final class SnippetGuard {

	/**
	 * Fonctions du snippet servant de témoin de présence.
	 *
	 * Plusieurs plutôt qu'une seule : le snippet peut avoir été partiellement
	 * modifié, et un faux négatif est bien plus coûteux qu'un faux positif.
	 *
	 * @var string[]
	 */
	private const SENTINELS = array(
		'mh_prep_demand_map',
		'mh_prep_set_item_qty',
		'mh_prep_receive',
		'mh_prep_sync_status',
	);

	/**
	 * Le snippet est-il chargé sur cette requête ?
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
	 * Nom des fonctions du snippet effectivement détectées.
	 *
	 * @return string[]
	 */
	public static function detected_functions(): array {
		return array_values( array_filter( self::SENTINELS, 'function_exists' ) );
	}

	/**
	 * Affiche l'avertissement de coexistence dans l'administration.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> — %s</p><p>%s</p></div>',
			esc_html__( 'Real Stock Manager for WooCommerce', 'real-stock-manager-for-woocommerce' ),
			esc_html__(
				'Le snippet de préparation des commandes est toujours actif : le module du plugin reste en veille pour éviter les doublons.',
				'real-stock-manager-for-woocommerce'
			),
			esc_html__(
				'Désactivez le snippet, puis rechargez cette page. Vos stocks, vos pointages et vos commandes « À empaqueter » sont conservés : le plugin utilise exactement les mêmes données.',
				'real-stock-manager-for-woocommerce'
			)
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
