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
 *
 * DIFFÉRÉ via Action Scheduler depuis la 0.9.0, et ce n'est pas cosmétique non
 * plus. Poser le second statut ré-émet `woocommerce_order_status_changed` — donc
 * rejoue TOUS ses écouteurs, Preparation\Allocator compris — une seconde fois
 * dans la même requête que le paiement. Sur une commande précommandée, cette
 * requête est déjà celle où la passerelle Stripe crée l'intention, débite la
 * carte et attend une réponse JSON pour débloquer le client ; doubler son coût
 * dedans est le seul facteur qui distingue une précommande d'une commande
 * normale à cet instant précis, et le suspect le plus crédible d'une
 * resoumission de paiement côté navigateur. On se contente donc ici de
 * PLANIFIER la bascule (une simple ligne en base, via Action Scheduler), et on
 * l'exécute dans `run()`, hors de la réponse HTTP du paiement.
 */
final class StatusFlip {

	/**
	 * Hook Action Scheduler sur lequel la bascule différée s'exécute.
	 *
	 * @var string
	 */
	public const RUN_HOOK = 'rsmw_preorder_status_flip';

	/**
	 * Groupe Action Scheduler, pour repérer nos tâches dans Outils > Actions
	 * planifiées sans avoir à les distinguer une par une.
	 *
	 * @var string
	 */
	public const GROUP = 'rsmw-preorder';

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
		 * PRIORITÉ 30 : Preparation\Allocator est accroché au MÊME hook en
		 * priorité 20. Ici on ne fait que DÉCIDER s'il faut planifier une bascule
		 * — l'exécuter est repoussé dans `run()` — donc l'ordre avec Allocator
		 * n'a plus d'incidence sur cet appel-ci ; il en garde une sur `run()`,
		 * documentée sur cette méthode.
		 */
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_schedule' ), 30, 4 );

		add_action( self::RUN_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Décide s'il faut planifier la bascule, sans jamais changer le statut ici.
	 *
	 * @param int             $order_id Identifiant de commande.
	 * @param string          $from     Statut précédent.
	 * @param string          $to       Nouveau statut.
	 * @param \WC_Order|mixed $order    Commande.
	 */
	public static function maybe_schedule( $order_id, $from, $to, $order ): void {
		/*
		 * Les trois derniers arguments sont IGNORÉS, délibérément. `$to` est figé
		 * au moment où la transition a été calculée, et `$order` est l'objet qui
		 * l'a émise : entre-temps, Allocator a pu faire passer la commande en
		 * « À empaqueter » sur un AUTRE objet, rechargé. Décider sur ces valeurs,
		 * c'est décider sur un état périmé.
		 */
		unset( $from, $to, $order );

		$order = self::eligible_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		/*
		 * `as_next_scheduled_action` évite un doublon si la commande retraverse
		 * plusieurs statuts suivis avant que la tâche planifiée n'ait tourné (ex.
		 * « En attente » → « En cours » rapprochés) : chaque passage ici
		 * replanifierait sinon une tâche identique.
		 */
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::RUN_HOOK, array( $order_id ), self::GROUP ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::RUN_HOOK, array( $order_id ), self::GROUP );
		} else {
			// Repli si Action Scheduler n'est, pour une raison quelconque, pas
			// chargé : on applique tout de suite plutôt que de perdre la bascule.
			self::run( $order_id );
		}
	}

	/**
	 * Applique réellement le statut. Tourne hors de la requête de paiement.
	 *
	 * Tout est revérifié ici à neuf, via `eligible_order()` : l'état a pu
	 * changer entre la planification et l'exécution (commande annulée
	 * entre-temps, statut posé à la main par le marchand, module désactivé).
	 * Reprendre l'état lu au moment de `maybe_schedule()` serait décider sur du
	 * périmé.
	 *
	 * PRIORITÉ avec Allocator : ici, contrairement à `maybe_schedule()`, on
	 * CHANGE le statut, donc on rejoue `woocommerce_order_status_changed` —
	 * Allocator (priorité 20) tourne avant nous s'il réagit à CETTE
	 * transition-ci. Comme il l'a déjà fait, sur ce même order_id, au moment du
	 * paiement, ce second passage ne fait rien de plus que réévaluer un état
	 * déjà à jour : sans effet notable, mais plus dans la réponse HTTP du
	 * client.
	 *
	 * @param int $order_id Identifiant de commande.
	 */
	public static function run( $order_id ): void {
		$order = self::eligible_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
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
	 * Charge la commande et vérifie si elle doit encore basculer.
	 *
	 * Factorise les gardes communs à `maybe_schedule()` (qui ne fait que
	 * PLANIFIER) et `run()` (qui bascule pour de vrai) : les deux doivent
	 * écarter exactement les mêmes cas, sinon l'un planifierait ce que l'autre
	 * refuserait d'appliquer.
	 *
	 * @param int $order_id Identifiant de commande.
	 *
	 * @return \WC_Order|null La commande si elle doit basculer, `null` sinon
	 *                        — y compris quand le témoin a été posé au passage
	 *                        sur une commande déjà en « Précommande ».
	 */
	private static function eligible_order( $order_id ): ?\WC_Order {
		if ( ! Config::auto_status_is_operative() ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$current = $order->get_status();
		$applied = '' !== (string) $order->get_meta( Legacy::STATUS_APPLIED_META );

		/*
		 * La commande est déjà en « Précommande ». On pose quand même le témoin,
		 * s'il manque : le statut a bien été appliqué, peu importe par qui. Sans
		 * cela, une pose à la main ne compterait pas, et la bascule se rejouerait
		 * plus tard — exactement le défaut du snippet remplacé, qui rendait
		 * impossible de sortir une commande de ce statut. Une simple pose de
		 * méta ne rejoue pas les écouteurs de `woocommerce_order_status_changed`
		 * (elle ne change pas le statut), donc rien à planifier pour ce cas.
		 */
		if ( Legacy::STATUS_SLUG === $current ) {
			if ( ! $applied && Marker::order_has_preorder( $order ) ) {
				$order->update_meta_data( Legacy::STATUS_APPLIED_META, 1 );
				$order->save();
			}

			return null;
		}

		if ( $applied ) {
			return null;
		}

		/*
		 * Le statut RÉEL doit être suivi. Cette seule condition écarte deux cas :
		 * « À empaqueter », qui signifie que la marchandise est là — y ramener une
		 * précommande annulerait le travail de StatusSync — et les statuts
		 * terminaux (Terminée, Annulée, Remboursée), qu'on ne rouvre jamais.
		 */
		if ( ! in_array( $current, PreparationConfig::statuses(), true ) ) {
			return null;
		}

		if ( ! Marker::order_has_preorder( $order ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
