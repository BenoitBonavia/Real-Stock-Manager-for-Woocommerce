<?php
/**
 * Onglet de réglages WooCommerce.
 *
 * @package RealStockManager
 */

namespace RSMW\Admin;

use RSMW\Modules\ModuleInterface;
use RSMW\Plugin;
use RSMW\PreOrder\Migration as PreOrderMigration;
use RSMW\PreOrder\OrderStatus as PreOrderStatus;
use RSMW\PreOrder\SnippetGuard as PreOrderSnippetGuard;
use RSMW\Preparation\Config;
use RSMW\Preparation\Defects;
use RSMW\Preparation\Items;
use RSMW\Preparation\Journal;
use RSMW\Preparation\OrderStatus;
use RSMW\Preparation\SnippetGuard;
use RSMW\Preparation\Stock;
use RSMW\Preparation\Supply;
use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Réglages → Stocks réels.
 *
 * Les identifiants de champs servent directement de noms d'options : ils
 * doivent donc rester préfixés par `rsmw_` (cf. Settings::PREFIX).
 */
final class SettingsTab extends \WC_Settings_Page {

	/**
	 * Déclare l'onglet auprès de WooCommerce.
	 */
	public function __construct() {
		$this->id    = Admin::SETTINGS_TAB;
		$this->label = __( 'Stocks réels', 'real-stock-manager-for-woocommerce' );

		parent::__construct();
	}

	/**
	 * Sous-sections de l'onglet.
	 *
	 * @return array<string, string>
	 */
	protected function get_own_sections(): array {
		return array(
			''            => __( 'Général', 'real-stock-manager-for-woocommerce' ),
			'preparation' => __( 'Préparation', 'real-stock-manager-for-woocommerce' ),
			'modules'     => __( 'Modules', 'real-stock-manager-for-woocommerce' ),
		);
	}

	/**
	 * Champs de la section par défaut.
	 *
	 * @return array
	 */
	protected function get_settings_for_default_section(): array {
		$settings = array(
			array(
				'title' => __( 'Réglages généraux', 'real-stock-manager-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Comportement global de la gestion des stocks réels.', 'real-stock-manager-for-woocommerce' ),
				'id'    => Settings::PREFIX . 'general_options',
			),
			array(
				'title'    => __( 'Journalisation', 'real-stock-manager-for-woocommerce' ),
				'desc'     => __( 'Consigner les opérations de stock dans les journaux WooCommerce.', 'real-stock-manager-for-woocommerce' ),
				'desc_tip' => __( 'Visible dans WooCommerce → État → Journaux, source « real-stock-manager ».', 'real-stock-manager-for-woocommerce' ),
				'id'       => Settings::PREFIX . 'enable_logging',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'    => __( 'Effacer les données à la désinstallation', 'real-stock-manager-for-woocommerce' ),
				'desc'     => __( 'Supprimer le stock physique, les pointages et le journal si l’extension est supprimée.', 'real-stock-manager-for-woocommerce' ),
				'desc_tip' => __( 'Décoché, la suppression de l’extension laisse vos données intactes : vous pourrez la réinstaller, ou revenir au snippet, sans rien perdre.', 'real-stock-manager-for-woocommerce' ),
				'id'       => Settings::PREFIX . 'delete_data_on_uninstall',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'general_options',
			),
		);

		return $settings;
	}

	/**
	 * Champs de la section « Préparation ».
	 *
	 * @return array
	 */
	protected function get_settings_for_preparation_section(): array {
		$overrides = Config::constant_overrides();

		$status_options = array();

		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$clean = preg_replace( '/^wc-/', '', $slug );

			if ( \RSMW\Preparation\Legacy::STATUS_SLUG === $clean ) {
				continue;
			}

			$status_options[ $clean ] = $label . ' (' . $clean . ')';
		}

		return array(
			array(
				'title' => __( 'Préparation des commandes', 'real-stock-manager-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Périmètre et automatismes de la préparation des commandes.', 'real-stock-manager-for-woocommerce' ),
				'id'    => Settings::PREFIX . 'preparation_options',
			),
			array(
				'title'    => __( 'Statuts à préparer', 'real-stock-manager-for-woocommerce' ),
				'desc'     => $this->override_note(
					$overrides,
					Config::KEY_STATUSES,
					__( 'Commandes prises en compte dans la table des besoins et servies par l’attribution automatique.', 'real-stock-manager-for-woocommerce' )
				),
				'id'       => Settings::PREFIX . Config::KEY_STATUSES,
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width: 350px;',
				'options'  => $status_options,
				'default'  => Config::DEFAULT_STATUSES,
				'desc_tip' => false,
			),
			array(
				'title'    => __( 'Attribution automatique', 'real-stock-manager-for-woocommerce' ),
				'desc'     => $this->override_note(
					$overrides,
					Config::KEY_AUTO_ALLOCATE,
					__( 'Servir une commande dans le stock libre dès qu’elle entre dans le périmètre.', 'real-stock-manager-for-woocommerce' )
				),
				'id'       => Settings::PREFIX . Config::KEY_AUTO_ALLOCATE,
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => false,
			),
			array(
				'title'             => __( 'Durée du cache', 'real-stock-manager-for-woocommerce' ),
				'desc'              => $this->override_note(
					$overrides,
					Config::KEY_CACHE_TTL,
					__( 'En secondes. Ne concerne pas la page « Besoins & stock », qui recalcule à chaque affichage.', 'real-stock-manager-for-woocommerce' )
				),
				'id'                => Settings::PREFIX . Config::KEY_CACHE_TTL,
				'type'              => 'number',
				'default'           => Config::DEFAULT_CACHE_TTL,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'preparation_options',
			),
			array(
				'title' => __( 'Diagnostic', 'real-stock-manager-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => $this->diagnostics_html(),
				'id'    => Settings::PREFIX . 'preparation_diagnostics',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'preparation_diagnostics',
			),
		);
	}

	/**
	 * Complète la description d'un champ imposé par une constante.
	 *
	 * Le champ reste modifiable : le désactiver ferait enregistrer une valeur vide
	 * par WooCommerce, et la configuration serait perdue le jour où la constante
	 * disparaîtrait.
	 *
	 * @param array  $overrides   Réglages imposés par une constante.
	 * @param string $key         Clé du réglage.
	 * @param string $description Description de base.
	 *
	 * @return string
	 */
	private function override_note( array $overrides, string $key, string $description ): string {
		if ( ! isset( $overrides[ $key ] ) ) {
			return $description;
		}

		return $description . '<br><strong>' . esc_html(
			sprintf(
				/* translators: %s: nom de la constante PHP. */
				__( 'Actuellement imposé par la constante %s : ce réglage est ignoré tant qu’elle est définie.', 'real-stock-manager-for-woocommerce' ),
				$overrides[ $key ]
			)
		) . '</strong>';
	}

	/**
	 * Tableau de diagnostic de la reprise des données.
	 *
	 * Sert à constater d'un coup d'œil que le plugin voit bien les données
	 * laissées par le snippet qu'il remplace.
	 *
	 * @return string
	 */
	private function diagnostics_html(): string {
		$lines = array();

		if ( SnippetGuard::snippet_is_active() ) {
			$lines[] = '<strong style="color:#b32d2e">'
				. esc_html__( 'Snippet détecté : le module est en veille. Désactivez le snippet pour que le plugin prenne le relais.', 'real-stock-manager-for-woocommerce' )
				. '</strong>';
		} else {
			$lines[] = '<span style="color:#00a32a">'
				. esc_html__( 'Aucun snippet concurrent détecté.', 'real-stock-manager-for-woocommerce' )
				. '</span>';
		}

		$lines[] = sprintf(
			/* translators: 1: oui/non, 2: oui/non, 3: nombre de commandes. */
			esc_html__( 'Statut « À empaqueter » — enregistré : %1$s · déclaré à WooCommerce : %2$s · commandes concernées : %3$s', 'real-stock-manager-for-woocommerce' ),
			$this->yes_no( OrderStatus::is_registered() ),
			$this->yes_no( OrderStatus::is_declared() ),
			'<strong>' . esc_html( number_format_i18n( OrderStatus::order_count() ) ) . '</strong>'
		);

		$lines[] = sprintf(
			/* translators: 1: nombre de références, 2: nombre de lignes, 3: nombre d'entrées. */
			esc_html__( 'Données reprises — références avec stock physique : %1$s · lignes de commande pointées : %2$s · mouvements au journal : %3$s', 'real-stock-manager-for-woocommerce' ),
			'<strong>' . esc_html( number_format_i18n( Stock::tracked_reference_count() ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( Items::prepared_line_count() ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( Journal::count() ) ) . '</strong>'
		);

		$lines[] = sprintf(
			/* translators: 1: nombre de références, 2: nombre de lignes. */
			esc_html__( 'Commandes fournisseur — références avec une commande en cours : %1$s · lignes de commande couvertes : %2$s', 'real-stock-manager-for-woocommerce' ),
			'<strong>' . esc_html( number_format_i18n( Supply::tracked_reference_count() ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( Items::ordered_line_count() ) ) . '</strong>'
		);

		$lines[] = sprintf(
			/* translators: 1: nombre de références, 2: nombre total d'articles. */
			esc_html__( 'Défectueux constatés à la réception — références concernées : %1$s · articles au total : %2$s', 'real-stock-manager-for-woocommerce' ),
			'<strong>' . esc_html( number_format_i18n( Defects::tracked_reference_count() ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( Defects::total() ) ) . '</strong>'
		);

		if ( PreOrderSnippetGuard::snippet_is_active() ) {
			$lines[] = '<strong style="color:#b32d2e">'
				. esc_html__( 'Snippets de précommande détectés : le module Précommandes est en veille.', 'real-stock-manager-for-woocommerce' )
				. '</strong>';
		}

		$lines[] = sprintf(
			/* translators: 1: oui/non, 2: nombre de commandes, 3: nombre de commandes marquées, 4: nombre de lignes. */
			esc_html__( 'Précommandes — statut enregistré : %1$s · commandes dans le statut : %2$s · commandes tracées : %3$s · lignes précommandées : %4$s', 'real-stock-manager-for-woocommerce' ),
			$this->yes_no( PreOrderStatus::is_registered() ),
			'<strong>' . esc_html( number_format_i18n( PreOrderStatus::order_count() ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( PreOrderMigration::marked_order_count() ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( PreOrderMigration::marked_line_count() ) ) . '</strong>'
		);

		if ( ! PreOrderMigration::is_done() ) {
			$lines[] = esc_html__( 'Reprise de l’historique des précommandes en cours : elle avance à chaque chargement de l’administration.', 'real-stock-manager-for-woocommerce' );
		}

		$statuses = Config::statuses();

		$lines[] = sprintf(
			/* translators: %s: liste des statuts suivis. */
			esc_html__( 'Statuts suivis : %s', 'real-stock-manager-for-woocommerce' ),
			'<code>' . esc_html( implode( ', ', $statuses ) ) . '</code>'
		);

		$known = array_map(
			static function ( $status ) {
				return preg_replace( '/^wc-/', '', $status );
			},
			array_keys( wc_get_order_statuses() )
		);

		$unknown = array_diff( $statuses, $known );

		if ( ! empty( $unknown ) ) {
			$lines[] = '<strong style="color:#b32d2e">' . sprintf(
				/* translators: %s: liste des slugs inconnus. */
				esc_html__( 'Statuts inconnus sur cette boutique : %s — les commandes concernées ne seront jamais comptées.', 'real-stock-manager-for-woocommerce' ),
				'<code>' . esc_html( implode( ', ', $unknown ) ) . '</code>'
			) . '</strong>';
		}

		return implode( '<br>', $lines );
	}

	/**
	 * Rend un booléen sous forme colorée.
	 *
	 * @param bool $value Valeur.
	 *
	 * @return string
	 */
	private function yes_no( bool $value ): string {
		return $value
			? '<span style="color:#00a32a">' . esc_html__( 'oui', 'real-stock-manager-for-woocommerce' ) . '</span>'
			: '<span style="color:#b32d2e">' . esc_html__( 'non', 'real-stock-manager-for-woocommerce' ) . '</span>';
	}

	/**
	 * Champs de la section « Modules » : une case à cocher par module déclaré.
	 *
	 * @return array
	 */
	protected function get_settings_for_modules_section(): array {
		$settings = array(
			array(
				'title' => __( 'Modules', 'real-stock-manager-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Activez individuellement les règles de gestion de stock.', 'real-stock-manager-for-woocommerce' ),
				'id'    => Settings::PREFIX . 'module_options',
			),
		);

		foreach ( $this->get_declared_modules() as $module ) {
			$settings[] = array(
				'title'   => $module->get_title(),
				'desc'    => __( 'Activer', 'real-stock-manager-for-woocommerce' ),
				'id'      => Settings::PREFIX . 'module_' . $module->get_id() . '_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			);
		}

		if ( 1 === count( $settings ) ) {
			$settings[] = array(
				'title' => '',
				'type'  => 'info',
				'text'  => __( 'Aucun module déclaré pour le moment. Ajoutez vos classes dans src/Modules/ puis référencez-les dans Plugin::get_module_classes().', 'real-stock-manager-for-woocommerce' ),
				'id'    => Settings::PREFIX . 'module_empty_notice',
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => Settings::PREFIX . 'module_options',
		);

		return $settings;
	}

	/**
	 * Instancie tous les modules déclarés, actifs ou non, pour l'affichage.
	 *
	 * @return ModuleInterface[]
	 */
	private function get_declared_modules(): array {
		$modules = array();

		foreach ( Plugin::get_module_classes() as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			$module = new $class_name();

			if ( $module instanceof ModuleInterface ) {
				$modules[] = $module;
			}
		}

		return $modules;
	}
}
