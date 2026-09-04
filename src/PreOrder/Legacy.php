<?php
/**
 * Contrat de données du module Précommandes.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Clés reprises telles quelles des snippets remplacés, et clés neuves du module.
 *
 * Les premières ne doivent JAMAIS changer : des commandes en production les
 * portent déjà.
 */
final class Legacy {

	/** Statut de commande, sans le préfixe « wc- ». */
	public const STATUS_SLUG = 'precommande';

	/** Statut de commande préfixé, tel qu'il est stocké en base. */
	public const STATUS_FULL = 'wc-' . self::STATUS_SLUG;

	/**
	 * Date d'expédition annoncée.
	 *
	 * Même clé aux trois emplacements : postmeta du produit, postmeta de la
	 * variation, et meta de la ligne de commande où elle est figée à l'achat.
	 */
	public const DATE_META = '_mh_preorder_date';

	/**
	 * Libellé de la meta de ligne visible par le client.
	 *
	 * DÉLIBÉRÉMENT PAS DE __() ICI. Sur une meta de ligne de commande, le libellé
	 * EST la clé de stockage. Le passer par la traduction ferait changer la clé
	 * avec la locale du site, et toutes les commandes déjà enregistrées
	 * porteraient une clé orpheline que plus rien ne saurait relire.
	 */
	public const VISIBLE_DATE_LABEL = 'Expédition estimée';

	/**
	 * Quantité réellement précommandée sur cette ligne, figée à l'achat.
	 *
	 * Sert aux statistiques : une commande peut mêler articles disponibles et
	 * articles précommandés, un simple drapeau ne suffirait pas.
	 */
	public const ITEM_QTY_META = '_rsmw_preorder_qty';

	/**
	 * Horodatage de la levée : moment où la ligne devient effectivement couverte.
	 *
	 * C'est ce qui permet de comparer le délai promis au délai tenu. Posé une
	 * seule fois, jamais réécrit.
	 */
	public const ITEM_FILLED_META = '_rsmw_preorder_filled_at';

	/**
	 * Marqueur au niveau de la commande.
	 *
	 * Simple index : la vérité est sur les lignes. Sans lui, retrouver les
	 * précommandes imposerait de parcourir toutes les lignes de toutes les
	 * commandes. Jamais retiré, y compris une fois la commande expédiée — c'est
	 * précisément ce que le statut ne savait pas faire.
	 */
	public const ORDER_FLAG_META = '_rsmw_has_preorder';

	/** Date promise la plus lointaine de la commande, pour le tri et la relance. */
	public const ORDER_DATE_MAX_META = '_rsmw_preorder_date_max';

	/**
	 * Marque une commande dont le statut « Précommande » a déjà été posé.
	 *
	 * La bascule automatique n'a lieu QU'UNE FOIS. Sans ce témoin, un marchand
	 * qui sort volontairement une commande de « Précommande » la verrait y
	 * retomber au changement de statut suivant.
	 */
	public const STATUS_APPLIED_META = '_rsmw_preorder_status_applied';

	/**
	 * Constructeur privé : classe de constantes, jamais instanciée.
	 */
	private function __construct() {}
}
