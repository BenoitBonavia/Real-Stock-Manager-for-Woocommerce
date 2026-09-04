<?php
/**
 * Page « Gestion du stock » : réception d'un colis et mouvements à l'unité.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Allocator;
use RSMW\Preparation\Journal;
use RSMW\Preparation\Labels;
use RSMW\Preparation\Legacy;
use RSMW\Preparation\Reception;
use RSMW\Preparation\Stock;
use RSMW\Preparation\Supply;
use RSMW\Suppliers\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Deux onglets : la réception d'un colis entier, et la console de mouvement à
 * l'unité.
 *
 * Les formulaires sont traités sur `load-{écran}`, avant que WordPress n'ait
 * envoyé l'en-tête de l'administration : c'est la seule fenêtre où une
 * redirection reste possible. Sans elle, un simple rafraîchissement de page
 * rejouerait le mouvement — sur un formulaire de réception, c'est un colis
 * entier qui serait enregistré deux fois.
 */
final class StockPage {

	/** Onglet de réception, affiché par défaut. */
	public const TAB_RECEPTION = 'reception';

	/** Onglet de mouvement à l'unité. */
	public const TAB_MOVEMENT = 'mouvement';

	/** Valeur du filtre fournisseur désignant les références sans fournisseur. */
	public const SUPPLIER_NONE = 'sans-fournisseur';

	/** Nonce du formulaire de mouvement à l'unité. */
	private const NONCE_MOVEMENT = 'rsmw_stock_movement';

	/** Nonce du formulaire de réception. */
	private const NONCE_RECEPTION = 'rsmw_reception';

	/**
	 * Sens autorisés pour un mouvement à l'unité.
	 *
	 * `in` / `out` déplacent du stock physique ; `order` / `unorder` déplacent des
	 * quantités commandées au fournisseur, sans marchandise et sans effet sur le
	 * statut des commandes.
	 */
	private const DIRECTIONS = array( 'in', 'order', 'unorder', 'out' );

	/**
	 * Résultat d'une simulation de réception, conservé le temps de la requête.
	 *
	 * @var array|null
	 */
	private static $simulation = null;

	/**
	 * Saisie à réafficher après une simulation.
	 *
	 * @var array
	 */
	private static $submitted = array();

	/**
	 * Onglet courant.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte d'affichage.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_RECEPTION;

		return in_array( $tab, array( self::TAB_RECEPTION, self::TAB_MOVEMENT ), true ) ? $tab : self::TAB_RECEPTION;
	}

	/**
	 * Adresse de l'onglet demandé.
	 *
	 * @param string $tab Onglet.
	 *
	 * @return string
	 */
	public static function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . Legacy::PAGE_STOCK . '&tab=' . $tab );
	}

	/**
	 * Adresse de l'onglet de réception, filtré sur un fournisseur.
	 *
	 * @param string $supplier Slug du fournisseur, chaîne vide pour tous.
	 *
	 * @return string
	 */
	public static function reception_url( string $supplier = '' ): string {
		$url = self::tab_url( self::TAB_RECEPTION );

		return '' === $supplier ? $url : $url . '&supplier=' . rawurlencode( $supplier );
	}

	/**
	 * Fournisseur sur lequel la réception est filtrée.
	 *
	 * Liste blanche construite sur TOUS les fournisseurs déclarés, et non sur ceux
	 * qui ont quelque chose en attente aujourd'hui : un signet posé sur l'un d'eux
	 * doit rester valable la semaine où son colis est déjà arrivé, sinon le filtre
	 * retomberait en silence sur la liste complète.
	 *
	 * @return string Slug, ou chaîne vide pour « tous ».
	 */
	public static function current_supplier(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte d'affichage.
		if ( ! isset( $_GET['supplier'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		$slug = sanitize_title( wp_unslash( $_GET['supplier'] ) );

		if ( self::SUPPLIER_NONE === $slug ) {
			return $slug;
		}

		foreach ( Resolver::all() as $term ) {
			if ( $term->slug === $slug ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Traite les formulaires avant tout affichage.
	 *
	 * Accroché sur `load-{écran}` : c'est le seul moment où une redirection est
	 * encore possible, l'en-tête de l'administration n'ayant pas été envoyé.
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['rsmw_reception_check'] ) ) {
			check_admin_referer( self::NONCE_RECEPTION );

			self::$submitted  = self::read_reception_input();
			self::$simulation = Reception::simulate( self::$submitted );

			return;
		}

		if ( isset( $_POST['rsmw_reception_submit'] ) ) {
			check_admin_referer( self::NONCE_RECEPTION );

			$report = Reception::apply( self::read_reception_input() );

			self::flash( array( 'reception' => $report ) );
			self::redirect( self::TAB_RECEPTION );
		}

		if ( isset( $_POST['rsmw_stock_submit'] ) ) {
			check_admin_referer( self::NONCE_MOVEMENT );

			$errors   = array();
			$movement = self::handle_movement( $errors );

			self::flash(
				array(
					'movement' => $movement,
					'errors'   => $errors,
				)
			);
			self::redirect( self::TAB_MOVEMENT );
		}
	}

	/**
	 * Affiche la page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		$flash = self::take_flash();

		if ( self::TAB_MOVEMENT === self::current_tab() ) {
			self::render_movement( $flash );

			return;
		}

		self::render_reception( $flash );
	}

	/**
	 * Onglet « Réception d'un colis ».
	 *
	 * @param array $flash Compte rendu d'une réception venant d'être enregistrée.
	 */
	private static function render_reception( array $flash ): void {
		$all      = Reception::pending();
		$supplier = self::current_supplier();

		View::render(
			'reception-page',
			array(
				'tab'            => self::TAB_RECEPTION,
				'tabs'           => self::tabs(),
				'pending'        => self::filter_by_supplier( $all, $supplier ),
				'supplier'       => $supplier,
				'supplier_name'  => self::supplier_name( $supplier ),
				'supplier_list'  => self::supplier_choices( $all ),
				'submitted'      => self::$submitted,
				'simulation'     => self::$simulation,
				'report'         => isset( $flash['reception'] ) ? $flash['reception'] : null,
				'nonce_field'    => wp_nonce_field( self::NONCE_RECEPTION, '_wpnonce', true, false ),
				'journal'        => Journal::all(),
				'needs_page_url' => admin_url( 'admin.php?page=' . Legacy::PAGE_NEEDS ),
			)
		);
	}

	/**
	 * Ne conserve que les références du fournisseur demandé.
	 *
	 * @param array  $rows     Toutes les références attendues.
	 * @param string $supplier Slug, chaîne vide pour tous.
	 *
	 * @return array
	 */
	private static function filter_by_supplier( array $rows, string $supplier ): array {
		if ( '' === $supplier ) {
			return $rows;
		}

		$wanted = self::SUPPLIER_NONE === $supplier ? '' : $supplier;

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $wanted ) {
					return isset( $row['supplierslug'] ) && $row['supplierslug'] === $wanted;
				}
			)
		);
	}

	/**
	 * Choix du sélecteur de fournisseur, avec le nombre de références attendues.
	 *
	 * Le compteur est ce qui rend le sélecteur utile : le marchand voit d'un coup
	 * d'œil chez qui il attend quelque chose, sans ouvrir chaque filtre. Seuls les
	 * fournisseurs ayant au moins une référence en attente sont proposés — la
	 * liste répond à « de qui ce colis peut-il venir ? », pas « qui connais-je ? ».
	 *
	 * @param array $rows Toutes les références attendues.
	 *
	 * @return array<int, array{slug:string, label:string, count:int}>
	 */
	private static function supplier_choices( array $rows ): array {
		$counts = array();
		$names  = array();

		foreach ( $rows as $row ) {
			$slug = isset( $row['supplierslug'] ) && '' !== $row['supplierslug']
				? $row['supplierslug']
				: self::SUPPLIER_NONE;

			$counts[ $slug ] = isset( $counts[ $slug ] ) ? $counts[ $slug ] + 1 : 1;

			$names[ $slug ] = '' !== (string) $row['fournisseur']
				? $row['fournisseur']
				: __( 'Sans fournisseur', 'real-stock-manager-for-woocommerce' );
		}

		$choices = array();
		$taken   = false;

		foreach ( Resolver::all() as $term ) {
			if ( self::SUPPLIER_NONE === $term->slug ) {
				$taken = true;
			}

			if ( isset( $counts[ $term->slug ] ) ) {
				$choices[] = array(
					'slug'  => $term->slug,
					'label' => $term->name,
					'count' => $counts[ $term->slug ],
				);
			}
		}

		/*
		 * « Sans fournisseur » en dernier : c'est un reste, pas un fournisseur.
		 *
		 * Sauté si un vrai terme occupe déjà ce slug — cas qui ne peut plus se
		 * produire depuis que Suppliers\Taxonomy réserve la valeur, mais qui reste
		 * possible pour un fournisseur créé avant cette garde. Sans ce test, le
		 * sélecteur afficherait deux fois la même option.
		 */
		if ( ! $taken && isset( $counts[ self::SUPPLIER_NONE ] ) ) {
			$choices[] = array(
				'slug'  => self::SUPPLIER_NONE,
				'label' => $names[ self::SUPPLIER_NONE ],
				'count' => $counts[ self::SUPPLIER_NONE ],
			);
		}

		return $choices;
	}

	/**
	 * Nom lisible du fournisseur filtré.
	 *
	 * @param string $supplier Slug.
	 *
	 * @return string
	 */
	private static function supplier_name( string $supplier ): string {
		if ( '' === $supplier ) {
			return '';
		}

		if ( self::SUPPLIER_NONE === $supplier ) {
			return __( 'Sans fournisseur', 'real-stock-manager-for-woocommerce' );
		}

		foreach ( Resolver::all() as $term ) {
			if ( $term->slug === $supplier ) {
				return $term->name;
			}
		}

		return '';
	}

	/**
	 * Onglet « Mouvement à l'unité ».
	 *
	 * @param array $flash Compte rendu d'un mouvement venant d'être enregistré.
	 */
	private static function render_movement( array $flash ): void {
		View::render(
			'stock-page',
			array(
				'tab'            => self::TAB_MOVEMENT,
				'tabs'           => self::tabs(),
				'movement'       => isset( $flash['movement'] ) ? $flash['movement'] : null,
				'errors'         => isset( $flash['errors'] ) ? (array) $flash['errors'] : array(),
				'journal'        => Journal::all(),
				'reasons'        => self::reasons(),
				'nonce_field'    => wp_nonce_field( self::NONCE_MOVEMENT, '_wpnonce', true, false ),
				'search_nonce'   => wp_create_nonce( 'search-products' ),
				'needs_page_url' => admin_url( 'admin.php?page=' . Legacy::PAGE_NEEDS ),
			)
		);
	}

	/**
	 * Libellés des onglets.
	 *
	 * @return array<string, string>
	 */
	private static function tabs(): array {
		return array(
			self::TAB_RECEPTION => __( 'Réception d’un colis', 'real-stock-manager-for-woocommerce' ),
			self::TAB_MOVEMENT  => __( 'Mouvement à l’unité', 'real-stock-manager-for-woocommerce' ),
		);
	}

	/**
	 * Motifs de retrait proposés.
	 *
	 * @return array<string, string>
	 */
	private static function reasons(): array {
		return array(
			'défaut'                  => __( 'Défaut', 'real-stock-manager-for-woocommerce' ),
			'casse'                   => __( 'Casse', 'real-stock-manager-for-woocommerce' ),
			'perte'                   => __( 'Perte', 'real-stock-manager-for-woocommerce' ),
			'retour fournisseur'      => __( 'Retour fournisseur', 'real-stock-manager-for-woocommerce' ),
			'correction d’inventaire' => __( 'Correction d’inventaire', 'real-stock-manager-for-woocommerce' ),
		);
	}

	/**
	 * Lit la saisie du tableau de réception.
	 *
	 * Le nonce est vérifié par l'appelant.
	 *
	 * @return array<int, array{ok:int, defective:int}>
	 */
	private static function read_reception_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié par l'appelant.
		$raw = isset( $_POST['rsmw_reception'] ) ? wp_unslash( $_POST['rsmw_reception'] ) : array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();

		foreach ( $raw as $product_id => $quantities ) {
			if ( ! is_array( $quantities ) ) {
				continue;
			}

			$rows[ (int) $product_id ] = array(
				'ok'        => isset( $quantities['ok'] ) ? absint( $quantities['ok'] ) : 0,
				'defective' => isset( $quantities['defective'] ) ? absint( $quantities['defective'] ) : 0,
			);
		}

		return $rows;
	}

	/**
	 * Traite le formulaire de mouvement à l'unité.
	 *
	 * @param array $errors Messages d'erreur, complétés par référence.
	 *
	 * @return array|null Sens et compte rendu, ou null si aucune demande.
	 */
	private static function handle_movement( array &$errors ): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- vérifié par l'appelant.
		$direction = isset( $_POST['rsmw_movement_direction'] )
			? sanitize_key( wp_unslash( $_POST['rsmw_movement_direction'] ) )
			: '';

		if ( ! in_array( $direction, self::DIRECTIONS, true ) ) {
			$errors[] = __( 'Sens du mouvement invalide. Rien n’a été enregistré.', 'real-stock-manager-for-woocommerce' );

			return null;
		}

		$product_id = self::resolve_product( 'rsmw_movement_product', 'rsmw_movement_sku' );
		$quantity   = isset( $_POST['rsmw_movement_qty'] ) ? absint( wp_unslash( $_POST['rsmw_movement_qty'] ) ) : 0;

		if ( $product_id <= 0 ) {
			$errors[] = __( 'Référence introuvable. Choisissez-la dans la liste ou saisissez un SKU exact.', 'real-stock-manager-for-woocommerce' );
		}

		if ( $quantity <= 0 ) {
			$errors[] = __( 'La quantité doit être supérieure à zéro.', 'real-stock-manager-for-woocommerce' );
		}

		if ( ! empty( $errors ) ) {
			return null;
		}

		$reason = 'out' === $direction && isset( $_POST['rsmw_movement_reason'] )
			? sanitize_text_field( wp_unslash( $_POST['rsmw_movement_reason'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		switch ( $direction ) {
			case 'in':
				$report   = Allocator::receive( $product_id, $quantity );
				$moved    = $quantity;
				$affected = (int) $report['affecte'];
				break;

			case 'order':
				$report   = Allocator::order_from_supplier( $product_id, $quantity );
				$moved    = $quantity;
				$affected = (int) $report['affecte'];
				break;

			case 'unorder':
				$report   = Allocator::cancel_supplier_order( $product_id, $quantity );
				$moved    = (int) $report['du_libre'] + (int) $report['repris'];
				$affected = (int) $report['repris'];
				break;

			default:
				$report   = Allocator::withdraw( $product_id, $quantity, $reason );
				$moved    = (int) $report['du_libre'] + (int) $report['repris'];
				$affected = (int) $report['repris'];
				break;
		}

		Journal::add(
			array(
				'time'     => time(),
				'user'     => wp_get_current_user()->display_name,
				'type'     => $direction,
				'product'  => $product_id,
				'label'    => self::reference_label( $product_id ),
				'qty'      => $moved,
				'orders'   => $affected,
				// Les deux compteurs sont relevés après coup, quel que soit le
				// sens : le journal doit rester lisible d'une ligne à l'autre.
				'libre'    => Stock::get( $product_id ),
				'commande' => Supply::get( $product_id ),
				'motif'    => $reason,
			)
		);

		return array(
			'direction' => $direction,
			'report'    => $report,
			'context'   => ReferenceContext::describe( $product_id ),
		);
	}

	/**
	 * Mémorise un compte rendu le temps d'une redirection.
	 *
	 * @param array $payload Données à réafficher.
	 */
	private static function flash( array $payload ): void {
		set_transient( 'rsmw_stock_flash_' . get_current_user_id(), $payload, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Récupère et consomme le compte rendu mémorisé.
	 *
	 * @return array
	 */
	private static function take_flash(): array {
		$key   = 'rsmw_stock_flash_' . get_current_user_id();
		$flash = get_transient( $key );

		if ( ! is_array( $flash ) ) {
			return array();
		}

		delete_transient( $key );

		return $flash;
	}

	/**
	 * Redirige vers l'onglet, pour qu'un rafraîchissement ne rejoue pas l'écriture.
	 *
	 * @param string $tab Onglet.
	 */
	private static function redirect( string $tab ): void {
		/*
		 * La réception conserve son filtre fournisseur. Sans cela, le marchand qui
		 * vient de pointer un colis d'un fournisseur retomberait sur la liste
		 * complète — et croirait, en voyant réapparaître les références des autres,
		 * que son enregistrement n'a pas pris.
		 */
		$url = self::TAB_RECEPTION === $tab
			? self::reception_url( self::current_supplier() )
			: self::tab_url( $tab );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Libellé complet d'une référence, pour le journal.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return string
	 */
	private static function reference_label( int $product_id ): string {
		$info = Labels::get( $product_id );

		return trim( $info['name'] . ( '' !== $info['variant'] ? ' — ' . $info['variant'] : '' ) );
	}

	/**
	 * Résout un produit depuis le champ de recherche, avec repli SKU puis identifiant.
	 *
	 * Le nonce du formulaire est vérifié par l'appelant.
	 *
	 * @param string $select_field Nom du champ de recherche.
	 * @param string $sku_field    Nom du champ SKU.
	 *
	 * @return int Identifiant du produit, ou 0 si introuvable.
	 */
	private static function resolve_product( string $select_field, string $sku_field ): int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- vérifié par l'appelant via check_admin_referer().
		$product_id = isset( $_POST[ $select_field ] ) ? absint( wp_unslash( $_POST[ $select_field ] ) ) : 0;

		if ( $product_id <= 0 && ! empty( $_POST[ $sku_field ] ) ) {
			$raw        = sanitize_text_field( wp_unslash( $_POST[ $sku_field ] ) );
			$product_id = (int) wc_get_product_id_by_sku( $raw );

			if ( $product_id <= 0 && ctype_digit( $raw ) ) {
				$product_id = (int) $raw;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return ( $product_id > 0 && wc_get_product( $product_id ) ) ? $product_id : 0;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
