<?php
/**
 * Gabarit de la page « Gestion stock ».
 *
 * @var array $data Données fournies par RSMW\Preparation\Admin\StockPage.
 *
 * @package RealStockManager
 */

use RSMW\Preparation\Admin\ReferenceContext;

defined( 'ABSPATH' ) || exit;

$rsmw_movement = $data['movement'];
$rsmw_errors   = (array) $data['errors'];
$rsmw_journal  = (array) $data['journal'];
$rsmw_reasons  = (array) $data['reasons'];
$rsmw_search   = (string) $data['search_nonce'];
$rsmw_context  = $rsmw_movement ? $rsmw_movement['context'] : null;
$rsmw_is_out   = $rsmw_movement && 'out' === $rsmw_movement['direction'];
?>
<div class="wrap woocommerce rsmw-wrap">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Gestion du stock', 'real-stock-manager-for-woocommerce' ); ?></h1>
	<a href="<?php echo esc_url( $data['needs_page_url'] ); ?>" class="page-title-action">
		<?php esc_html_e( 'Voir les besoins', 'real-stock-manager-for-woocommerce' ); ?>
	</a>
	<hr class="wp-header-end">

	<p class="description">
		<?php esc_html_e( 'Une entrée est affectée automatiquement aux commandes les plus anciennes qui l’attendent. Une sortie puise d’abord dans le stock libre, puis reprend aux commandes les plus récentes.', 'real-stock-manager-for-woocommerce' ); ?>
	</p>

	<?php foreach ( $rsmw_errors as $rsmw_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $rsmw_error ); ?></p></div>
	<?php endforeach; ?>

	<?php if ( $rsmw_movement ) : ?>
		<?php $rsmw_report = $rsmw_movement['report']; ?>
		<div class="rsmw-card <?php echo $rsmw_is_out ? 'rsmw-card--report-out' : 'rsmw-card--report'; ?>">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title">
					<?php
					echo $rsmw_is_out
						? esc_html__( 'Retrait enregistré', 'real-stock-manager-for-woocommerce' )
						: esc_html__( 'Entrée enregistrée', 'real-stock-manager-for-woocommerce' );
					?>
				</h2>
			</div>
			<div class="rsmw-card__body">

				<?php if ( $rsmw_is_out ) : ?>
					<p>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['du_libre'] ); ?></span>
						<?php esc_html_e( 'pris sur le stock libre', 'real-stock-manager-for-woocommerce' ); ?> ·
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['repris'] ); ?></span>
						<?php esc_html_e( 'repris à des commandes', 'real-stock-manager-for-woocommerce' ); ?> ·
						<?php esc_html_e( 'reste', 'real-stock-manager-for-woocommerce' ); ?>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['libre'] ); ?></span>
						<?php esc_html_e( 'en stock libre.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>
				<?php else : ?>
					<p>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['affecte'] ); ?></span>
						<?php esc_html_e( 'affecté(s) aux commandes', 'real-stock-manager-for-woocommerce' ); ?> ·
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
								<?php
								echo $rsmw_is_out
									? esc_html__( 'dépointé(s)', 'real-stock-manager-for-woocommerce' )
									: esc_html__( 'pointé(s)', 'real-stock-manager-for-woocommerce' );
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( ! $rsmw_is_out ) : ?>
					<p><?php esc_html_e( 'Aucune commande n’attendait cette référence. Tout est parti en stock libre.', 'real-stock-manager-for-woocommerce' ); ?></p>
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
								__( '%d article(s) n’ont pas pu être retirés : ni en stock libre, ni pointés sur une commande.', 'real-stock-manager-for-woocommerce' )
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
				<div>
					<h2 class="rsmw-card__title"><?php esc_html_e( 'Nouveau mouvement', 'real-stock-manager-for-woocommerce' ); ?></h2>
				</div>

				<div class="rsmw-segmented" role="group" aria-label="<?php esc_attr_e( 'Sens du mouvement', 'real-stock-manager-for-woocommerce' ); ?>">
					<div class="rsmw-segmented__option">
						<input type="radio" class="rsmw-segmented__input" id="rsmw-direction-in"
							name="rsmw_movement_direction" value="in" <?php checked( ! $rsmw_is_out ); ?>>
						<label class="rsmw-segmented__label rsmw-segmented__label--in" for="rsmw-direction-in">
							<?php esc_html_e( 'Entrée', 'real-stock-manager-for-woocommerce' ); ?>
						</label>
					</div>
					<div class="rsmw-segmented__option">
						<input type="radio" class="rsmw-segmented__input" id="rsmw-direction-out"
							name="rsmw_movement_direction" value="out" <?php checked( $rsmw_is_out ); ?>>
						<label class="rsmw-segmented__label rsmw-segmented__label--out" for="rsmw-direction-out">
							<?php esc_html_e( 'Retrait', 'real-stock-manager-for-woocommerce' ); ?>
						</label>
					</div>
				</div>
			</div>

			<div class="rsmw-card__body">

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
				<span class="rsmw-field__hint" data-rsmw-only="out" <?php echo $rsmw_is_out ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Un retrait peut dépointer des commandes clients.', 'real-stock-manager-for-woocommerce' ); ?>
				</span>
			</div>
		</form>

		<?php
		// Libellés du panneau, consommés par assets/js/stock-movement.js : le
		// contenu est reconstruit côté client après chaque sélection.
		?>
		<div class="rsmw-card rsmw-context" id="rsmw-context"
			data-free="<?php esc_attr_e( 'Stock libre', 'real-stock-manager-for-woocommerce' ); ?>"
			data-remaining="<?php esc_attr_e( 'Reste à préparer', 'real-stock-manager-for-woocommerce' ); ?>"
			data-orders="<?php esc_attr_e( 'Commandes en attente', 'real-stock-manager-for-woocommerce' ); ?>"
			data-missing="<?php esc_attr_e( 'Manquant', 'real-stock-manager-for-woocommerce' ); ?>"
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
							<span class="rsmw-stats__label"><?php esc_html_e( 'Commandes en attente', 'real-stock-manager-for-woocommerce' ); ?></span>
							<span class="rsmw-stats__value"><?php echo esc_html( (string) $rsmw_context['orders'] ); ?></span>
						</li>
					</ul>
				<?php else : ?>
					<p class="rsmw-context__empty">
						<?php esc_html_e( 'Choisissez une référence pour voir son stock libre et ce que les commandes en attendent.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="rsmw-card" id="rsmw-journal">
		<div class="rsmw-card__header">
			<h2 class="rsmw-card__title"><?php esc_html_e( 'Derniers mouvements', 'real-stock-manager-for-woocommerce' ); ?></h2>

			<?php if ( ! empty( $rsmw_journal ) ) : ?>
				<div class="rsmw-toolbar">
					<label class="screen-reader-text" for="rsmw-journal-search">
						<?php esc_html_e( 'Rechercher dans les mouvements', 'real-stock-manager-for-woocommerce' ); ?>
					</label>
					<input type="search" id="rsmw-journal-search"
						placeholder="<?php esc_attr_e( 'Rechercher…', 'real-stock-manager-for-woocommerce' ); ?>" autocomplete="off">

					<div class="rsmw-filters" role="group" aria-label="<?php esc_attr_e( 'Filtrer par sens', 'real-stock-manager-for-woocommerce' ); ?>">
						<button type="button" class="rsmw-filters__button" data-rsmw-filter="all" aria-pressed="true">
							<?php esc_html_e( 'Tous', 'real-stock-manager-for-woocommerce' ); ?>
						</button>
						<button type="button" class="rsmw-filters__button" data-rsmw-filter="in" aria-pressed="false">
							<?php esc_html_e( 'Entrées', 'real-stock-manager-for-woocommerce' ); ?>
						</button>
						<button type="button" class="rsmw-filters__button" data-rsmw-filter="out" aria-pressed="false">
							<?php esc_html_e( 'Retraits', 'real-stock-manager-for-woocommerce' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( empty( $rsmw_journal ) ) : ?>
			<div class="rsmw-card__body">
				<p class="rsmw-context__empty">
					<?php esc_html_e( 'Aucun mouvement enregistré pour le moment.', 'real-stock-manager-for-woocommerce' ); ?>
				</p>
			</div>
		<?php else : ?>
			<div class="rsmw-card__body rsmw-card__body--flush">
				<table class="rsmw-table" id="rsmw-journal-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num"><?php esc_html_e( 'Mouvement', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num"><?php esc_html_e( 'Commandes', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num"><?php esc_html_e( 'Libre après', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Motif', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Par', 'real-stock-manager-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rsmw_journal as $rsmw_entry ) : ?>
						<?php
						// Les entrées antérieures au retrait n'ont ni type ni motif.
						$rsmw_type   = isset( $rsmw_entry['type'] ) ? $rsmw_entry['type'] : 'in';
						$rsmw_out    = 'out' === $rsmw_type;
						$rsmw_orders = isset( $rsmw_entry['orders'] )
							? (int) $rsmw_entry['orders']
							: (int) ( isset( $rsmw_entry['affecte'] ) ? $rsmw_entry['affecte'] : 0 );
						$rsmw_motif  = isset( $rsmw_entry['motif'] ) ? $rsmw_entry['motif'] : '';
						$rsmw_user   = isset( $rsmw_entry['user'] ) ? $rsmw_entry['user'] : '';
						?>
						<tr data-rsmw-type="<?php echo esc_attr( $rsmw_out ? 'out' : 'in' ); ?>"
							data-rsmw-search="<?php echo esc_attr( strtolower( remove_accents( $rsmw_entry['label'] . ' ' . $rsmw_motif . ' ' . $rsmw_user ) ) ); ?>">
							<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $rsmw_entry['time'] ) ); ?></td>
							<td><?php echo esc_html( $rsmw_entry['label'] ); ?></td>
							<td class="rsmw-num <?php echo $rsmw_out ? 'rsmw-move-out' : 'rsmw-move-in'; ?>">
								<?php echo esc_html( ( $rsmw_out ? '−' : '+' ) . (int) $rsmw_entry['qty'] ); ?>
							</td>
							<td class="rsmw-num">
								<?php
								echo $rsmw_orders
									? esc_html( ( $rsmw_out ? '−' : '+' ) . $rsmw_orders )
									: '<span class="rsmw-zero">·</span>';
								?>
							</td>
							<td class="rsmw-num"><?php echo esc_html( (string) (int) $rsmw_entry['libre'] ); ?></td>
							<td><?php echo esc_html( $rsmw_motif ); ?></td>
							<td><?php echo esc_html( $rsmw_user ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="rsmw-empty" id="rsmw-journal-empty" hidden>
					<?php esc_html_e( 'Aucun mouvement ne correspond à ce filtre.', 'real-stock-manager-for-woocommerce' ); ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
