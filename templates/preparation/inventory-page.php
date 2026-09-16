<?php
/**
 * Gabarit de l'onglet « Inventaire ».
 *
 * @var array $data Données fournies par RSMW\Preparation\Admin\StockPage.
 *
 * @package RealStockManager
 */

use RSMW\Preparation\Admin\StockPage;

defined( 'ABSPATH' ) || exit;

$rsmw_rows       = (array) $data['rows'];
$rsmw_categories = (array) $data['categories'];
$rsmw_report     = $data['report'];

// Verrou optimiste : totaux affichés, transportés dans un champ compact plutôt
// qu'en deux champs cachés par ligne (voir StockPage::read_inventory_refs()).
$rsmw_refs = array();

foreach ( $rsmw_rows as $rsmw_ref_row ) {
	$rsmw_refs[] = $rsmw_ref_row['id'] . ':' . $rsmw_ref_row['stock_total'] . ':' . $rsmw_ref_row['supply_total'];
}

$rsmw_refs = implode( ';', $rsmw_refs );
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

	<?php if ( is_array( $rsmw_report ) && ! empty( $rsmw_report['changed'] ) ) : ?>
		<div class="rsmw-card rsmw-card--report">
			<div class="rsmw-card__body">
				<p>
					<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_report['changed'] ); ?></span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: nombre de mouvements de stock déclenchés. */
							_n( 'référence(s) mise(s) à jour, dont %d mouvement de stock.', 'référence(s) mise(s) à jour, dont %d mouvements de stock.', (int) ( $rsmw_report['moved'] ?? 0 ), 'real-stock-manager-for-woocommerce' ),
							(int) ( $rsmw_report['moved'] ?? 0 )
						)
					);
					?>
				</p>

				<?php if ( ! empty( $rsmw_report['conflicts'] ) ) : ?>
					<p class="rsmw-report__warning">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: nombre de lignes en conflit. */
								_n( '%d ligne a changé entre l’affichage et l’enregistrement : elle n’a pas été modifiée. Rechargez la page et recommencez sur cette référence.', '%d lignes ont changé entre l’affichage et l’enregistrement : elles n’ont pas été modifiées. Rechargez la page et recommencez sur ces références.', (int) $rsmw_report['conflicts'], 'real-stock-manager-for-woocommerce' ),
								(int) $rsmw_report['conflicts']
							)
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['skipped'] ) ) : ?>
					<p class="rsmw-report__warning">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: nombre de lignes reportées. */
								_n( '%d ligne reportée : le plafond de mouvements par enregistrement est atteint. Enregistrez de nouveau pour la traiter.', '%d lignes reportées : le plafond de mouvements par enregistrement est atteint. Enregistrez de nouveau pour les traiter.', (int) $rsmw_report['skipped'], 'real-stock-manager-for-woocommerce' ),
								(int) $rsmw_report['skipped']
							)
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['missing'] ) ) : ?>
					<p class="rsmw-report__warning">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: nombre d'unités manquantes. */
								_n( '%d unité n’a pas pu être retirée : le total obtenu est supérieur au total saisi sur la référence concernée.', '%d unités n’ont pas pu être retirées : le total obtenu est supérieur au total saisi sur les références concernées.', (int) $rsmw_report['missing'], 'real-stock-manager-for-woocommerce' ),
								(int) $rsmw_report['missing']
							)
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['switched'] ) ) : ?>
					<p>
						<?php esc_html_e( 'Commande(s) passée(s) à « À empaqueter » :', 'real-stock-manager-for-woocommerce' ); ?>
						<?php echo esc_html( implode( ', ', array_map( 'strval', $rsmw_report['switched'] ) ) ); ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['dropped'] ) ) : ?>
					<p>
						<?php esc_html_e( 'Commande(s) redescendue(s) de « À empaqueter » :', 'real-stock-manager-for-woocommerce' ); ?>
						<?php echo esc_html( implode( ', ', array_map( 'strval', $rsmw_report['dropped'] ) ) ); ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['lines'] ) ) : ?>
					<ul class="rsmw-report__lines">
						<?php foreach ( $rsmw_report['lines'] as $rsmw_line ) : ?>
							<li>
								<a href="<?php echo esc_url( $rsmw_line['url'] ); ?>">#<?php echo esc_html( (string) $rsmw_line['num'] ); ?></a>
								<?php echo esc_html( (string) $rsmw_line['client'] ); ?>
								—
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: quantité concernée. */
										_n( '%d article', '%d articles', (int) $rsmw_line['qty'], 'real-stock-manager-for-woocommerce' ),
										(int) $rsmw_line['qty']
									)
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['truncated'] ) ) : ?>
					<p class="rsmw-report__warning"><?php esc_html_e( 'Liste partielle : d’autres commandes ont été affectées mais ne sont pas listées ci-dessus.', 'real-stock-manager-for-woocommerce' ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $rsmw_report['truncated_post'] ) ) : ?>
					<p class="rsmw-report__warning"><?php esc_html_e( 'Le formulaire a été reçu incomplet (limite max_input_vars du serveur) : toutes les lignes n’ont pas été enregistrées. Réduisez le nombre de lignes affichées (filtre) et recommencez.', 'real-stock-manager-for-woocommerce' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	<?php elseif ( is_array( $rsmw_report ) ) : ?>
		<div class="rsmw-card rsmw-card--dry">
			<div class="rsmw-card__body">
				<p><?php esc_html_e( 'Aucune valeur n’avait changé : rien n’a été enregistré.', 'real-stock-manager-for-woocommerce' ); ?></p>
			</div>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Le catalogue entier — un produit simple par ligne, une ligne par déclinaison pour un produit à variations. Saisissez ce que vous COMPTEZ : le stock physique total (articles déjà mis de côté pour des commandes clients inclus) et le total commandé au fournisseur. La répartition sur les commandes clients est refaite automatiquement : une hausse sert d’abord les commandes les plus anciennes et peut en faire passer en « À empaqueter » ; une baisse dépointe les plus récentes et peut les en faire redescendre. Seules les lignes dont la valeur a changé déclenchent un mouvement.', 'real-stock-manager-for-woocommerce' ); ?>
	</p>

	<?php if ( empty( $rsmw_rows ) ) : ?>
		<div class="rsmw-card">
			<div class="rsmw-card__body">
				<div class="rsmw-empty">
					<?php esc_html_e( 'Aucun produit trouvé.', 'real-stock-manager-for-woocommerce' ); ?>
				</div>
			</div>
		</div>
	<?php else : ?>

		<form method="post" id="rsmw-inventory-form">
			<?php echo $data['nonce_field']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balisage produit par wp_nonce_field(). ?>
			<input type="hidden" name="rsmw_inventory_count" value="<?php echo esc_attr( (string) count( $rsmw_rows ) ); ?>">
			<input type="hidden" name="rsmw_inventory_ref" value="<?php echo esc_attr( $rsmw_refs ); ?>">

			<div class="rsmw-card" id="rsmw-inventory">
				<div class="rsmw-card__header">
					<h2 class="rsmw-card__title"><?php esc_html_e( 'Catalogue', 'real-stock-manager-for-woocommerce' ); ?></h2>

					<div class="rsmw-toolbar">
						<label class="screen-reader-text" for="rsmw-inventory-search">
							<?php esc_html_e( 'Rechercher une référence', 'real-stock-manager-for-woocommerce' ); ?>
						</label>
						<input type="search" id="rsmw-inventory-search"
							placeholder="<?php esc_attr_e( 'Rechercher (nom, taille, SKU…)', 'real-stock-manager-for-woocommerce' ); ?>" autocomplete="off">

						<label class="screen-reader-text" for="rsmw-inventory-category">
							<?php esc_html_e( 'Filtrer par catégorie', 'real-stock-manager-for-woocommerce' ); ?>
						</label>
						<select id="rsmw-inventory-category">
							<option value=""><?php esc_html_e( 'Toutes les catégories', 'real-stock-manager-for-woocommerce' ); ?></option>
							<?php foreach ( $rsmw_categories as $rsmw_category ) : ?>
								<option value="<?php echo esc_attr( $rsmw_category->slug ); ?>">
									<?php echo esc_html( $rsmw_category->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="rsmw-card__body rsmw-card__body--flush">
					<table class="rsmw-table" id="rsmw-inventory-table">
						<thead>
							<tr>
								<th class="rsmw-thumb-col"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'real-stock-manager-for-woocommerce' ); ?></span></th>
								<th data-key="name"><?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num" data-key="stock"
									title="<?php esc_attr_e( 'Tout le stock physique de cette référence : ce qui est libre en rayon plus ce qui est déjà mis de côté pour des commandes clients', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Stock physique (total)', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num" data-key="supply"
									title="<?php esc_attr_e( 'Tout ce qui est commandé au fournisseur et pas encore reçu : la part non affectée plus celle déjà réservée à des commandes clients', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Commandé au fournisseur (total)', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num" data-key="attribue"
									title="<?php esc_attr_e( 'Part du total déjà affectée à des commandes clients : en bleu ce qui est prélevé sur le stock physique, en orange ce qui est couvert par une commande fournisseur', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Déjà attribué', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num" data-key="woo"
									title="<?php esc_attr_e( 'Stock WooCommerce : celui qui gouverne « en stock » / « rupture » côté client', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Stock WooCommerce', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rsmw_rows as $rsmw_row ) : ?>
							<?php
							$rsmw_id     = (int) $rsmw_row['id'];
							$rsmw_search = strtolower(
								remove_accents(
									$rsmw_row['name'] . ' ' . $rsmw_row['variant'] . ' ' . $rsmw_row['sku'] . ' ' . implode( ' ', $rsmw_row['categories'] )
								)
							);
							?>
							<tr data-search="<?php echo esc_attr( $rsmw_search ); ?>"
								data-name="<?php echo esc_attr( trim( $rsmw_row['name'] . ' ' . $rsmw_row['variant'] ) ); ?>"
								data-categories="<?php echo esc_attr( implode( ' ', $rsmw_row['category_slugs'] ) ); ?>"
								data-stock="<?php echo esc_attr( (string) $rsmw_row['stock_total'] ); ?>"
								data-supply="<?php echo esc_attr( (string) $rsmw_row['supply_total'] ); ?>"
								data-attribue="<?php echo esc_attr( (string) $rsmw_row['attribue'] ); ?>"
								data-woo="<?php echo esc_attr( $rsmw_row['woo_managed'] ? (string) $rsmw_row['woo_stock'] : '' ); ?>">
								<td class="rsmw-thumb-cell">
									<?php echo wp_kses_post( $rsmw_row['thumbnail'] ); ?>
								</td>
								<td>
									<?php if ( '' !== $rsmw_row['edit'] ) : ?>
										<strong><a href="<?php echo esc_url( $rsmw_row['edit'] ); ?>"><?php echo esc_html( $rsmw_row['name'] ); ?></a></strong>
									<?php else : ?>
										<strong><?php echo esc_html( $rsmw_row['name'] ); ?></strong>
									<?php endif; ?>
									<?php if ( '' !== $rsmw_row['variant'] ) : ?>
										<span class="rsmw-variant"> — <?php echo esc_html( $rsmw_row['variant'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== $rsmw_row['sku'] ) : ?>
										<br><span class="rsmw-sku"><?php echo esc_html( $rsmw_row['sku'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="rsmw-num">
									<label class="screen-reader-text" for="rsmw-stock-<?php echo esc_attr( (string) $rsmw_id ); ?>">
										<?php esc_html_e( 'Stock physique total', 'real-stock-manager-for-woocommerce' ); ?>
									</label>
									<?php // Pas de min="0" ni de required : une ligne masquée par le
									// filtre de recherche voit sa validation HTML5 ignorée par le
									// navigateur, qui bloque alors silencieusement toute la
									// soumission du formulaire. Le plancher réel est appliqué à la
									// lecture du POST (StockPage::read_inventory_input()) puis à
									// l'écriture, par Stock::set(). ?>
									<input type="number" step="1"
										class="rsmw-field__input--qty"
										id="rsmw-stock-<?php echo esc_attr( (string) $rsmw_id ); ?>"
										name="rsmw_inventory[<?php echo esc_attr( (string) $rsmw_id ); ?>][stock]"
										value="<?php echo esc_attr( (string) $rsmw_row['stock_total'] ); ?>">
								</td>
								<td class="rsmw-num">
									<label class="screen-reader-text" for="rsmw-supply-<?php echo esc_attr( (string) $rsmw_id ); ?>">
										<?php esc_html_e( 'Commandé au fournisseur, total', 'real-stock-manager-for-woocommerce' ); ?>
									</label>
									<input type="number" step="1"
										class="rsmw-field__input--qty"
										id="rsmw-supply-<?php echo esc_attr( (string) $rsmw_id ); ?>"
										name="rsmw_inventory[<?php echo esc_attr( (string) $rsmw_id ); ?>][supply]"
										value="<?php echo esc_attr( (string) $rsmw_row['supply_total'] ); ?>">
								</td>
								<td class="rsmw-num">
									<?php
									$rsmw_alloc = (int) $rsmw_row['attribue'];

									if ( $rsmw_alloc <= 0 ) {
										echo '<span class="rsmw-zero">·</span>';
									} else {
										/*
										 * Base 100 % = le TOTAL attribué : la barre est toujours
										 * pleine, elle ne montre que le PARTAGE entre stock
										 * physique et commande fournisseur. Pas de segment
										 * « non couvert » — ce qui n'est pas attribué n'est pas
										 * dans cette cellule.
										 *
										 * Le second pourcentage est le COMPLÉMENT du premier,
										 * jamais un second round() : deux arrondis indépendants
										 * laissent un liseré de piste visible sur une barre qui,
										 * elle, doit être exactement pleine.
										 */
										$rsmw_stock_pct = (int) round( (int) $rsmw_row['attribue_stock'] / $rsmw_alloc * 100 );
										$rsmw_ord_pct   = 100 - $rsmw_stock_pct;
										$rsmw_sentence  = sprintf(
											/* translators: 1: part prélevée sur le stock physique, 2: part couverte par une commande fournisseur. */
											__( '%1$d prélevé(s) sur le stock physique, %2$d couvert(s) par une commande fournisseur.', 'real-stock-manager-for-woocommerce' ),
											(int) $rsmw_row['attribue_stock'],
											(int) $rsmw_row['attribue_commande']
										);
										?>
										<span class="rsmw-alloc" title="<?php echo esc_attr( $rsmw_sentence ); ?>">
											<span class="rsmw-alloc__track" aria-hidden="true">
												<span class="rsmw-alloc__fill--stock" style="width:<?php echo (int) $rsmw_stock_pct; ?>%"></span>
												<span class="rsmw-alloc__fill--ordered" style="width:<?php echo (int) $rsmw_ord_pct; ?>%"></span>
											</span>
											<span class="rsmw-alloc__label" aria-hidden="true">
												<?php echo esc_html( (string) (int) $rsmw_row['attribue_stock'] ); ?>
												<?php if ( $rsmw_row['attribue_commande'] > 0 ) : ?>
													<span class="rsmw-alloc__ordered">+<?php echo esc_html( (string) (int) $rsmw_row['attribue_commande'] ); ?></span>
												<?php endif; ?>
											</span>
											<span class="screen-reader-text"><?php echo esc_html( $rsmw_sentence ); ?></span>
										</span>
										<?php
									}
									?>
								</td>
								<td class="rsmw-num">
									<?php if ( $rsmw_row['woo_editable'] ) : ?>
										<label class="screen-reader-text" for="rsmw-woo-<?php echo esc_attr( (string) $rsmw_id ); ?>">
											<?php esc_html_e( 'Stock WooCommerce', 'real-stock-manager-for-woocommerce' ); ?>
										</label>
										<input type="number" step="1"
											class="rsmw-field__input--qty rsmw-inventory__woo <?php echo $rsmw_row['woo_stock'] <= 0 ? 'rsmw-lack' : ''; ?>"
											id="rsmw-woo-<?php echo esc_attr( (string) $rsmw_id ); ?>"
											name="rsmw_inventory[<?php echo esc_attr( (string) $rsmw_id ); ?>][woo]"
											value="<?php echo esc_attr( (string) $rsmw_row['woo_stock'] ); ?>">
									<?php elseif ( $rsmw_row['woo_managed'] ) : ?>
										<span class="rsmw-inventory__woo <?php echo $rsmw_row['woo_stock'] <= 0 ? 'rsmw-lack' : ''; ?>"
											title="<?php esc_attr_e( 'Stock mutualisé, géré au niveau du produit parent : à corriger depuis sa fiche ou depuis Mouvement à l’unité.', 'real-stock-manager-for-woocommerce' ); ?>">
											<?php echo esc_html( (string) $rsmw_row['woo_stock'] ); ?>
										</span>
									<?php else : ?>
										<span class="rsmw-zero" title="<?php esc_attr_e( 'Cette référence ne suit pas de quantité WooCommerce.', 'real-stock-manager-for-woocommerce' ); ?>">·</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<div class="rsmw-empty" id="rsmw-inventory-noresult" style="display:none">
						<?php esc_html_e( 'Aucune référence ne correspond à ce filtre.', 'real-stock-manager-for-woocommerce' ); ?>
					</div>
				</div>

				<div class="rsmw-card__footer">
					<button type="submit" name="rsmw_inventory_submit" value="1" class="button button-primary">
						<?php esc_html_e( 'Enregistrer les modifications', 'real-stock-manager-for-woocommerce' ); ?>
					</button>
					<span class="rsmw-field__hint">
						<?php esc_html_e( 'Seules les lignes dont la valeur a changé déclenchent un mouvement.', 'real-stock-manager-for-woocommerce' ); ?>
					</span>
				</div>
			</div>
		</form>

	<?php endif; ?>
</div>
