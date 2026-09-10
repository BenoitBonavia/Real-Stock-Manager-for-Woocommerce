<?php
/**
 * Point d'isolement du plugin hôte « Back In Stock Notifier for WooCommerce ».
 *
 * @package RealStockManager
 */

namespace RSMW\BackInStock;

defined( 'ABSPATH' ) || exit;

/**
 * Tout ce que ce plugin sait de « Back In Stock Notifier for WooCommerce »
 * (ProPluginsLab). Aucune autre classe ne doit écrire en dur `cwginstock`,
 * `cwg_*` ni `CWGINSTOCK_*` : le jour où l'extension hôte renomme une option,
 * un statut ou une métadonnée, seul ce fichier bouge.
 *
 * Lecture exclusivement en base — jamais d'appel à une classe `CWG_*` de
 * l'hôte : l'intégration reste une dépendance souple, comme celle de
 * `RSMW\Preparation\Cost` avec « Cost of Goods for WooCommerce ». Le plugin
 * hôte peut être absent, désactivé ou remplacé sans casser l'amorçage.
 *
 * Relevé sur la version 7.4.2 (téléchargée depuis WordPress.org le
 * 2026-09-10, fichiers includes/class-api.php, includes/admin/class-post-type.php
 * et includes/class-quantity-field.php).
 */
final class Host {

	/** Slug de l'extension, tel que WordPress.org l'expose. */
	public const SLUG = 'back-in-stock-notifier-for-woocommerce';

	/** Chemin du fichier principal, relatif au dossier des extensions. */
	public const BASENAME = 'back-in-stock-notifier-for-woocommerce/cwginstocknotifier.php';

	/** Constante de version posée par l'extension hôte. */
	public const VERSION_CONSTANT = 'CWGINSTOCK_VERSION';

	/** Type de contenu des inscriptions à une alerte de retour en stock. */
	public const SUBSCRIBER_TYPE = 'cwginstocknotifier';

	/*
	 * ---------------------------------------------------------------------
	 * Statuts d'inscription — les six enregistrés par la version gratuite.
	 * ---------------------------------------------------------------------
	 */

	/** Inscrit, en attente de réapprovisionnement. */
	public const STATUS_SUBSCRIBED = 'cwg_subscribed';

	/** Mis en file d'envoi. */
	public const STATUS_QUEUED = 'cwg_queued';

	/** Alerte de retour en stock envoyée. */
	public const STATUS_MAILSENT = 'cwg_mailsent';

	/** Échec d'envoi de l'alerte. */
	public const STATUS_MAILNOTSENT = 'cwg_mailnotsent';

	/** « Purchased ». Jamais posé par la version gratuite de l'hôte seule. */
	public const STATUS_CONVERTED = 'cwg_converted';

	/** Désinscrit. */
	public const STATUS_UNSUBSCRIBED = 'cwg_unsubscribed';

	/*
	 * ---------------------------------------------------------------------
	 * Métadonnées d'inscription.
	 * ---------------------------------------------------------------------
	 */

	/** ID du produit PARENT, toujours — jamais celui d'une variation. */
	public const META_PRODUCT_ID = 'cwginstock_product_id';

	/** ID de la variation attendue, `0` pour un produit simple. */
	public const META_VARIATION_ID = 'cwginstock_variation_id';

	/**
	 * Produit effectivement attendu : la variation s'il y en a une, sinon le
	 * produit. C'est la clé de référence de stock du module Préparation.
	 */
	public const META_PID = 'cwginstock_pid';

	/**
	 * Variation ayant déclenché l'alerte d'un inscrit « produit parent ».
	 *
	 * Posée par l'hôte uniquement quand le réglage
	 * `variable_any_variation_backinstock` est actif : un client peut alors
	 * s'inscrire sur un produit variable entier (`cwginstock_pid` = parent),
	 * et l'hôte grave ici l'ID de la variation qui a déclenché l'envoi, sans
	 * jamais l'effacer ensuite. Une inscription « parent » n'a donc PAS de
	 * référence de stock propre tant que ce champ est vide.
	 */
	public const META_BYPASS_PID = 'cwginstock_bypass_pid';

	/**
	 * Quantité demandée par l'inscrit.
	 *
	 * Absente si le champ « Quantité » est désactivé côté hôte
	 * (réglage `enable_quantity_field`) — dans ce cas comme dans celui d'une
	 * valeur vide ou nulle, l'hôte lui-même retombe sur 1 (voir
	 * `CWG_Instock_Quantity_Field::add_quantity_shortcode()`).
	 */
	public const META_QUANTITY = 'cwginstock_custom_quantity';

	/** Horodatage UNIX UTC de l'envoi de l'alerte. */
	public const META_MAIL_ON = 'cwginstock_mail_on';

	/*
	 * ---------------------------------------------------------------------
	 * Réglages de l'extension hôte.
	 * ---------------------------------------------------------------------
	 */

	/** Option principale de l'extension hôte (tableau de réglages). */
	public const SETTINGS_OPTION = 'cwginstocksettings';

	/** Clé : champ « quantité » affiché sur le formulaire d'inscription. */
	public const SETTING_QUANTITY_FIELD = 'enable_quantity_field';

	/** Clé : une inscription au produit parent variable vaut pour toute variation. */
	public const SETTING_VARIABLE_ANY_VARIATION = 'variable_any_variation_backinstock';

	/** Clé : suppression automatique des inscrits après un délai. */
	public const SETTING_AUTO_DELETE = 'enable_auto_delete';

	/** Clé : délai de rétention, en jours, avant suppression automatique. */
	public const SETTING_AUTO_DELETE_DAYS = 'delete_subscribers_for_x_days';

	/** Rétention appliquée par l'hôte quand la valeur n'est pas renseignée. */
	public const AUTO_DELETE_DEFAULT_DAYS = 7;

	/**
	 * L'extension hôte est-elle chargée ET démarrée ?
	 *
	 * On teste la CONSTANTE, jamais `class_exists()` : la classe principale de
	 * l'hôte peut être déclarée sans que WooCommerce soit actif, auquel cas
	 * l'extension ne fait rien du tout.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( self::VERSION_CONSTANT );
	}

	/**
	 * L'extension hôte est-elle installée, active ou non ?
	 *
	 * Sert uniquement à orienter le message affiché quand elle est absente :
	 * « installez-la » et « activez-la » n'appellent pas le même lien.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array_key_exists( self::BASENAME, get_plugins() );
	}

	/**
	 * Libellé lisible d'un statut d'inscription.
	 *
	 * L'hôte ne les enregistre qu'en anglais (`register_post_status()`) : ce
	 * plugin ne peut pas les récupérer traduits, et les recopier en dur reste
	 * plus honnête qu'un slug nu à l'écran.
	 *
	 * @param string $status Slug de statut.
	 *
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			self::STATUS_SUBSCRIBED   => __( 'En attente', 'real-stock-manager-for-woocommerce' ),
			self::STATUS_QUEUED       => __( 'En file d’envoi', 'real-stock-manager-for-woocommerce' ),
			self::STATUS_MAILSENT     => __( 'Alerte envoyée', 'real-stock-manager-for-woocommerce' ),
			self::STATUS_MAILNOTSENT  => __( 'Échec d’envoi', 'real-stock-manager-for-woocommerce' ),
			self::STATUS_CONVERTED    => __( 'Achetée', 'real-stock-manager-for-woocommerce' ),
			self::STATUS_UNSUBSCRIBED => __( 'Désinscrite', 'real-stock-manager-for-woocommerce' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Statuts d'inscription enregistrés par la version gratuite de l'hôte.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_SUBSCRIBED,
			self::STATUS_QUEUED,
			self::STATUS_MAILSENT,
			self::STATUS_MAILNOTSENT,
			self::STATUS_CONVERTED,
			self::STATUS_UNSUBSCRIBED,
		);
	}

	/**
	 * Réglages de l'extension hôte.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		return (array) get_option( self::SETTINGS_OPTION, array() );
	}

	/**
	 * Le champ « quantité » est-il affiché sur le formulaire d'inscription ?
	 *
	 * @return bool
	 */
	public static function quantity_field_enabled(): bool {
		$settings = self::settings();

		return isset( $settings[ self::SETTING_QUANTITY_FIELD ] )
			&& '1' === (string) $settings[ self::SETTING_QUANTITY_FIELD ];
	}

	/**
	 * Une inscription au produit parent variable vaut-elle pour toute variation ?
	 *
	 * @return bool
	 */
	public static function variable_any_variation_enabled(): bool {
		$settings = self::settings();

		return isset( $settings[ self::SETTING_VARIABLE_ANY_VARIATION ] )
			&& '1' === (string) $settings[ self::SETTING_VARIABLE_ANY_VARIATION ];
	}

	/**
	 * L'extension hôte supprime-t-elle automatiquement ses inscrits ?
	 *
	 * Réglage lourd de conséquences pour cet écran : passé le délai, l'hôte
	 * efface DÉFINITIVEMENT les inscriptions « Mail Sent », « Unsubscribed »
	 * et « Purchased ». Le dénominateur de la couverture affichée ici fond
	 * en silence — d'où le diagnostic qui le signale.
	 *
	 * @return bool
	 */
	public static function auto_delete_enabled(): bool {
		$settings = self::settings();

		return isset( $settings[ self::SETTING_AUTO_DELETE ] )
			&& '1' === (string) $settings[ self::SETTING_AUTO_DELETE ];
	}

	/**
	 * Rétention appliquée par la suppression automatique de l'hôte, en jours.
	 *
	 * @return int
	 */
	public static function auto_delete_days(): int {
		$settings = self::settings();
		$days     = isset( $settings[ self::SETTING_AUTO_DELETE_DAYS ] )
			? (int) $settings[ self::SETTING_AUTO_DELETE_DAYS ]
			: 0;

		return $days > 0 ? $days : self::AUTO_DELETE_DEFAULT_DAYS;
	}

	/**
	 * URL de la fiche d'installation de l'extension hôte.
	 *
	 * @return string
	 */
	public static function install_url(): string {
		return self_admin_url( 'plugin-install.php?tab=search&s=' . rawurlencode( self::SLUG ) . '&type=term' );
	}

	/**
	 * URL de la liste des extensions installées, pour l'activer.
	 *
	 * @return string
	 */
	public static function plugins_url(): string {
		return self_admin_url( 'plugins.php' );
	}

	/**
	 * Nom lisible de l'extension hôte.
	 *
	 * Volontairement non traduit : c'est un nom propre, il doit rester
	 * cherchable tel quel dans l'écran d'ajout d'extensions.
	 *
	 * @return string
	 */
	public static function name(): string {
		return 'Back In Stock Notifier for WooCommerce';
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
