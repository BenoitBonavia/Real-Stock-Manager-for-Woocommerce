<?php
/**
 * Bascule automatique du statut de commande.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Fait passer une commande en « À empaqueter » quand toutes ses lignes sont
 * pointées, et la ramène à son statut précédent si une ligne redevient incomplète.
 *
 * Ces transitions sont internes à la préparation : le client ne doit recevoir
 * aucun email. D'où le silence temporaire installé autour de chaque changement.
 */
final class StatusSync {

	/**
	 * Emails clients neutralisés pendant une transition interne.
	 *
	 * @var string[]
	 */
	private const SILENCED_EMAILS = array(
		'customer_processing_order',
		'customer_on_hold_order',
		'customer_completed_order',
	);

	/**
	 * Une transition interne est-elle en cours ?
	 *
	 * @var bool
	 */
	private static $silent = false;

	/**
	 * Installe le filtre de neutralisation des emails.
	 */
	public static function register(): void {
		foreach ( self::SILENCED_EMAILS as $email ) {
			add_filter(
				'woocommerce_email_recipient_' . $email,
				array( __CLASS__, 'filter_recipient' ),
				99
			);
		}
	}

	/**
	 * Vide le destinataire pendant une transition interne.
	 *
	 * @param string $recipient Destinataire calculé par WooCommerce.
	 *
	 * @return string
	 */
	public static function filter_recipient( $recipient ) {
		return self::$silent ? '' : $recipient;
	}

	/**
	 * Aligne le statut de la commande sur l'état de sa préparation.
	 *
	 * @param \WC_Order $order Commande.
	 *
	 * @return string Statut après synchronisation.
	 */
	public static function sync( $order ): string {
		$status  = $order->get_status();
		$ready   = Items::order_is_ready( $order );
		$actives = Config::statuses();

		if ( $ready && in_array( $status, $actives, true ) ) {
			$order->update_meta_data( Legacy::PREV_STATUS_META, $status );

			self::apply_status(
				$order,
				Legacy::STATUS_SLUG,
				__( 'Toutes les lignes sont préparées.', 'real-stock-manager-for-woocommerce' )
			);

			Log::info( sprintf( 'Commande %d → À empaqueter.', $order->get_id() ) );

			return Legacy::STATUS_SLUG;
		}

		if ( ! $ready && Legacy::STATUS_SLUG === $status ) {
			$previous = $order->get_meta( Legacy::PREV_STATUS_META );

			if ( ! $previous || ! in_array( $previous, $actives, true ) ) {
				$previous = $actives ? $actives[0] : 'processing';
			}

			self::apply_status(
				$order,
				$previous,
				__( 'Une ligne n’est plus complète.', 'real-stock-manager-for-woocommerce' )
			);

			Log::info( sprintf( 'Commande %d → retour %s.', $order->get_id(), $previous ) );

			return $previous;
		}

		return $status;
	}

	/**
	 * Applique un statut sans notifier le client.
	 *
	 * Le drapeau est remis en place dans un `finally` : si l'enregistrement lève
	 * une exception, le laisser à `true` couperait tous les emails clients pour le
	 * reste de la requête, sans la moindre trace.
	 *
	 * @param \WC_Order $order  Commande.
	 * @param string    $status Statut visé, slug nu.
	 * @param string    $note   Note de transition.
	 */
	private static function apply_status( $order, string $status, string $note ): void {
		$previous     = self::$silent;
		self::$silent = true;

		try {
			$order->set_status( $status, $note );
			$order->save();
		} finally {
			self::$silent = $previous;
		}
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
