<?php
/**
 * Page « Besoins & stock ».
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Allocator;
use RSMW\Preparation\Config;
use RSMW\Preparation\Demand;
use RSMW\Preparation\Labels;
use RSMW\Preparation\Legacy;
use RSMW\Preparation\OrderStatus;
use RSMW\Preparation\Purchase;
use RSMW\Preparation\Stock;
use RSMW\Preparation\Supply;
use RSMW\Suppliers\Resolver;
use RSMW\Suppliers\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Confronte ce qu'il reste à préparer sur les commandes en attente au stock
 * physique libre, référence par référence.
 *
 * La page est découpée en onglets : « Général » avec tout, puis un par
 * fournisseur, parce que le geste réel du marchand est fournisseur par
 * fournisseur — il ouvre un onglet et doit en sortir une commande à envoyer.
 */
final class NeedsPage {

	/** Onglet « Général ». */
	public const TAB_ALL = 'general';

	/** Onglet des références sans fournisseur. */
	public const TAB_NONE = 'sans-fournisseur';

	/** Nonce du formulaire de commande fournisseur. */
	private const NONCE_PURCHASE = 'rsmw_purchase';

	/**
	 * Quantités saisies, conservées le temps d'afficher la vérification.
	 *
	 * @var array<int, int>
	 */
	private static $submitted = array();

	/**
	 * Vérification avant écriture.
	 *
	 * @var array|null
	 */
	private static $simulation = null;

	/**
	 * Compte rendu de réaffectation.
	 *
	 * @var array|null
	 */
	private static $reallocation = null;

	/**
	 * Traite les formulaires, avant tout affichage.
	 *
	 * Accroché sur `load-{écran}` et non depuis render() : c'est la seule fenêtre
	 * où une redirection reste possible. Sans elle, un rafraîchissement rejouerait
	 * l'écriture — anodin pour une réaffectation, mais une commande fournisseur de
	 * douze articles en enregistrerait vingt-quatre.
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['rsmw_purchase_check'] ) ) {
			check_admin_referer( self::NONCE_PURCHASE );

			self::$submitted  = self::read_purchase_input();
			self::$simulation = Purchase::simulate( self::$submitted );

			return;
		}

		if ( isset( $_POST['rsmw_purchase_submit'] ) ) {
			check_admin_referer( self::NONCE_PURCHASE );

			$report = Purchase::apply( self::read_purchase_input() );

			self::flash( array( 'purchase' => $report ) );
			self::redirect( self::current_tab() );
		}

		if ( isset( $_POST['mh_prep_realloc'] ) ) {
			check_admin_referer( 'mh_prep_realloc' );

			$mode   = sanitize_text_field( wp_unslash( $_POST['mh_prep_realloc'] ) );
			$report = Allocator::reallocate_all( 'simuler' === $mode );

			// Une simulation n'écrit rien : la rejouer est sans conséquence, on la
			// garde en mémoire plutôt que de rediriger.
			if ( $report['dry'] ) {
				self::$reallocation = $report;

				return;
			}

			Demand::flush();
			self::flash( array( 'reallocation' => $report ) );
			self::redirect( self::current_tab() );
		}

		if ( isset( $_POST['mh_prep_repair'] ) ) {
			check_admin_referer( 'mh_prep_repair' );

			$negatives = Stock::negative_ids();

			foreach ( $negatives as $product_id ) {
				Stock::set( $product_id, 0 );
			}

			Demand::flush();
			self::flash( array( 'repaired' => count( $negatives ) ) );
			self::redirect( self::current_tab() );
		}
	}

	/**
	 * Affiche la page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple invalidation de cache, sans effet de bord.
		if ( isset( $_GET['mh_flush'] ) ) {
			Demand::flush();
		}

		$flash = self::take_flash();

		// Recalcul systématique : la page affichée doit refléter l'état réel des
		// commandes, sans dépendre du déclenchement des hooks d'invalidation.
		$map = Demand::map( false );

		Labels::prime( array_keys( $map ) );

		$suppliers = Resolver::map_for( array_keys( $map ), self::parents( $map ) );
		$rows      = self::build_rows( $map, $suppliers );
		$counts    = self::count_by_supplier( $rows );
		$tab       = self::current_tab();
		$visible   = self::filter_rows( $rows, $tab );

		View::render(
			'needs-page',
			array(
				'rows'             => $visible,
				'totals'           => self::totals( $visible ),
				'tab'              => $tab,
				'tabs'             => self::tabs( $counts ),
				'supplier'         => self::current_supplier( $tab ),
				'suppliers_url'    => Taxonomy::manage_url(),
				'export_filename'  => self::export_filename( $tab ),
				'purchase_nonce'   => self::NONCE_PURCHASE,
				'submitted'        => self::$submitted,
				'simulation'       => self::$simulation,
				'purchase'         => isset( $flash['purchase'] ) ? $flash['purchase'] : null,
				'statuses'         => Config::statuses(),
				'unknown_statuses' => self::unknown_statuses(),
				'negatives'        => Stock::negative_ids(),
				'repaired'         => isset( $flash['repaired'] ) ? (int) $flash['repaired'] : null,
				'reallocation'     => isset( $flash['reallocation'] ) ? $flash['reallocation'] : self::$reallocation,
				'allocatable'      => Demand::allocatable_count( false ),
				'cache_meta'       => Demand::meta(),
				'outside'          => Demand::orders_outside(),
				'auto_allocate'    => Config::auto_allocate(),
				'status_label'     => OrderStatus::label(),
				'status_ok'        => OrderStatus::is_registered() && OrderStatus::is_declared(),
				'status_declared'  => OrderStatus::is_declared(),
				'status_count'     => OrderStatus::order_count(),
				'stock_page_url'   => admin_url( 'admin.php?page=' . Legacy::PAGE_STOCK ),
				'refresh_url'      => add_query_arg( 'mh_flush', time() ),
			)
		);
	}

	/**
	 * Produit parent de chaque référence, tel que la table des besoins le connaît.
	 *
	 * @param array $map Table des besoins.
	 *
	 * @return array<int, int>
	 */
	private static function parents( array $map ): array {
		$parents = array();

		foreach ( $map as $product_id => $data ) {
			// Lecture défensive : un transient écrit par une version antérieure ne
			// porte pas encore cette clé. Le résolveur retombera sur get_post().
			if ( isset( $data['parent'] ) ) {
				$parents[ (int) $product_id ] = (int) $data['parent'];
			}
		}

		return $parents;
	}

	/**
	 * Construit les lignes du tableau.
	 *
	 * @param array                 $map       Table des besoins.
	 * @param array<int, \WP_Term>  $suppliers Fournisseur par référence.
	 *
	 * @return array
	 */
	private static function build_rows( array $map, array $suppliers ): array {
		$rows = array();

		foreach ( $map as $product_id => $data ) {

			$info      = Labels::get( $product_id );
			$free      = Stock::get( $product_id );
			$remaining = (int) $data['restant'];

			/*
			 * Commandé au fournisseur : la part déjà réservée sur des commandes
			 * clients, plus le reliquat non attribué. Lecture défensive de la carte,
			 * un transient d'une version antérieure ne porte pas encore cette clé.
			 */
			$ordered = ( isset( $data['commande'] ) ? (int) $data['commande'] : 0 )
				+ Supply::get( $product_id );

			// Ce qu'il reste RÉELLEMENT à commander : ni en stock, ni déjà commandé.
			$missing = max( 0, $remaining - max( 0, $free ) - $ordered );

			$supplier = isset( $suppliers[ $product_id ] ) ? $suppliers[ $product_id ] : null;

			$rows[] = array(
				'id'           => (int) $product_id,
				'name'         => $info['name'],
				'variant'      => $info['variant'],
				'sku'          => $info['sku'],
				'edit'         => $info['edit'],
				'demande'      => (int) $data['demande'],
				'pointe'       => (int) $data['pointe'],
				'restant'      => $remaining,
				'libre'        => $free,
				'commande'     => $ordered,
				'manque'       => $missing,
				'commandes'    => (int) $data['commandes'],
				'oldest'       => self::oldest_order( $data['plus_vieux'] ),
				'valeur'       => $missing * $info['price'],
				'fournisseur'  => $supplier ? $supplier->name : '',
				'supplierslug' => $supplier ? $supplier->slug : '',
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['manque'] !== $b['manque'] ) {
					return $b['manque'] <=> $a['manque'];
				}

				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Totaux calculés sur les lignes retenues.
	 *
	 * @param array $rows Lignes de l'onglet courant.
	 *
	 * @return array
	 */
	private static function totals( array $rows ): array {
		$totals = array(
			'restant'     => 0,
			'commande'    => 0,
			'manque'      => 0,
			'valeur'      => 0.0,
			'refs_manque' => 0,
		);

		foreach ( $rows as $row ) {
			$totals['restant']  += $row['restant'];
			$totals['commande'] += $row['commande'];
			$totals['manque']   += $row['manque'];
			$totals['valeur']   += $row['valeur'];

			if ( $row['manque'] > 0 ) {
				++$totals['refs_manque'];
			}
		}

		return $totals;
	}

	/**
	 * Nombre de références à commander, par fournisseur.
	 *
	 * On compte les références dont il MANQUE quelque chose, et non toutes celles
	 * du fournisseur : la case « Manquants uniquement » étant cochée d'entrée,
	 * c'est exactement le nombre de lignes que le marchand verra en ouvrant
	 * l'onglet. Un compteur annonçant quarante devant un tableau de trois lignes
	 * ferait perdre confiance dans les deux.
	 *
	 * @param array $rows Toutes les lignes.
	 *
	 * @return array<string, int>
	 */
	private static function count_by_supplier( array $rows ): array {
		$counts = array(
			self::TAB_ALL  => 0,
			self::TAB_NONE => 0,
		);

		foreach ( Resolver::all() as $term ) {
			$counts[ $term->slug ] = 0;
		}

		foreach ( $rows as $row ) {
			if ( $row['manque'] <= 0 ) {
				continue;
			}

			++$counts[ self::TAB_ALL ];

			$slug = '' !== $row['supplierslug'] ? $row['supplierslug'] : self::TAB_NONE;

			if ( ! isset( $counts[ $slug ] ) ) {
				$counts[ $slug ] = 0;
			}

			++$counts[ $slug ];
		}

		return $counts;
	}

	/**
	 * Onglets à afficher, avec leur libellé et leur compteur.
	 *
	 * @param array<string, int> $counts Compteurs par onglet.
	 *
	 * @return array<string, array{label:string, count:int, alert:bool}>
	 */
	private static function tabs( array $counts ): array {
		$current = self::current_tab();

		$tabs = array(
			self::TAB_ALL => array(
				'label' => __( 'Général', 'real-stock-manager-for-woocommerce' ),
				'count' => isset( $counts[ self::TAB_ALL ] ) ? $counts[ self::TAB_ALL ] : 0,
				'alert' => false,
			),
		);

		foreach ( Resolver::all() as $term ) {
			$tabs[ $term->slug ] = array(
				'label' => $term->name,
				'count' => isset( $counts[ $term->slug ] ) ? $counts[ $term->slug ] : 0,
				'alert' => false,
			);
		}

		/*
		 * « Sans fournisseur » en dernier, et masqué quand il est vide. Sa présence
		 * est indispensable : sans lui, une référence non affectée n'apparaîtrait
		 * dans AUCUN onglet fournisseur, et le marchand qui travaille fournisseur
		 * par fournisseur ne la commanderait jamais. Sa disparition est en revanche
		 * la récompense : elle signale que le catalogue est entièrement cartographié.
		 */
		$orphans = isset( $counts[ self::TAB_NONE ] ) ? $counts[ self::TAB_NONE ] : 0;

		if ( $orphans > 0 || self::TAB_NONE === $current ) {
			$tabs[ self::TAB_NONE ] = array(
				'label' => __( 'Sans fournisseur', 'real-stock-manager-for-woocommerce' ),
				'count' => $orphans,
				'alert' => true,
			);
		}

		return $tabs;
	}

	/**
	 * Onglet demandé.
	 *
	 * La liste blanche couvre TOUS les fournisseurs déclarés, y compris ceux qui
	 * n'ont rien à commander cette semaine : un signet posé sur l'un d'eux doit
	 * ouvrir son onglet, et non retomber en silence sur « Général », ce qui
	 * donnerait à croire que le fournisseur a disparu.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte d'affichage.
		if ( ! isset( $_GET['tab'] ) ) {
			return self::TAB_ALL;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		$tab = sanitize_title( wp_unslash( $_GET['tab'] ) );

		if ( self::TAB_ALL === $tab || self::TAB_NONE === $tab ) {
			return $tab;
		}

		foreach ( Resolver::all() as $term ) {
			if ( $term->slug === $tab ) {
				return $tab;
			}
		}

		return self::TAB_ALL;
	}

	/**
	 * Adresse d'un onglet.
	 *
	 * @param string $tab Onglet.
	 *
	 * @return string
	 */
	public static function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . Legacy::PAGE_NEEDS . '&tab=' . rawurlencode( $tab ) );
	}

	/**
	 * Fournisseur de l'onglet courant, s'il en désigne un.
	 *
	 * @param string $tab Onglet.
	 *
	 * @return \WP_Term|null
	 */
	private static function current_supplier( string $tab ): ?\WP_Term {
		if ( self::TAB_ALL === $tab || self::TAB_NONE === $tab ) {
			return null;
		}

		foreach ( Resolver::all() as $term ) {
			if ( $term->slug === $tab ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Ne conserve que les lignes de l'onglet courant.
	 *
	 * @param array  $rows Toutes les lignes.
	 * @param string $tab  Onglet.
	 *
	 * @return array
	 */
	private static function filter_rows( array $rows, string $tab ): array {
		if ( self::TAB_ALL === $tab ) {
			return $rows;
		}

		$wanted = self::TAB_NONE === $tab ? '' : $tab;

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $wanted ) {
					return $row['supplierslug'] === $wanted;
				}
			)
		);
	}

	/**
	 * Nom du fichier d'export, distinct par onglet.
	 *
	 * Sans cela, six exports successifs produiraient six fichiers de même nom dans
	 * le dossier de téléchargement.
	 *
	 * @param string $tab Onglet.
	 *
	 * @return string
	 */
	private static function export_filename( string $tab ): string {
		$base = __( 'besoins', 'real-stock-manager-for-woocommerce' );

		if ( self::TAB_ALL === $tab ) {
			return $base . '-general';
		}

		return $base . '-' . $tab;
	}

	/**
	 * Lit les quantités saisies dans le formulaire de commande.
	 *
	 * @return array<int, int>
	 */
	private static function read_purchase_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié par l'appelant.
		$raw = isset( $_POST['rsmw_purchase'] ) ? wp_unslash( $_POST['rsmw_purchase'] ) : array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();

		foreach ( $raw as $product_id => $qty ) {
			$rows[ (int) $product_id ] = absint( $qty );
		}

		return $rows;
	}

	/**
	 * Mémorise un compte rendu le temps d'une redirection.
	 *
	 * @param array $payload Données à réafficher.
	 */
	private static function flash( array $payload ): void {
		set_transient( 'rsmw_needs_flash_' . get_current_user_id(), $payload, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Récupère et consomme le compte rendu mémorisé.
	 *
	 * @return array
	 */
	private static function take_flash(): array {
		$key   = 'rsmw_needs_flash_' . get_current_user_id();
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
		wp_safe_redirect( self::tab_url( $tab ) );
		exit;
	}

	/**
	 * Résumé de la commande la plus ancienne attendant une référence.
	 *
	 * @param int|null $order_id Identifiant de commande.
	 *
	 * @return array|null
	 */
	private static function oldest_order( $order_id ): ?array {
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$created = $order->get_date_created();

		return array(
			'num'  => $order->get_order_number(),
			'date' => $created ? $created->date_i18n( 'd/m/Y' ) : '',
			'url'  => $order->get_edit_order_url(),
		);
	}

	/**
	 * Statuts configurés qui n'existent pas sur cette boutique.
	 *
	 * Un slug inconnu ne lève aucune erreur : la requête renvoie simplement zéro
	 * commande. Ce contrôle rend la panne visible.
	 *
	 * @return string[]
	 */
	private static function unknown_statuses(): array {
		$known = array_map(
			static function ( $status ) {
				return preg_replace( '/^wc-/', '', $status );
			},
			array_keys( wc_get_order_statuses() )
		);

		return array_values( array_diff( Config::statuses(), $known ) );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
