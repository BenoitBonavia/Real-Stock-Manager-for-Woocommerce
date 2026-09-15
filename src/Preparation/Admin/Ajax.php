<?php
/**
 * Point d'entrée AJAX du pointage.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Allocator;
use RSMW\Preparation\Config;
use RSMW\Preparation\Items;
use RSMW\Preparation\Legacy;
use RSMW\Preparation\StatusSync;
use RSMW\Preparation\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Applique un pointage et renvoie l'état à jour de la commande.
 */
final class Ajax {

	/**
	 * Accroche l'action AJAX.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . Legacy::AJAX_ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Traite la requête de pointage.
	 */
	public static function handle(): void {
		$order_id = isset( $_POST['order'] ) ? absint( wp_unslash( $_POST['order'] ) ) : 0;

		check_ajax_referer( self::nonce_action( $order_id ), 'nonce' );

		// Meta-capability par objet : sous HPOS comme en stockage historique,
		// WooCommerce résout correctement les droits propres à CETTE commande,
		// contrairement à la capability globale 'edit_shop_orders'.
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_send_json_error( __( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( __( 'Commande introuvable.', 'real-stock-manager-for-woocommerce' ) );
		}

		// Hors périmètre : ni de quoi rendre, ni de quoi resynchroniser — le
		// stock prélevé depuis un tel écran deviendrait irrécupérable, puisque
		// ni active_order_ids() ni holder_order_ids() ne verraient plus la commande.
		if ( ! in_array( $order->get_status(), array_merge( Config::statuses(), array( Legacy::STATUS_SLUG ) ), true ) ) {
			wp_send_json_error( __( 'Cette commande n’est plus dans le périmètre de préparation.', 'real-stock-manager-for-woocommerce' ) );
		}

		$status_before = $order->get_status();

		// Écart entre ce qui a été demandé et ce que le stock libre a permis
		// d'appliquer — alimente le message renvoyé plus bas, sans jamais
		// prendre le pas sur un message de bascule de statut.
		$requested = 0;
		$applied   = 0;

		if ( isset( $_POST['all'] ) ) {

			$full = (bool) absint( wp_unslash( $_POST['all'] ) );

			$stats = Allocator::without_auto_allocation(
				static function () use ( $order, $full ) {
					$requested = 0;
					$applied   = 0;

					foreach ( $order->get_items() as $item ) {
						$before = Items::prepared( $item );
						$target = $full ? (int) $item->get_quantity() : 0;
						$result = Items::set_quantity( $item, $target );

						if ( $full ) {
							$requested += max( 0, $target - $before );
							$applied   += max( 0, (int) $result['delta'] );
						}
					}

					return array(
						'requested' => $requested,
						'applied'   => $applied,
					);
				}
			);

			$requested = $stats['requested'];
			$applied   = $stats['applied'];
		} else {

			$item_id = isset( $_POST['item'] ) ? absint( wp_unslash( $_POST['item'] ) ) : 0;
			$delta   = isset( $_POST['delta'] ) ? (int) wp_unslash( $_POST['delta'] ) : 0;

			// Seul contrat émis par l'interface : un pas d'une unité. Sans cette
			// borne, un $_POST forgé (delta=999) atteindrait le même état qu'« all=1 »
			// sans jamais passer par son garde-fou de compte rendu.
			$delta = max( -1, min( 1, $delta ) );

			// $load_from_db = false : la résolution passe par le data store de LA
			// commande, dont la requête est scopée par order_id (vérifié y compris
			// sous HPOS). Avec le paramètre par défaut, WC_Order_Factory résout
			// l'identifiant de ligne GLOBALEMENT, ce qui permettrait d'écrire sur la
			// ligne d'une autre commande avec le nonce et l'order_id de celle-ci.
			$item = $order->get_item( $item_id, false );

			// Vérification d'appartenance défensive, en plus du scope de la requête
			// ci-dessus, et garde de type : une ligne 'fee'/'shipping' n'a pas de
			// get_variation_id(), utilisée par Items::key().
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_order_id() !== $order_id ) {
				wp_send_json_error( __( 'Ligne introuvable.', 'real-stock-manager-for-woocommerce' ) );
			}

			$result = Allocator::without_auto_allocation(
				static function () use ( $item, $delta ) {
					return Items::set_quantity( $item, Items::prepared( $item ) + $delta );
				}
			);

			if ( $delta > 0 ) {
				$requested = $delta;
				$applied   = max( 0, (int) $result['delta'] );
			}
		}

		// Relecture depuis la base : les objets en mémoire sont périmés.
		$order = wc_get_order( $order_id );

		$status_after = StatusSync::sync( $order );

		$lines = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$quantity = (int) $item->get_quantity();
			$prepared = min( $quantity, Items::prepared( $item ) );

			$lines[] = array(
				'item'    => (int) $item_id,
				'qty'     => $prepared,
				'ordered' => min( max( 0, $quantity - $prepared ), Items::ordered( $item ) ),
				'free'    => Stock::get( Items::key( $item ) ),
			);
		}

		list( $done, $ordered_total, $total ) = Items::order_coverage( $order );

		$message = __( 'Enregistré.', 'real-stock-manager-for-woocommerce' );
		$reload  = false;

		if ( $status_after !== $status_before ) {
			$reload = true;

			$message = Legacy::STATUS_SLUG === $status_after
				? __( 'Commande complète : passage en « À empaqueter ».', 'real-stock-manager-for-woocommerce' )
				: sprintf(
					/* translators: %s: libellé du statut de retour. */
					__( 'Commande incomplète : retour en « %s ».', 'real-stock-manager-for-woocommerce' ),
					wc_get_order_status_name( $status_after )
				);
		} elseif ( $applied < $requested ) {
			// Le pointage ne peut plus dépasser le stock libre : dit ici ce que le
			// clic n'a pas pu faire, plutôt que de laisser croire à un enregistrement
			// silencieusement partiel.
			$message = isset( $_POST['all'] )
				? sprintf(
					/* translators: 1: articles effectivement pointés, 2: articles demandés. */
					__( 'Stock libre insuffisant : %1$d article(s) pointé(s) sur %2$d demandé(s).', 'real-stock-manager-for-woocommerce' ),
					$applied,
					$requested
				)
				: __( 'Stock libre épuisé pour cette référence : rien n’a été pointé. Corrigez d’abord le stock depuis Gestion stock → Mouvement à l’unité.', 'real-stock-manager-for-woocommerce' );
		}

		$percent = $total > 0 ? (int) round( $done / $total * 100 ) : 0;

		wp_send_json_success(
			array(
				'lines'      => $lines,
				'done'       => $done,
				'ordered'    => $ordered_total,
				'total'      => $total,
				'pct'        => $percent,
				// Borné à la place restante : les deux arrondis peuvent dépasser 100 %.
				'orderedPct' => $total > 0
					? min( max( 0, 100 - $percent ), (int) round( $ordered_total / $total * 100 ) )
					: 0,
				'message'    => $message,
				'reload'     => $reload,
			)
		);
	}

	/**
	 * Action de nonce scopée à une commande.
	 *
	 * Un nonce global valait pour n'importe quel couple (commande, ligne) : un
	 * jeton obtenu en ouvrant une commande permettait d'écrire sur les lignes
	 * d'une autre. Utilisée à la fois ici et par Metabox::render(), qui génère
	 * le jeton affiché.
	 *
	 * @param int $order_id Identifiant de commande.
	 *
	 * @return string
	 */
	public static function nonce_action( int $order_id ): string {
		return Legacy::AJAX_NONCE . '_' . $order_id;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
