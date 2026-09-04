<?php
/**
 * Gabarit de l'onglet « Réception d'un colis ».
 *
 * @var array $data Données fournies par RSMW\Preparation\Admin\StockPage.
 *
 * @package RealStockManager
 */

use RSMW\Preparation\Admin\StockPage;
use RSMW\Preparation\Admin\View;

defined( 'ABSPATH' ) || exit;

$rsmw_pending    = (array) $data['pending'];
$rsmw_submitted  = (array) $data['submitted'];
$rsmw_simulation = $data['simulation'];
$rsmw_report     = $data['report'];
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

	<?php if ( $rsmw_report ) : ?>
		<div class="rsmw-card rsmw-card--report">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title"><?php esc_html_e( 'Réception enregistrée', 'real-stock-manager-for-woocommerce' ); ?></h2>
			</div>
			<div class="rsmw-card__body">
				<p>
					<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['ok_total'] ); ?></span>
					<?php esc_html_e( 'conforme(s)', 'real-stock-manager-for-woocommerce' ); ?> ·
					<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['allocated_total'] ); ?></span>
					<?php esc_html_e( 'affecté(s) aux commandes', 'real-stock-manager-for-woocommerce' ); ?> ·
					<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['free_total'] ); ?></span>
					<?php esc_html_e( 'en stock libre', 'real-stock-manager-for-woocommerce' ); ?> ·
					<span class="rsmw-report__figure rsmw-lack"><?php echo esc_html( (string) (int) $rsmw_report['defective_total'] ); ?></span>
					<?php esc_html_e( 'défectueux écarté(s).', 'real-stock-manager-for-woocommerce' ); ?>
				</p>

				<?php if ( ! empty( $rsmw_report['completed'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Passées en « À empaqueter » :', 'real-stock-manager-for-woocommerce' ); ?></strong>
						<?php
						$rsmw_numbers = array();

						foreach ( $rsmw_report['completed'] as $rsmw_done ) {
							$rsmw_numbers[] = '#' . $rsmw_done['num'];
						}

						echo esc_html( implode( ', ', array_unique( $rsmw_numbers ) ) );
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['missing_defective'] ) ) : ?>
					<p class="rsmw-lack">
						<?php
						printf(
							esc_html(
								/* translators: %d: nombre de défectueux sans contrepartie. */
								__( '%d défectueux n’ont trouvé aucune commande fournisseur à réduire : ils ont été comptés, mais rien n’était attendu pour eux.', 'real-stock-manager-for-woocommerce' )
							),
							(int) $rsmw_report['missing_defective']
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['lines'] ) ) : ?>
					<table class="rsmw-table" style="margin-top:12px">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Conforme', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Affecté', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Stock libre', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Défectueux', 'real-stock-manager-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rsmw_report['lines'] as $rsmw_line ) : ?>
							<tr>
								<td><?php echo esc_html( $rsmw_line['label'] ); ?></td>
								<td class="rsmw-num"><?php echo esc_html( (string) (int) $rsmw_line['ok'] ); ?></td>
								<td class="rsmw-num"><?php echo esc_html( (string) (int) $rsmw_line['allocated'] ); ?></td>
								<td class="rsmw-num"><?php echo esc_html( (string) (int) $rsmw_line['free'] ); ?></td>
								<td class="rsmw-num <?php echo $rsmw_line['defective'] > 0 ? 'rsmw-lack' : 'rsmw-zero'; ?>">
									<?php echo esc_html( (string) (int) $rsmw_line['defective'] ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Saisissez ce que le colis contient réellement. Rien n’est enregistré tant que vous n’avez pas validé, et les lignes laissées vides sont ignorées. Un article défectueux n’entre pas en stock : il cesse d’être attendu et la référence remonte dans « Reste à commander ».', 'real-stock-manager-for-woocommerce' ); ?>
	</p>

	<?php if ( empty( $rsmw_pending ) ) : ?>
		<div class="rsmw-card">
			<div class="rsmw-card__body">
				<div class="rsmw-empty">
					<?php esc_html_e( 'Aucune commande fournisseur en cours : il n’y a rien à réceptionner.', 'real-stock-manager-for-woocommerce' ); ?>
					<br>
					<a href="<?php echo esc_url( StockPage::tab_url( StockPage::TAB_MOVEMENT ) ); ?>">
						<?php esc_html_e( 'Enregistrer une commande fournisseur', 'real-stock-manager-for-woocommerce' ); ?>
					</a>
				</div>
			</div>
		</div>
	<?php else : ?>

		<form method="post" id="rsmw-reception-form">
			<?php echo $data['nonce_field']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balisage produit par wp_nonce_field(). ?>

			<?php if ( $rsmw_simulation ) : ?>
				<div class="rsmw-card rsmw-card--dry">
					<div class="rsmw-card__header">
						<h2 class="rsmw-card__title"><?php esc_html_e( 'Vérification — rien n’a été enregistré', 'real-stock-manager-for-woocommerce' ); ?></h2>
					</div>
					<div class="rsmw-card__body">
						<?php if ( 0 === (int) $rsmw_simulation['ok_total'] && 0 === (int) $rsmw_simulation['defective_total'] ) : ?>
							<p><?php esc_html_e( 'Aucune quantité saisie.', 'real-stock-manager-for-woocommerce' ); ?></p>
						<?php else : ?>
							<p>
								<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_simulation['ok_total'] ); ?></span>
								<?php esc_html_e( 'conforme(s) seraient reçus, dont', 'real-stock-manager-for-woocommerce' ); ?>
								<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_simulation['allocated_total'] ); ?></span>
								<?php esc_html_e( 'affecté(s) aux commandes et', 'real-stock-manager-for-woocommerce' ); ?>
								<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_simulation['free_total'] ); ?></span>
								<?php esc_html_e( 'en stock libre.', 'real-stock-manager-for-woocommerce' ); ?>
							</p>
							<p>
								<span class="rsmw-report__figure rsmw-lack"><?php echo esc_html( (string) (int) $rsmw_simulation['defective_total'] ); ?></span>
								<?php esc_html_e( 'défectueux cesseraient d’être attendus.', 'real-stock-manager-for-woocommerce' ); ?>
							</p>

							<?php if ( ! empty( $rsmw_simulation['completed'] ) ) : ?>
								<p>
									<strong><?php esc_html_e( 'Passeraient en « À empaqueter » :', 'real-stock-manager-for-woocommerce' ); ?></strong>
									<?php foreach ( $rsmw_simulation['completed'] as $rsmw_done ) : ?>
										<a href="<?php echo esc_url( $rsmw_done['url'] ); ?>">#<?php echo esc_html( $rsmw_done['num'] ); ?></a>
									<?php endforeach; ?>
								</p>
							<?php endif; ?>

							<?php foreach ( $rsmw_simulation['warnings'] as $rsmw_warning ) : ?>
								<p class="rsmw-lack">⚠ <?php echo esc_html( $rsmw_warning ); ?></p>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<?php if ( (int) $rsmw_simulation['ok_total'] > 0 || (int) $rsmw_simulation['defective_total'] > 0 ) : ?>
						<div class="rsmw-card__footer">
							<button type="submit" name="rsmw_reception_submit" value="1" class="button button-primary">
								<?php esc_html_e( 'Enregistrer la réception', 'real-stock-manager-for-woocommerce' ); ?>
							</button>
							<span class="rsmw-field__hint">
								<?php esc_html_e( 'Corrigez la saisie ci-dessous si besoin, puis vérifiez à nouveau.', 'real-stock-manager-for-woocommerce' ); ?>
							</span>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="rsmw-kpis">
				<div class="rsmw-kpi">
					<div class="rsmw-kpi__label"><?php esc_html_e( 'Références saisies', 'real-stock-manager-for-woocommerce' ); ?></div>
					<div class="rsmw-kpi__value" id="rsmw-k-refs">0</div>
				</div>
				<div class="rsmw-kpi">
					<div class="rsmw-kpi__label"><?php esc_html_e( 'Conformes', 'real-stock-manager-for-woocommerce' ); ?></div>
					<div class="rsmw-kpi__value" id="rsmw-k-ok">0</div>
				</div>
				<div class="rsmw-kpi rsmw-kpi--alert">
					<div class="rsmw-kpi__label"><?php esc_html_e( 'Défectueux', 'real-stock-manager-for-woocommerce' ); ?></div>
					<div class="rsmw-kpi__value" id="rsmw-k-defective">0</div>
				</div>
				<div class="rsmw-kpi rsmw-kpi--ordered">
					<div class="rsmw-kpi__label"><?php esc_html_e( 'Restera attendu', 'real-stock-manager-for-woocommerce' ); ?></div>
					<div class="rsmw-kpi__value" id="rsmw-k-remaining">0</div>
				</div>
			</div>

			<div class="rsmw-card" id="rsmw-reception">
				<div class="rsmw-card__header">
					<h2 class="rsmw-card__title"><?php esc_html_e( 'En attente de réception', 'real-stock-manager-for-woocommerce' ); ?></h2>

					<div class="rsmw-toolbar">
						<label class="screen-reader-text" for="rsmw-reception-search">
							<?php esc_html_e( 'Rechercher une référence', 'real-stock-manager-for-woocommerce' ); ?>
						</label>
						<input type="search" id="rsmw-reception-search"
							placeholder="<?php esc_attr_e( 'Rechercher (nom, taille, SKU…)', 'real-stock-manager-for-woocommerce' ); ?>" autocomplete="off">
						<button type="button" class="button" id="rsmw-fill-visible">
							<?php esc_html_e( 'Tout reçu conforme', 'real-stock-manager-for-woocommerce' ); ?>
						</button>
						<button type="button" class="button" id="rsmw-clear-all">
							<?php esc_html_e( 'Tout effacer', 'real-stock-manager-for-woocommerce' ); ?>
						</button>
					</div>
				</div>

				<div class="rsmw-card__body rsmw-card__body--flush">
					<table class="rsmw-table" id="rsmw-reception-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Attendu', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Commandes', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Conforme', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Défectueux', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num"><?php esc_html_e( 'Restera attendu', 'real-stock-manager-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rsmw_pending as $rsmw_row ) : ?>
							<?php
							$rsmw_id  = (int) $rsmw_row['id'];
							$rsmw_ok  = isset( $rsmw_submitted[ $rsmw_id ]['ok'] ) ? (int) $rsmw_submitted[ $rsmw_id ]['ok'] : '';
							$rsmw_def = isset( $rsmw_submitted[ $rsmw_id ]['defective'] ) ? (int) $rsmw_submitted[ $rsmw_id ]['defective'] : '';
							?>
							<tr data-rsmw-expected="<?php echo esc_attr( (string) $rsmw_row['expected'] ); ?>"
								data-rsmw-search="<?php echo esc_attr( strtolower( remove_accents( $rsmw_row['name'] . ' ' . $rsmw_row['variant'] . ' ' . $rsmw_row['sku'] ) ) ); ?>">
								<td>
									<strong><?php echo esc_html( $rsmw_row['name'] ); ?></strong>
									<?php if ( '' !== $rsmw_row['variant'] ) : ?>
										<span class="rsmw-variant"> — <?php echo esc_html( $rsmw_row['variant'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== $rsmw_row['sku'] ) : ?>
										<br><span class="rsmw-sku"><?php echo esc_html( $rsmw_row['sku'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="rsmw-num rsmw-ordered"><?php echo esc_html( (string) $rsmw_row['expected'] ); ?></td>
								<td class="rsmw-num">
									<?php
									echo $rsmw_row['orders']
										? esc_html( (string) $rsmw_row['orders'] )
										: '<span class="rsmw-zero">·</span>';
									?>
								</td>
								<td class="rsmw-num">
									<label class="screen-reader-text" for="rsmw-ok-<?php echo esc_attr( (string) $rsmw_id ); ?>">
										<?php esc_html_e( 'Quantité conforme', 'real-stock-manager-for-woocommerce' ); ?>
									</label>
									<input type="number" min="0" step="1" placeholder="0"
										class="rsmw-field__input--qty rsmw-reception__ok"
										id="rsmw-ok-<?php echo esc_attr( (string) $rsmw_id ); ?>"
										name="rsmw_reception[<?php echo esc_attr( (string) $rsmw_id ); ?>][ok]"
										value="<?php echo esc_attr( (string) $rsmw_ok ); ?>">
								</td>
								<td class="rsmw-num">
									<label class="screen-reader-text" for="rsmw-def-<?php echo esc_attr( (string) $rsmw_id ); ?>">
										<?php esc_html_e( 'Quantité défectueuse', 'real-stock-manager-for-woocommerce' ); ?>
									</label>
									<input type="number" min="0" step="1" placeholder="0"
										class="rsmw-field__input--qty rsmw-reception__defective"
										id="rsmw-def-<?php echo esc_attr( (string) $rsmw_id ); ?>"
										name="rsmw_reception[<?php echo esc_attr( (string) $rsmw_id ); ?>][defective]"
										value="<?php echo esc_attr( (string) $rsmw_def ); ?>">
								</td>
								<td class="rsmw-num rsmw-reception__remaining"><?php echo esc_html( (string) $rsmw_row['expected'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<div class="rsmw-empty" id="rsmw-reception-empty" hidden>
						<?php esc_html_e( 'Aucune référence ne correspond à cette recherche.', 'real-stock-manager-for-woocommerce' ); ?>
					</div>
				</div>

				<div class="rsmw-card__footer">
					<button type="submit" name="rsmw_reception_check" value="1" class="button button-primary">
						<?php esc_html_e( 'Vérifier la réception', 'real-stock-manager-for-woocommerce' ); ?>
					</button>
					<span class="rsmw-field__hint">
						<?php esc_html_e( 'Rien ne sera écrit : vous verrez d’abord l’effet sur vos commandes clients.', 'real-stock-manager-for-woocommerce' ); ?>
					</span>
				</div>
			</div>
		</form>

	<?php endif; ?>

	<?php View::render( 'journal', array( 'journal' => $data['journal'] ) ); ?>
</div>
