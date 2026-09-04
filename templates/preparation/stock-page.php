<?php
/**
 * Gabarit de la page « Gestion stock ».
 *
 * @var array $data Données fournies par RSMW\Preparation\Admin\StockPage.
 *
 * @package RealStockManager
 */

use RSMW\Preparation\Admin\ReferenceContext;
use RSMW\Preparation\Admin\StockPage;
use RSMW\Preparation\Admin\View;

defined( 'ABSPATH' ) || exit;

$rsmw_movement = $data['movement'];
$rsmw_errors   = (array) $data['errors'];
$rsmw_reasons  = (array) $data['reasons'];
$rsmw_search   = (string) $data['search_nonce'];
$rsmw_context  = $rsmw_movement ? $rsmw_movement['context'] : null;

$rsmw_direction = $rsmw_movement ? (string) $rsmw_movement['direction'] : 'in';
$rsmw_is_out    = 'out' === $rsmw_direction;
$rsmw_is_supply = in_array( $rsmw_direction, array( 'order', 'unorder' ), true );

$rsmw_titles = array(
	'in'      => __( 'Entrée enregistrée', 'real-stock-manager-for-woocommerce' ),
	'order'   => __( 'Commande fournisseur enregistrée', 'real-stock-manager-for-woocommerce' ),
	'unorder' => __( 'Annulation enregistrée', 'real-stock-manager-for-woocommerce' ),
	'out'     => __( 'Retrait enregistré', 'real-stock-manager-for-woocommerce' ),
);

$rsmw_directions = array(
	'in'      => __( 'Entrée', 'real-stock-manager-for-woocommerce' ),
	'order'   => __( 'Commande', 'real-stock-manager-for-woocommerce' ),
	'unorder' => __( 'Annulation', 'real-stock-manager-for-woocommerce' ),
	'out'     => __( 'Retrait', 'real-stock-manager-for-woocommerce' ),
);

$rsmw_direction_hints = array(
	'in'      => __( 'Marchandise reçue : elle entre en stock et sert les commandes les plus anciennes.', 'real-stock-manager-for-woocommerce' ),
	'order'   => __( 'Commande passée au fournisseur : rien n’entre en stock, mais les commandes clients les plus anciennes sont marquées comme couvertes.', 'real-stock-manager-for-woocommerce' ),
	'unorder' => __( 'Commande fournisseur annulée ou saisie par erreur.', 'real-stock-manager-for-woocommerce' ),
	'out'     => __( 'Article défectueux, cassé, perdu ou renvoyé. Peut dépointer des commandes clients.', 'real-stock-manager-for-woocommerce' ),
);
?>
<div class="wrap woocommerce rsmw-wrap">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Gestion du stock', 'real-stock-manager-for-woocommerce' ); ?></h1>
	<a href="<?php echo esc_url( $data['needs_page_url'] ); ?>" class="page-title-action">
		<?php esc_html_e( 'Voir les besoins', 'real-stock-manager-for-woocommerce' ); ?>
	</a>
	<hr class="wp-header-end">

	<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
		<?php foreach ( (array) $data['tabs'] as $rsmw_slug => $rsmw_label ) : ?>
			<a href="<?php echo esc_url( StockPage::tab_url( $rsmw_slug ) ); ?>"
				class="nav-tab <?php echo $data['tab'] === $rsmw_slug ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $rsmw_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<p class="description">
		<?php esc_html_e( 'Une entrée est affectée automatiquement aux commandes les plus anciennes qui l’attendent. Une commande fournisseur les marque comme couvertes sans les rendre prêtes à empaqueter. Une sortie puise d’abord dans le stock libre, puis reprend aux commandes les plus récentes.', 'real-stock-manager-for-woocommerce' ); ?>
	</p>

	<?php foreach ( $rsmw_errors as $rsmw_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $rsmw_error ); ?></p></div>
	<?php endforeach; ?>

	<?php if ( $rsmw_movement ) : ?>
		<?php
		$rsmw_report = $rsmw_movement['report'];

		if ( $rsmw_is_supply ) {
			$rsmw_report_class = 'rsmw-card--report-ordered';
		} elseif ( $rsmw_is_out ) {
			$rsmw_report_class = 'rsmw-card--report-out';
		} else {
			$rsmw_report_class = 'rsmw-card--report';
		}
		?>
		<div class="rsmw-card <?php echo esc_attr( $rsmw_report_class ); ?>">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title">
					<?php echo esc_html( isset( $rsmw_titles[ $rsmw_direction ] ) ? $rsmw_titles[ $rsmw_direction ] : '' ); ?>
				</h2>
			</div>
			<div class="rsmw-card__body">

				<?php if ( 'in' === $rsmw_direction ) : ?>
					<p>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['affecte'] ); ?></span>
						<?php esc_html_e( 'affecté(s) aux commandes', 'real-stock-manager-for-woocommerce' ); ?> ·
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['libre'] ); ?></span>
						<?php esc_html_e( 'en stock libre.', 'real-stock-manager-for-woocommerce' ); ?>
						<?php if ( ! empty( $rsmw_report['converti'] ) ) : ?>
							<br>
							<?php
							printf(
								esc_html(
									/* translators: %d: nombre d'articles convertis. */
									__( '%d article(s) qui étaient en commande fournisseur viennent d’arriver et ne sont plus attendus.', 'real-stock-manager-for-woocommerce' )
								),
								(int) $rsmw_report['converti']
							);
							?>
						<?php endif; ?>
					</p>

				<?php elseif ( 'order' === $rsmw_direction ) : ?>
					<p>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['affecte'] ); ?></span>
						<?php esc_html_e( 'réservé(s) sur des commandes clients', 'real-stock-manager-for-woocommerce' ); ?> ·
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['libre'] ); ?></span>
						<?php esc_html_e( 'en commande sans affectation.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>

				<?php elseif ( 'unorder' === $rsmw_direction ) : ?>
					<p>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['du_libre'] ); ?></span>
						<?php esc_html_e( 'repris sur le commandé non affecté', 'real-stock-manager-for-woocommerce' ); ?> ·
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['repris'] ); ?></span>
						<?php esc_html_e( 'repris à des commandes clients', 'real-stock-manager-for-woocommerce' ); ?> ·
						<?php esc_html_e( 'reste', 'real-stock-manager-for-woocommerce' ); ?>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['libre'] ); ?></span>
						<?php esc_html_e( 'en commande.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>

				<?php else : ?>
					<p>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['du_libre'] ); ?></span>
						<?php esc_html_e( 'pris sur le stock libre', 'real-stock-manager-for-woocommerce' ); ?> ·
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['repris'] ); ?></span>
						<?php esc_html_e( 'repris à des commandes', 'real-stock-manager-for-woocommerce' ); ?> ·
						<?php esc_html_e( 'reste', 'real-stock-manager-for-woocommerce' ); ?>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['libre'] ); ?></span>
						<?php esc_html_e( 'en stock libre.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['lignes'] ) ) : ?>
					<ul>
						<?php foreach ( $rsmw_report['lignes'] as $rsmw_line ) : ?>
							<li>
								<a href="<?php echo esc_url( $rsmw_line['url'] ); ?>">#<?php echo esc_html( $rsmw_line['num'] ); ?></a>
								<?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'du %s', 'real-stock-manager-for-woocommerce' ), $rsmw_line['date'] ) ); ?>
								<?php if ( '' !== $rsmw_line['client'] ) : ?>
									(<?php echo esc_html( $rsmw_line['client'] ); ?>)
								<?php endif; ?>
								— <strong><?php echo esc_html( (string) (int) $rsmw_line['qty'] ); ?></strong>
								<?php esc_html_e( 'article(s)', 'real-stock-manager-for-woocommerce' ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( 'in' === $rsmw_direction ) : ?>
					<p><?php esc_html_e( 'Aucune commande n’attendait cette référence. Tout est parti en stock libre.', 'real-stock-manager-for-woocommerce' ); ?></p>
				<?php elseif ( 'order' === $rsmw_direction ) : ?>
					<p><?php esc_html_e( 'Aucune commande n’attendait cette référence. La quantité reste en commande, sans affectation.', 'real-stock-manager-for-woocommerce' ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['basculees'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Passées en « À empaqueter » :', 'real-stock-manager-for-woocommerce' ); ?></strong>
						<?php echo esc_html( '#' . implode( ', #', $rsmw_report['basculees'] ) ); ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['rendues'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Sorties de « À empaqueter » :', 'real-stock-manager-for-woocommerce' ); ?></strong>
						<?php echo esc_html( '#' . implode( ', #', $rsmw_report['rendues'] ) ); ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['manquant'] ) ) : ?>
					<p class="rsmw-lack">
						<?php
						printf(
							esc_html(
								/* translators: %d: nombre d'articles introuvables. */
								__( '%d article(s) n’ont pas pu être retirés : introuvables.', 'real-stock-manager-for-woocommerce' )
							),
							(int) $rsmw_report['manquant']
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="rsmw-console"
		data-action="<?php echo esc_attr( ReferenceContext::ACTION ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( ReferenceContext::NONCE ) ); ?>">

		<form method="post" class="rsmw-card">
			<?php echo $data['nonce_field']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balisage produit par wp_nonce_field(). ?>

			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title"><?php esc_html_e( 'Nouveau mouvement', 'real-stock-manager-for-woocommerce' ); ?></h2>

				<div class="rsmw-segmented" role="group" aria-label="<?php esc_attr_e( 'Sens du mouvement', 'real-stock-manager-for-woocommerce' ); ?>">
					<?php foreach ( $rsmw_directions as $rsmw_value => $rsmw_label ) : ?>
						<div class="rsmw-segmented__option">
							<input type="radio" class="rsmw-segmented__input" id="rsmw-direction-<?php echo esc_attr( $rsmw_value ); ?>"
								name="rsmw_movement_direction" value="<?php echo esc_attr( $rsmw_value ); ?>"
								<?php checked( $rsmw_direction, $rsmw_value ); ?>>
							<label class="rsmw-segmented__label rsmw-segmented__label--<?php echo esc_attr( $rsmw_value ); ?>"
								for="rsmw-direction-<?php echo esc_attr( $rsmw_value ); ?>"
								title="<?php echo esc_attr( $rsmw_direction_hints[ $rsmw_value ] ); ?>">
								<?php echo esc_html( $rsmw_label ); ?>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="rsmw-card__body">

				<?php foreach ( $rsmw_direction_hints as $rsmw_value => $rsmw_hint ) : ?>
					<p class="rsmw-field__hint rsmw-direction-hint" data-rsmw-hint="<?php echo esc_attr( $rsmw_value ); ?>"
						<?php echo $rsmw_direction === $rsmw_value ? '' : 'hidden'; ?>>
						<?php echo esc_html( $rsmw_hint ); ?>
					</p>
				<?php endforeach; ?>

				<div class="rsmw-field">
					<label class="rsmw-field__label" for="rsmw_movement_product">
						<?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?>
					</label>
					<select class="wc-product-search" id="rsmw_movement_product" name="rsmw_movement_product"
						data-placeholder="<?php esc_attr_e( 'Tapez un nom de produit ou une taille…', 'real-stock-manager-for-woocommerce' ); ?>"
						data-action="woocommerce_json_search_products_and_variations"
						data-security="<?php echo esc_attr( $rsmw_search ); ?>"
						data-allow_clear="true"></select>
					<p class="rsmw-field__hint">
						<?php esc_html_e( 'Pour un produit à variations, choisissez bien la variation concernée.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>
				</div>

				<div class="rsmw-field">
					<label class="rsmw-field__label" for="rsmw_movement_sku">
						<?php esc_html_e( 'Ou saisissez un SKU', 'real-stock-manager-for-woocommerce' ); ?>
					</label>
					<input type="text" id="rsmw_movement_sku" name="rsmw_movement_sku" class="regular-text">
				</div>

				<div class="rsmw-field">
					<label class="rsmw-field__label" for="rsmw_movement_qty">
						<?php esc_html_e( 'Quantité', 'real-stock-manager-for-woocommerce' ); ?>
					</label>
					<input type="number" id="rsmw_movement_qty" name="rsmw_movement_qty"
						class="rsmw-field__input--qty" min="1" step="1" value="1" required>
				</div>

				<div class="rsmw-field <?php echo $rsmw_is_out ? '' : 'rsmw-field--hidden'; ?>" data-rsmw-only="out">
					<label class="rsmw-field__label" for="rsmw_movement_reason">
						<?php esc_html_e( 'Motif du retrait', 'real-stock-manager-for-woocommerce' ); ?>
					</label>
					<select id="rsmw_movement_reason" name="rsmw_movement_reason">
						<?php foreach ( $rsmw_reasons as $rsmw_value => $rsmw_label ) : ?>
							<option value="<?php echo esc_attr( $rsmw_value ); ?>"><?php echo esc_html( $rsmw_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="rsmw-field__hint">
						<?php esc_html_e( 'Reporté dans la note des commandes touchées.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>
				</div>
			</div>

			<div class="rsmw-card__footer">
				<button type="submit" name="rsmw_stock_submit" value="1" class="button button-primary">
					<?php esc_html_e( 'Enregistrer le mouvement', 'real-stock-manager-for-woocommerce' ); ?>
				</button>
			</div>
		</form>

		<?php
		// Libellés du panneau, consommés par assets/js/stock-movement.js : le
		// contenu est reconstruit côté client après chaque sélection.
		?>
		<div class="rsmw-card rsmw-context" id="rsmw-context"
			data-free="<?php esc_attr_e( 'Stock libre', 'real-stock-manager-for-woocommerce' ); ?>"
			data-remaining="<?php esc_attr_e( 'Reste à préparer', 'real-stock-manager-for-woocommerce' ); ?>"
			data-ordered="<?php esc_attr_e( 'En commande', 'real-stock-manager-for-woocommerce' ); ?>"
			data-orders="<?php esc_attr_e( 'Commandes en attente', 'real-stock-manager-for-woocommerce' ); ?>"
			data-missing="<?php esc_attr_e( 'Reste à commander', 'real-stock-manager-for-woocommerce' ); ?>"
			data-oldest="<?php esc_attr_e( 'Plus ancienne :', 'real-stock-manager-for-woocommerce' ); ?>"
			data-on="<?php esc_attr_e( 'du', 'real-stock-manager-for-woocommerce' ); ?>"
			data-not-found="<?php esc_attr_e( 'Référence introuvable.', 'real-stock-manager-for-woocommerce' ); ?>"
			data-network-error="<?php esc_attr_e( 'Impossible de récupérer l’état de cette référence.', 'real-stock-manager-for-woocommerce' ); ?>">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title"><?php esc_html_e( 'Sélection', 'real-stock-manager-for-woocommerce' ); ?></h2>
			</div>
			<div class="rsmw-card__body">
				<?php if ( $rsmw_context ) : ?>
					<div class="rsmw-context__name"><?php echo esc_html( $rsmw_context['label'] ); ?></div>
					<?php if ( '' !== $rsmw_context['sku'] ) : ?>
						<div class="rsmw-context__sku"><?php echo esc_html( $rsmw_context['sku'] ); ?></div>
					<?php endif; ?>
					<ul class="rsmw-stats">
						<li class="rsmw-stats__row">
							<span class="rsmw-stats__label"><?php esc_html_e( 'Stock libre', 'real-stock-manager-for-woocommerce' ); ?></span>
							<span class="rsmw-stats__value"><?php echo esc_html( (string) $rsmw_context['free'] ); ?></span>
						</li>
						<li class="rsmw-stats__row">
							<span class="rsmw-stats__label"><?php esc_html_e( 'Reste à préparer', 'real-stock-manager-for-woocommerce' ); ?></span>
							<span class="rsmw-stats__value"><?php echo esc_html( (string) $rsmw_context['remaining'] ); ?></span>
						</li>
						<li class="rsmw-stats__row">
							<span class="rsmw-stats__label"><?php esc_html_e( 'En commande', 'real-stock-manager-for-woocommerce' ); ?></span>
							<span class="rsmw-stats__value rsmw-stats__value--ordered"><?php echo esc_html( (string) $rsmw_context['ordered'] ); ?></span>
						</li>
						<li class="rsmw-stats__row">
							<span class="rsmw-stats__label"><?php esc_html_e( 'Commandes en attente', 'real-stock-manager-for-woocommerce' ); ?></span>
							<span class="rsmw-stats__value"><?php echo esc_html( (string) $rsmw_context['orders'] ); ?></span>
						</li>
					</ul>
				<?php else : ?>
					<p class="rsmw-context__empty">
						<?php esc_html_e( 'Choisissez une référence pour voir son stock libre, ce qui est déjà commandé, et ce que les commandes en attendent.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php View::render( 'journal', array( 'journal' => $data['journal'] ) ); ?>
</div>
