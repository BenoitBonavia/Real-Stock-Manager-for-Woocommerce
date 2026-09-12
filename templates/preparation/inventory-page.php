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
					<?php esc_html_e( 'référence(s) mise(s) à jour.', 'real-stock-manager-for-woocommerce' ); ?>
				</p>
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
		<?php esc_html_e( 'Le catalogue entier — un produit simple par ligne, une ligne par déclinaison pour un produit à variations. Stock réel, commandé fournisseur et stock WooCommerce (celui affiché au client) sont modifiables directement ; seules les valeurs réellement changées sont enregistrées.', 'real-stock-manager-for-woocommerce' ); ?>
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
								<th data-key="name"><?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num" data-key="libre"><?php esc_html_e( 'Stock réel', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num" data-key="commande"><?php esc_html_e( 'Commandé', 'real-stock-manager-for-woocommerce' ); ?></th>
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
								data-libre="<?php echo esc_attr( (string) $rsmw_row['libre'] ); ?>"
								data-commande="<?php echo esc_attr( (string) $rsmw_row['commande'] ); ?>"
								data-woo="<?php echo esc_attr( $rsmw_row['woo_managed'] ? (string) $rsmw_row['woo_stock'] : '' ); ?>">
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
									<label class="screen-reader-text" for="rsmw-libre-<?php echo esc_attr( (string) $rsmw_id ); ?>">
										<?php esc_html_e( 'Stock réel', 'real-stock-manager-for-woocommerce' ); ?>
									</label>
									<input type="number" min="0" step="1"
										class="rsmw-field__input--qty"
										id="rsmw-libre-<?php echo esc_attr( (string) $rsmw_id ); ?>"
										name="rsmw_inventory[<?php echo esc_attr( (string) $rsmw_id ); ?>][libre]"
										value="<?php echo esc_attr( (string) $rsmw_row['libre'] ); ?>">
								</td>
								<td class="rsmw-num">
									<label class="screen-reader-text" for="rsmw-commande-<?php echo esc_attr( (string) $rsmw_id ); ?>">
										<?php esc_html_e( 'Commandé au fournisseur', 'real-stock-manager-for-woocommerce' ); ?>
									</label>
									<input type="number" min="0" step="1"
										class="rsmw-field__input--qty"
										id="rsmw-commande-<?php echo esc_attr( (string) $rsmw_id ); ?>"
										name="rsmw_inventory[<?php echo esc_attr( (string) $rsmw_id ); ?>][commande]"
										value="<?php echo esc_attr( (string) $rsmw_row['commande'] ); ?>">
								</td>
								<td class="rsmw-num">
									<?php if ( $rsmw_row['woo_managed'] ) : ?>
										<label class="screen-reader-text" for="rsmw-woo-<?php echo esc_attr( (string) $rsmw_id ); ?>">
											<?php esc_html_e( 'Stock WooCommerce', 'real-stock-manager-for-woocommerce' ); ?>
										</label>
										<input type="number" step="1"
											class="rsmw-field__input--qty rsmw-inventory__woo <?php echo $rsmw_row['woo_stock'] <= 0 ? 'rsmw-lack' : ''; ?>"
											id="rsmw-woo-<?php echo esc_attr( (string) $rsmw_id ); ?>"
											name="rsmw_inventory[<?php echo esc_attr( (string) $rsmw_id ); ?>][woo]"
											value="<?php echo esc_attr( (string) $rsmw_row['woo_stock'] ); ?>">
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
						<?php esc_html_e( 'Seules les valeurs réellement changées sont enregistrées.', 'real-stock-manager-for-woocommerce' ); ?>
					</span>
				</div>
			</div>
		</form>

	<?php endif; ?>
</div>
