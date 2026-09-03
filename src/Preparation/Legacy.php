<?php
/**
 * Contrat de données hérité du snippet WPCode.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Clés de stockage reprises telles quelles du snippet « Maison Hespérides ».
 *
 * AUCUNE de ces valeurs ne doit être modifiée. Le plugin lit et écrit exactement
 * les mêmes clés que le snippet qu'il remplace : c'est ce qui permet de désactiver
 * le snippet sans migration ni perte. Les renommer orphelinerait le stock physique,
 * les pointages de préparation et les commandes déjà basculées.
 */
final class Legacy {

	/**
	 * Statut de commande, sans le préfixe « wc- ».
	 *
	 * Utilisé partout où WooCommerce attend un slug nu : set_status(), has_status(),
	 * woocommerce_order_is_paid_statuses, action groupée « mark_{slug} ».
	 */
	public const STATUS_SLUG = 'mh-empaqueter';

	/**
	 * Statut de commande préfixé, tel qu'il est stocké en base.
	 *
	 * Utilisé là où WordPress attend un post status : register_post_status(),
	 * clés de wc_order_statuses(), clés du tableau de vues des listes.
	 */
	public const STATUS_FULL = 'wc-' . self::STATUS_SLUG;

	/** Stock physique libre, porté par le produit ou la variation. */
	public const STOCK_META = '_mh_stock_reel';

	/** Quantité pointée sur une ligne de commande. */
	public const ITEM_QTY_META = '_mh_prep_qty';

	/** Part de la ligne réellement prélevée sur le stock physique. */
	public const ITEM_SOURCE_META = '_mh_prep_from_stock';

	/** Horodatage du dernier pointage d'une ligne. */
	public const ITEM_DATE_META = '_mh_prep_date';

	/** Auteur du dernier pointage d'une ligne. */
	public const ITEM_USER_META = '_mh_prep_user';

	/** Statut de la commande avant sa bascule en « À empaqueter ». */
	public const PREV_STATUS_META = '_mh_prep_prev_status';

	/** Option stockant les 40 derniers mouvements de stock. */
	public const JOURNAL_OPTION = 'mh_prep_receptions';

	/** Transient de la table des besoins. */
	public const CACHE_KEY = 'mh_prep_demand_v1';

	/** Transient portant l'horodatage et le périmètre du dernier calcul. */
	public const CACHE_META_KEY = self::CACHE_KEY . '_meta';

	/** Slug de la page « Besoins & stock » — conservé pour ne pas casser les signets. */
	public const PAGE_NEEDS = 'mh-prep-stock';

	/** Slug de la page « Gestion stock » — conservé pour ne pas casser les signets. */
	public const PAGE_STOCK = 'mh-prep-reception';

	/** Action AJAX du pointage. */
	public const AJAX_ACTION = 'mh_prep_set';

	/** Nonce de l'action AJAX du pointage. */
	public const AJAX_NONCE = 'mh_prep';

	/**
	 * Constructeur privé : classe de constantes, jamais instanciée.
	 */
	private function __construct() {}
}
