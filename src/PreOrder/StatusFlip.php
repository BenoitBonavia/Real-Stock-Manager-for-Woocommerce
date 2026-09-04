<?php
/**
 * Bascule automatique vers le statut « Précommande ».
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

use RSMW\Preparation\Config as PreparationConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Place une commande contenant des articles précommandés dans le statut dédié.
 *
 * C'est le retour de l'automatisme du snippet remplacé — mais il ne porte plus
 * la même responsabilité. Avant, le statut ÉTAIT la traçabilité : la faire
 * reposer sur une valeur unique la faisait disparaître dès que la commande
 * avançait, et c'est ce que le marchand nous a demandé de corriger. Maintenant
 * la trace vit dans les métas de ligne ; le statut n'est plus qu'un état de flux,
 * donc l'automatiser redevient sans danger.
 *
 * Trois différences avec le snippet, chacune corrigeant un défaut constaté :
 *
 * 1. La décision se prend sur le MARQUEUR, pas sur `is_on_backorder()`. Cette
 *    méthode lit l'état courant du produit, sans contexte de commande : rejouée
 *    après l'achat, elle répond « non » pour une commande pourtant précommandée
 *    dès que la marchandise est revenue. Le marqueur, lui, dit ce qui a été
 *    réellement vendu.
 * 2. La bascule n'a lieu QU'UNE FOIS. Le snippet la rejouait à chaque passage en
 *    « En cours » : sortir une commande de « Précommande » à la main était donc
 *    impossible, elle y retombait.
 * 3. Elle est SUSPENDUE tant que « Précommande » n'est pas un statut suivi
 *    (cf. Config::status_is_tracked). Sans cela, l'automatisme sortirait
 *    silencieusement chaque précommande du circuit de préparation.
 *
 * La sortie du statut n'a pas besoin de code : `Preparation\StatusSync` fait
 * passer la commande en « À empaqueter » dès que toutes ses lignes sont pointées,
 * et mémorise « Précommande » comme statut de retour si une ligne redevient
 * incomplète.
 */
final class StatusFlip {

	/**
	 * Accroche la bascule.
	 */
	public static function register(): void {
		/*
		 * `woocommerce_order_status_changed` plutôt que
		 * `woocommerce_order_status_processing` du snippet : il est émis APRÈS
		 * `woocommerce_order_status_{from}_to_{to}`, donc après l'envoi de l'email
		 * client de la transition d'origine (includes/class-wc-order.php). Le
		 * client reçoit bien sa confirmation de commande, puis voit
		 * « Précommande » dans son espace.
		 *
		 * Et il couvre TOUS les statuts suivis, pas seulement « En cours » : une
		 * boutique qui encaisse par virement passe par « En attente ».
		 *
		 * PRIORITÉ 30, et ce n'est pas cosmétique. Preparation\Allocator est
		 * accroché au MÊME hook en priorité 20 : il sert la commande dans le stock
		 * libre puis, si elle devient complète, appelle StatusSync qui la passe en
		 * « À empaqueter ». En 20 nous serions à égalité avec lui, donc départagés
		 * par l'ordre d'enregistrement des modules — et nous écraserions un
		 * « À empaqueter » tout juste posé. La bascule doit avoir le dernier mot,
		 * et ne parler que si la commande attend encore.
		 */
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_apply' ), 30, 4 );
	}

	/**
	 * Applique le statut si la commande contient des articles précommandés.
	 *
	 * @param int             $order_id Identifiant de commande.
	 * @param string          $from     Statut précédent.
	 * @param string          $to       Nouveau statut.
	 * @param \WC_Order|mixed $order    Commande.
	 */
	public static function maybe_apply( $order_id, $from, $to, $order ): void {
		/*
		 * Les trois derniers arguments sont IGNORÉS, délibérément. `$to` est figé
		 * au moment où la transition a été calculée, et `$order` est l'objet qui
		 * l'a émise : entre-temps, Allocator et StatusSync ont pu faire passer la
		 * commande en « À empaqueter » sur un AUTRE objet, rechargé. Décider sur
		 * ces valeurs, c'est décider sur un état périmé — et enregistrer l'objet
		 * périmé écraserait le travail des autres.
		 */
		unset( $from, $to, $order );

		if ( ! Config::auto_status_is_operative() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$current = $order->get_status();
		$applied = '' !== (string) $order->get_meta( Legacy::STATUS_APPLIED_META );

		/*
		 * La commande est déjà en « Précommande ». On pose quand même le témoin,
		 * s'il manque : le statut a bien été appliqué, peu importe par qui. Sans
		 * cela, une pose à la main ne compterait pas, et la bascule se rejouerait
		 * plus tard — exactement le défaut du snippet remplacé, qui rendait
		 * impossible de sortir une commande de ce statut.
		 */
		if ( Legacy::STATUS_SLUG === $current ) {
			if ( ! $applied && Marker::order_has_preorder( $order ) ) {
				$order->update_meta_data( Legacy::STATUS_APPLIED_META, 1 );
				$order->save();
			}

			return;
		}

		if ( $applied ) {
			return;
		}

		/*
		 * Le statut RÉEL doit être suivi. Cette seule condition écarte deux cas :
		 * « À empaqueter », qui signifie que la marchandise est là — y ramener une
		 * précommande annulerait le travail de StatusSync — et les statuts
		 * terminaux (Terminée, Annulée, Remboursée), qu'on ne rouvre jamais.
		 */
		if ( ! in_array( $current, PreparationConfig::statuses(), true ) ) {
			return;
		}

		if ( ! Marker::order_has_preorder( $order ) ) {
			return;
		}

		$order->update_meta_data( Legacy::STATUS_APPLIED_META, 1 );

		$order->set_status(
			Legacy::STATUS_SLUG,
			__( 'Statut posé automatiquement : la commande contient des articles précommandés.', 'real-stock-manager-for-woocommerce' )
		);

		$order->save();
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
