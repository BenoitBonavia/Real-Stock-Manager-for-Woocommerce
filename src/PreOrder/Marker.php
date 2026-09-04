<?php
/**
 * Marqueurs de traçabilité des précommandes.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Fige à l'achat le fait qu'une ligne était une précommande, et horodate sa levée.
 *
 * C'est le remplacement du snippet qui basculait le statut de commande. Un statut
 * est une valeur unique : il ne peut pas porter à la fois l'historique et l'état
 * courant, si bien que la trace disparaissait dès que la commande avançait. Ici
 * la trace vit dans des métas qu'on n'écrit qu'une fois et qu'on ne retire jamais.
 *
 * On ne rejoue JAMAIS `is_on_backorder()` après l'achat : cette méthode lit l'état
 * courant du produit, sans aucun contexte de commande. Une fois la marchandise
 * revenue en stock, elle répondrait « non » pour une commande qui était pourtant
 * bien une précommande.
 */
final class Marker {

	/**
	 * Accroche la pose des marqueurs.
	 */
	public static function register(): void {
		/*
		 * Le tunnel en blocs passe par ce hook comme le tunnel classique : le
		 * Store API délègue à WC_Checkout::create_order_line_items(), qui l'émet.
		 * Priorité 20 pour passer après set_backorder_meta() de WooCommerce, dont
		 * on réutilise le calcul quand il est disponible.
		 */
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'stamp_line' ), 20, 4 );

		/*
		 * Marqueur de commande : un hook par tunnel, tous deux idempotents. Ils
		 * sont émis une fois la commande entièrement enregistrée, lignes comprises.
		 *
		 * Pas de filet sur `woocommerce_new_order` : il est émis depuis create(),
		 * donc AVANT save_items(). À cet instant aucune ligne n'est encore en base
		 * et la commande relue n'en porterait aucune — le filet ne verrait jamais
		 * rien. Les commandes créées par l'API REST ou le point de vente ne sont
		 * pas couvertes, faute de hook exploitable ; leurs lignes ne reçoivent
		 * de toute façon pas de marqueur (cf. stamp_admin_line).
		 */
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'stamp_order_by_id' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'stamp_order' ), 20 );

		// Rattrapage des lignes ajoutées à la main en back-office.
		add_action( 'woocommerce_ajax_add_order_item_meta', array( __CLASS__, 'stamp_admin_line' ), 10, 3 );

		// Levée de la précommande, émise par le module Préparation.
		add_action( 'rsmw_line_prepared', array( __CLASS__, 'maybe_stamp_filled' ), 10, 3 );
	}

	/**
	 * Fige la nature « précommande » d'une ligne au moment de l'achat.
	 *
	 * Purement déclaratif : aucune écriture de journal, aucun compteur, aucun
	 * mouvement de stock. Le callback doit rester sans effet de bord.
	 *
	 * @param \WC_Order_Item_Product $item          Ligne de commande.
	 * @param string                 $cart_item_key Clé de l'article du panier.
	 * @param array                  $values        Article du panier.
	 * @param \WC_Order              $order         Commande.
	 */
	public static function stamp_line( $item, $cart_item_key, $values, $order ): void {
		unset( $cart_item_key, $order );

		$product = $item->get_product();

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$quantity = isset( $values['quantity'] ) ? (int) $values['quantity'] : (int) $item->get_quantity();

		self::apply_marks( $item, $product, $quantity );
	}

	/**
	 * Rattrapage pour une ligne ajoutée à la main sur une commande en back-office.
	 *
	 * Le hook du tunnel ne couvre que le parcours « panier vers commande » : une
	 * commande saisie au téléphone n'y passe pas. L'API REST et le point de vente
	 * n'offrent aucun équivalent et restent donc non couverts.
	 *
	 * @param int                    $item_id Identifiant de la ligne.
	 * @param \WC_Order_Item_Product $item    Ligne de commande.
	 * @param \WC_Order              $order   Commande.
	 */
	public static function stamp_admin_line( $item_id, $item, $order ): void {
		unset( $item_id );

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$product = $item->get_product();

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( self::apply_marks( $item, $product, (int) $item->get_quantity() ) ) {
			$item->save();
			self::stamp_order( $order );
		}
	}

	/**
	 * Écrit les marqueurs sur une ligne, si elle est bien une précommande.
	 *
	 * @param \WC_Order_Item_Product $item     Ligne de commande.
	 * @param \WC_Product            $product  Produit ou variation.
	 * @param int                    $quantity Quantité commandée.
	 *
	 * @return bool Vrai si la ligne a été marquée.
	 */
	private static function apply_marks( $item, $product, int $quantity ): bool {
		$preordered = self::preordered_quantity( $item, $product, $quantity );

		if ( $preordered <= 0 ) {
			return false;
		}

		// add_meta_data( …, true ) : unique, donc rejouable sans effet.
		$item->add_meta_data( Legacy::ITEM_QTY_META, $preordered, true );

		$raw = Dates::raw( $product );

		if ( '' === $raw ) {
			return true;
		}

		$item->add_meta_data( Legacy::DATE_META, $raw, true );

		/*
		 * Seule meta VISIBLE ajoutée. WooCommerce affiche déjà sa propre ligne
		 * « Backordered » dans les emails quand le réglage de rupture le prévoit :
		 * en ajouter une seconde ferait lire deux fois la même information.
		 */
		$item->add_meta_data( Legacy::VISIBLE_DATE_LABEL, Dates::format( $raw, 'j F Y' ), true );

		return true;
	}

	/**
	 * Quantité réellement précommandée sur une ligne.
	 *
	 * WooCommerce écrit lui-même une meta « Backordered » juste avant ce hook,
	 * mais seulement si le réglage de rupture vaut « Autoriser, mais informer le
	 * client ». On la réutilise quand elle existe, et on refait le calcul sinon.
	 *
	 * @param \WC_Order_Item_Product $item     Ligne de commande.
	 * @param \WC_Product            $product  Produit ou variation.
	 * @param int                    $quantity Quantité commandée.
	 *
	 * @return int
	 */
	private static function preordered_quantity( $item, $product, int $quantity ): int {
		$native = self::native_backordered_quantity( $item );

		if ( $native > 0 ) {
			return min( $quantity, $native );
		}

		if ( ! $product->is_on_backorder( $quantity ) ) {
			return 0;
		}

		if ( ! $product->managing_stock() ) {
			// Statut de stock « en réappro » sans gestion de quantité : tout l'est.
			return $quantity;
		}

		$available  = max( 0, (int) $product->get_stock_quantity() );
		$preordered = max( 0, $quantity - $available );

		// is_on_backorder() a répondu oui : la ligne est une précommande même si
		// le calcul ci-dessus tombe à zéro, par exemple sur un stock déjà négatif.
		return $preordered > 0 ? $preordered : $quantity;
	}

	/**
	 * Quantité portée par la meta « Backordered » de WooCommerce, si présente.
	 *
	 * @param \WC_Order_Item_Product $item Ligne de commande.
	 *
	 * @return int
	 */
	private static function native_backordered_quantity( $item ): int {
		foreach ( self::native_meta_keys( $item ) as $key ) {
			$value = $item->get_meta( $key );

			if ( is_numeric( $value ) ) {
				return max( 0, (int) $value );
			}
		}

		return 0;
	}

	/**
	 * Clés possibles de la meta « Backordered » écrite par WooCommerce.
	 *
	 * WooCommerce passe ce libellé par `__()` : la clé dépend donc de la locale
	 * active AU MOMENT DE L'ÉCRITURE. Une boutique passée du français à l'anglais
	 * — ou l'inverse — a les deux variantes en base. On les cherche toutes.
	 *
	 * @param \WC_Order_Item_Product|null $item Ligne de commande, pour le filtre.
	 *
	 * @return string[]
	 */
	public static function native_meta_keys( $item = null ): array {
		$keys = array(
			apply_filters( 'woocommerce_backordered_item_meta_name', __( 'Backordered', 'woocommerce' ), $item ),
			'Backordered',
		);

		return array_values( array_unique( array_filter( array_map( 'strval', $keys ), 'strlen' ) ) );
	}

	/**
	 * Pose le marqueur de commande à partir d'un identifiant.
	 *
	 * @param int $order_id Identifiant de commande.
	 */
	public static function stamp_order_by_id( $order_id ): void {
		self::stamp_order( wc_get_order( $order_id ) );
	}

	/**
	 * Pose le marqueur au niveau de la commande, si l'une de ses lignes est marquée.
	 *
	 * Idempotent : appelé depuis trois hooks, il ne doit écrire qu'une fois.
	 *
	 * @param \WC_Order|mixed $order Commande.
	 */
	public static function stamp_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$flagged = '' !== (string) $order->get_meta( Legacy::ORDER_FLAG_META );
		$latest  = '';

		foreach ( $order->get_items() as $item ) {
			if ( self::line_preorder_quantity( $item ) <= 0 ) {
				continue;
			}

			$raw = (string) $item->get_meta( Legacy::DATE_META );

			// Comparaison de chaînes : le format AAAA-MM-JJ est ordonnable tel quel.
			if ( '' !== $raw && $raw > $latest ) {
				$latest = $raw;
			}
		}

		if ( '' === $latest && ! self::order_has_marked_line( $order ) ) {
			return;
		}

		$changed = false;

		if ( ! $flagged ) {
			$order->update_meta_data( Legacy::ORDER_FLAG_META, 1 );
			$changed = true;
		}

		/*
		 * Le drapeau est un fait acquis, la date est une promesse. Une ligne de
		 * précommande ajoutée après coup en back-office peut repousser l'échéance :
		 * on ne sort donc PAS en tête quand le drapeau existe déjà, sans quoi la
		 * date resterait figée à sa première valeur. Comme elle est maintenant
		 * affichée dans la liste des commandes, elle doit rester juste.
		 */
		if ( '' !== $latest && $latest !== (string) $order->get_meta( Legacy::ORDER_DATE_MAX_META ) ) {
			$order->update_meta_data( Legacy::ORDER_DATE_MAX_META, $latest );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Horodate la levée de la précommande quand la ligne devient complète.
	 *
	 * Écouté sur l'action émise par le module Préparation : les deux modules
	 * restent ainsi indépendants l'un de l'autre.
	 *
	 * @param \WC_Order_Item_Product $item     Ligne de commande.
	 * @param int                    $prepared Quantité préparée après écriture.
	 * @param int                    $quantity Quantité de la ligne.
	 */
	public static function maybe_stamp_filled( $item, $prepared, $quantity ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		if ( (int) $prepared < (int) $quantity || (int) $quantity <= 0 ) {
			return;
		}

		if ( self::line_preorder_quantity( $item ) <= 0 ) {
			return;
		}

		// Posé une seule fois : la première couverture fait foi, un dépointage
		// suivi d'un repointage ne doit pas réécrire la date.
		if ( $item->get_meta( Legacy::ITEM_FILLED_META ) ) {
			return;
		}

		$item->update_meta_data( Legacy::ITEM_FILLED_META, time() );
		$item->save();
	}

	/**
	 * Quantité précommandée portée par une ligne.
	 *
	 * @param \WC_Order_Item_Product $item Ligne de commande.
	 *
	 * @return int
	 */
	public static function line_preorder_quantity( $item ): int {
		return max( 0, (int) $item->get_meta( Legacy::ITEM_QTY_META ) );
	}

	/**
	 * Cette commande contient-elle des articles précommandés ?
	 *
	 * Lit le drapeau posé au niveau de la COMMANDE, jamais les lignes. C'est toute
	 * la raison d'être de ce drapeau : sur une liste de vingt commandes,
	 * order_has_marked_line() instancierait une soixantaine d'objets de ligne sous
	 * HPOS, et coûterait environ cent cinquante requêtes en stockage historique,
	 * pour un booléen déjà chargé avec la commande.
	 *
	 * Corollaire assumé : la couverture de ce marqueur vaut exactement celle du
	 * marquage. Une commande que la reprise d'historique n'a pas pu marquer
	 * n'affichera rien — elle n'apparaît pas non plus dans la vue « Précommandes ».
	 *
	 * @param \WC_Order|int $order_or_id Commande (HPOS) ou identifiant (historique).
	 *
	 * @return bool
	 */
	public static function order_has_preorder( $order_or_id ): bool {
		return '' !== self::order_meta( $order_or_id, Legacy::ORDER_FLAG_META );
	}

	/**
	 * Date d'expédition annoncée la plus lointaine de la commande.
	 *
	 * @param \WC_Order|int $order_or_id Commande (HPOS) ou identifiant (historique).
	 *
	 * @return string Format AAAA-MM-JJ, chaîne vide si aucune ligne ne portait de date.
	 */
	public static function order_preorder_date( $order_or_id ): string {
		return self::order_meta( $order_or_id, Legacy::ORDER_DATE_MAX_META );
	}

	/**
	 * Lit une méta de commande au coût le plus bas selon le mode de stockage.
	 *
	 * Sous HPOS l'objet arrive déjà hydraté : la liste des commandes charge les
	 * métas de ses vingt commandes en UNE requête groupée
	 * (CustomMetaDataStore::get_meta_data_for_object_ids), puis les injecte dans
	 * chaque objet. get_meta() n'est donc qu'un parcours de tableau.
	 *
	 * En stockage historique on ne reconstruit surtout PAS un WC_Order :
	 * get_post_meta() est servi par le cache que WP_Query vient d'amorcer, là où
	 * wc_get_order() referait toute la lecture de la commande.
	 *
	 * @param \WC_Order|int $order_or_id Commande ou identifiant.
	 * @param string        $key         Clé de méta.
	 *
	 * @return string
	 */
	private static function order_meta( $order_or_id, string $key ): string {
		if ( $order_or_id instanceof \WC_Order ) {
			return (string) $order_or_id->get_meta( $key );
		}

		$id = absint( $order_or_id );

		return $id > 0 ? (string) get_post_meta( $id, $key, true ) : '';
	}

	/**
	 * Une ligne au moins porte-t-elle un marqueur de précommande ?
	 *
	 * @param \WC_Order $order Commande.
	 *
	 * @return bool
	 */
	public static function order_has_marked_line( $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( self::line_preorder_quantity( $item ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
