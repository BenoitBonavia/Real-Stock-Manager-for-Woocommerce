<?php
/**
 * Gabarit de la page « Besoins Back in Stock ».
 *
 * @var array $data Données fournies par RSMW\BackInStock\Admin\Page.
 *
 * @package RealStockManager
 */

defined( 'ABSPATH' ) || exit;

$rsmw_bis_host_active = ! empty( $data['host_active'] );
?>
<div class="wrap woocommerce rsmw-wrap">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Besoins Back in Stock', 'real-stock-manager-for-woocommerce' ); ?></h1>
	<a href="<?php echo esc_url( $data['needs_page_url'] ); ?>" class="page-title-action">
		<?php esc_html_e( 'Besoins pour commande', 'real-stock-manager-for-woocommerce' ); ?>
	</a>
	<hr class="wp-header-end">

	<p class="description">
		<?php esc_html_e( 'Pour chaque référence demandée en retour en stock, ce que le stock libre — déjà en boutique ou commandé au fournisseur — permettrait d’honorer. Les commandes clients en attente passent toujours avant la liste d’attente : le stock compté ici est ce qu’il en resterait.', 'real-stock-manager-for-woocommerce' ); ?>
	</p>

	<?php if ( ! $rsmw_bis_host_active ) : ?>

		<div class="rsmw-card">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title"><?php esc_html_e( 'Back In Stock Notifier for WooCommerce', 'real-stock-manager-for-woocommerce' ); ?></h2>
			</div>
			<div class="rsmw-card__body">
				<p class="rsmw-lack">
					<?php if ( ! empty( $data['host_installed'] ) ) : ?>
						<?php esc_html_e( 'L’extension est installée mais inactive. Cet écran reste vide tant qu’elle n’est pas activée.', 'real-stock-manager-for-woocommerce' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Cet écran s’appuie sur « Back In Stock Notifier for WooCommerce », qui n’est pas installé. Sans lui, aucune demande de retour en stock n’est disponible.', 'real-stock-manager-for-woocommerce' ); ?>
					<?php endif; ?>
				</p>
			</div>
			<?php if ( empty( $data['host_installed'] ) ) : ?>
				<div class="rsmw-card__footer">
					<a href="<?php echo esc_url( \RSMW\BackInStock\Host::install_url() ); ?>" class="button button-primary">
						<?php esc_html_e( 'Installer l’extension', 'real-stock-manager-for-woocommerce' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="rsmw-card__footer">
					<a href="<?php echo esc_url( \RSMW\BackInStock\Host::plugins_url() ); ?>" class="button button-primary">
						<?php esc_html_e( 'Activer l’extension', 'real-stock-manager-for-woocommerce' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<?php
		$rsmw_bis_rows        = (array) $data['rows'];
		$rsmw_bis_totals      = (array) $data['totals'];
		$rsmw_bis_statuses    = (array) $data['statuses'];
		$rsmw_bis_status_list = implode(
			', ',
			array_map( array( '\RSMW\BackInStock\Host', 'status_label' ), $rsmw_bis_statuses )
		);
		?>

		<?php if ( empty( $data['preparation_active'] ) ) : ?>
			<div class="notice notice-error inline">
				<p>
					<strong><?php esc_html_e( 'Module « Préparation des commandes & stock physique » inactif.', 'real-stock-manager-for-woocommerce' ); ?></strong>
					<?php esc_html_e( 'Le stock libre et le réassort commandé ne sont plus tenus à jour : les chiffres ci-dessous ne reflètent plus la réalité.', 'real-stock-manager-for-woocommerce' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $data['auto_delete'] ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %d: nombre de jours. */
						esc_html__( 'Suppression automatique des inscrits activée côté extension hôte, après %d jour(s) : les demandes « Alerte envoyée » anciennes disparaissent, ce qui fait fondre les compteurs ci-dessous.', 'real-stock-manager-for-woocommerce' ),
						(int) $data['auto_delete_days']
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="rsmw-kpis">
			<div class="rsmw-kpi">
				<div class="rsmw-kpi__label"><?php esc_html_e( 'Références demandées', 'real-stock-manager-for-woocommerce' ); ?></div>
				<div class="rsmw-kpi__value" id="k-bis-refs"><?php echo esc_html( (string) count( $rsmw_bis_rows ) ); ?></div>
			</div>
			<div class="rsmw-kpi">
				<div class="rsmw-kpi__label"><?php esc_html_e( 'Inscrits', 'real-stock-manager-for-woocommerce' ); ?></div>
				<div class="rsmw-kpi__value" id="k-bis-inscrits"><?php echo esc_html( (string) (int) $rsmw_bis_totals['inscrits'] ); ?></div>
			</div>
			<div class="rsmw-kpi">
				<div class="rsmw-kpi__label"><?php esc_html_e( 'Demandé (unités)', 'real-stock-manager-for-woocommerce' ); ?></div>
				<div class="rsmw-kpi__value" id="k-bis-demande"><?php echo esc_html( (string) (int) $rsmw_bis_totals['demande'] ); ?></div>
			</div>
			<div class="rsmw-kpi">
				<div class="rsmw-kpi__label"><?php esc_html_e( 'Satisfaites', 'real-stock-manager-for-woocommerce' ); ?></div>
				<div class="rsmw-kpi__value" id="k-bis-satisfait"><?php echo esc_html( (string) (int) $rsmw_bis_totals['satisfait'] ); ?></div>
			</div>
			<div class="rsmw-kpi rsmw-kpi--alert">
				<div class="rsmw-kpi__label"><?php esc_html_e( 'Manquantes', 'real-stock-manager-for-woocommerce' ); ?></div>
				<div class="rsmw-kpi__value" id="k-bis-manque"><?php echo esc_html( (string) (int) $rsmw_bis_totals['manque'] ); ?></div>
				<div class="rsmw-kpi__sub" id="k-bis-refsmanque-wrap">
					<span id="k-bis-refsmanque"><?php echo esc_html( (string) (int) $rsmw_bis_totals['refs_manque'] ); ?></span>
					<?php esc_html_e( 'référence(s) concernée(s)', 'real-stock-manager-for-woocommerce' ); ?>
				</div>
			</div>
			<div class="rsmw-kpi">
				<div class="rsmw-kpi__label"><?php esc_html_e( 'Taux honoré', 'real-stock-manager-for-woocommerce' ); ?>
					<span class="dashicons dashicons-info-outline" title="<?php esc_attr_e( 'Taux pondéré : unités satisfaites sur unités demandées, toutes références confondues — pas la moyenne des taux de ligne.', 'real-stock-manager-for-woocommerce' ); ?>"></span>
				</div>
				<div class="rsmw-kpi__value">
					<div class="rsmw-gauge rsmw-gauge--kpi" id="k-bis-taux">
						<span class="rsmw-gauge__track" aria-hidden="true"><span class="rsmw-gauge__fill" style="width:<?php echo esc_attr( number_format( (float) $rsmw_bis_totals['taux'], 1, '.', '' ) ); ?>%"></span></span>
						<span class="rsmw-gauge__label"><?php echo esc_html( number_format_i18n( (float) $rsmw_bis_totals['taux'], 1 ) ); ?> %</span>
					</div>
				</div>
			</div>
		</div>

		<div class="rsmw-card">
			<div class="rsmw-card__body">
				<div class="rsmw-freshness">
					<span class="rsmw-freshness__dot"></span>
					<?php
					printf(
						/* translators: %s: liste des statuts comptés. */
						esc_html__( 'Calculé à %1$s, sur les statuts d’inscription %2$s.', 'real-stock-manager-for-woocommerce' ),
						'<strong>' . esc_html( wp_date( 'H:i:s' ) ) . '</strong>',
						'<span class="rsmw-slug">' . esc_html( $rsmw_bis_status_list ) . '</span>'
					);
					?>
					<a class="button button-small" href="<?php echo esc_url( $data['refresh_url'] ); ?>">
						<?php esc_html_e( 'Actualiser', 'real-stock-manager-for-woocommerce' ); ?>
					</a>
				</div>
				<?php if ( ! empty( $data['variable_any_variation'] ) ) : ?>
					<p class="rsmw-note">
						<?php esc_html_e( 'Une inscription posée sur un produit à déclinaisons dans son ensemble apparaît sur une ligne « toutes déclinaisons » : elle n’est servie que par ce que les variations laissent de côté après leurs propres demandes.', 'real-stock-manager-for-woocommerce' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="rsmw-card">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title"><?php esc_html_e( 'Demandes de retour en stock', 'real-stock-manager-for-woocommerce' ); ?></h2>

				<div class="rsmw-toolbar">
					<label class="screen-reader-text" for="rsmw-bis-search"><?php esc_html_e( 'Rechercher une référence', 'real-stock-manager-for-woocommerce' ); ?></label>
					<input type="search" id="rsmw-bis-search" placeholder="<?php esc_attr_e( 'Rechercher (nom, taille, SKU…)', 'real-stock-manager-for-woocommerce' ); ?>" autocomplete="off">
					<label><input type="checkbox" id="rsmw-bis-only-missing" checked> <?php esc_html_e( 'Non couvertes uniquement', 'real-stock-manager-for-woocommerce' ); ?></label>
					<label><input type="checkbox" id="rsmw-bis-only-partial"> <?php esc_html_e( 'Partiellement couvertes', 'real-stock-manager-for-woocommerce' ); ?></label>
					<button type="button" class="button" id="rsmw-bis-copy-text"
						data-done="<?php esc_attr_e( 'Copié !', 'real-stock-manager-for-woocommerce' ); ?>">
						<?php esc_html_e( 'Copier les manques (e-mail)', 'real-stock-manager-for-woocommerce' ); ?>
					</button>
					<button type="button" class="button" id="rsmw-bis-copy-cells"
						data-done="<?php esc_attr_e( 'Copié !', 'real-stock-manager-for-woocommerce' ); ?>">
						<?php esc_html_e( 'Copier les manques (tableur)', 'real-stock-manager-for-woocommerce' ); ?>
					</button>
					<a class="button" id="rsmw-bis-export" href="#" data-filename="<?php echo esc_attr( $data['export_filename'] ); ?>">
						<?php esc_html_e( 'Exporter en CSV', 'real-stock-manager-for-woocommerce' ); ?>
					</a>
				</div>
			</div>

			<?php if ( empty( $rsmw_bis_rows ) ) : ?>
				<div class="rsmw-card__body">
					<div class="rsmw-empty">
						<?php
						printf(
							/* translators: %s: liste des statuts comptés. */
							esc_html__( 'Aucune inscription à une alerte de retour en stock dans les statuts %s.', 'real-stock-manager-for-woocommerce' ),
							'<span class="rsmw-slug">' . esc_html( $rsmw_bis_status_list ) . '</span>'
						);
						?>
						<br>
						<a href="<?php echo esc_url( $data['settings_url'] ); ?>"><?php esc_html_e( 'Modifier les statuts comptés.', 'real-stock-manager-for-woocommerce' ); ?></a>
					</div>
				</div>
			<?php else : ?>
				<div class="rsmw-card__body rsmw-card__body--flush">
					<table class="rsmw-table" id="rsmw-bis-table">
						<thead>
							<tr>
								<th data-key="name"><?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th data-key="fournisseur"><?php esc_html_e( 'Fournisseur', 'real-stock-manager-for-woocommerce' ); ?></th>
								<th class="rsmw-num" data-key="inscrits"
									title="<?php esc_attr_e( 'Nombre d’inscriptions actives', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Inscrits', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num" data-key="demande"
									title="<?php esc_attr_e( 'Unités demandées (quantité déclarée, 1 par défaut)', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Demandé', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num rsmw-col-secondary" data-key="libre"
									title="<?php esc_attr_e( 'Stock physique déjà libre, au-delà des commandes clients en attente', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Stock libre', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num rsmw-col-secondary" data-key="avenir"
									title="<?php esc_attr_e( 'Commandé au fournisseur, non attribué à une commande client', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'À venir', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num" data-key="disponible"
									title="<?php esc_attr_e( 'Stock libre déjà là et à venir, au total', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Disponible', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num" data-key="satisfait"
									title="<?php esc_attr_e( 'Unités qui pourront être satisfaites', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Satisfait', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num" data-key="manque"
									title="<?php esc_attr_e( 'Unités qui manqueraient', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Manque', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
								<th class="rsmw-num" data-key="taux"
									title="<?php esc_attr_e( 'Part de la demande satisfaite', 'real-stock-manager-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Taux', 'real-stock-manager-for-woocommerce' ); ?>
								</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rsmw_bis_rows as $rsmw_bis_row ) : ?>
							<?php
							$rsmw_bis_search = strtolower(
								remove_accents(
									$rsmw_bis_row['name'] . ' ' . $rsmw_bis_row['variant'] . ' ' . $rsmw_bis_row['sku'] . ' ' . $rsmw_bis_row['fournisseur']
								)
							);
							$rsmw_bis_taux_class = '';

							if ( $rsmw_bis_row['taux'] < 40 ) {
								$rsmw_bis_taux_class = 'rsmw-gauge__fill--low';
							} elseif ( $rsmw_bis_row['taux'] < 80 ) {
								$rsmw_bis_taux_class = 'rsmw-gauge__fill--mid';
							}
							?>
							<tr data-id="<?php echo esc_attr( (string) $rsmw_bis_row['id'] ); ?>"
								data-fournisseur="<?php echo esc_attr( $rsmw_bis_row['fournisseur'] ); ?>"
								data-sku="<?php echo esc_attr( $rsmw_bis_row['sku'] ); ?>"
								data-search="<?php echo esc_attr( $rsmw_bis_search ); ?>"
								data-name="<?php echo esc_attr( trim( $rsmw_bis_row['name'] . ' ' . $rsmw_bis_row['variant'] ) ); ?>"
								data-inscrits="<?php echo esc_attr( (string) $rsmw_bis_row['inscrits'] ); ?>"
								data-demande="<?php echo esc_attr( (string) $rsmw_bis_row['demande'] ); ?>"
								data-libre="<?php echo esc_attr( (string) $rsmw_bis_row['libre'] ); ?>"
								data-avenir="<?php echo esc_attr( (string) $rsmw_bis_row['a_venir'] ); ?>"
								data-disponible="<?php echo esc_attr( (string) $rsmw_bis_row['disponible'] ); ?>"
								data-satisfait="<?php echo esc_attr( (string) $rsmw_bis_row['satisfait'] ); ?>"
								data-manque="<?php echo esc_attr( (string) $rsmw_bis_row['manque'] ); ?>"
								data-taux="<?php echo esc_attr( number_format( (float) $rsmw_bis_row['taux'], 1, '.', '' ) ); ?>">
								<td>
									<?php if ( '' !== $rsmw_bis_row['edit'] ) : ?>
										<strong><a href="<?php echo esc_url( $rsmw_bis_row['edit'] ); ?>"><?php echo esc_html( $rsmw_bis_row['name'] ); ?></a></strong>
									<?php else : ?>
										<strong><?php echo esc_html( $rsmw_bis_row['name'] ); ?></strong>
									<?php endif; ?>
									<?php if ( ! empty( $rsmw_bis_row['parent_level'] ) ) : ?>
										<span class="rsmw-slug"><?php esc_html_e( 'toutes déclinaisons', 'real-stock-manager-for-woocommerce' ); ?></span>
									<?php elseif ( '' !== $rsmw_bis_row['variant'] ) : ?>
										<span class="rsmw-variant"> — <?php echo esc_html( $rsmw_bis_row['variant'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== $rsmw_bis_row['sku'] ) : ?>
										<br><span class="rsmw-sku"><?php echo esc_html( $rsmw_bis_row['sku'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="rsmw-supplier-cell" title="<?php echo esc_attr( $rsmw_bis_row['fournisseur'] ); ?>">
									<?php if ( '' !== $rsmw_bis_row['fournisseur'] ) : ?>
										<?php echo esc_html( $rsmw_bis_row['fournisseur'] ); ?>
									<?php else : ?>
										<span class="rsmw-lack">—</span>
									<?php endif; ?>
								</td>
								<td class="rsmw-num"><?php echo esc_html( (string) $rsmw_bis_row['inscrits'] ); ?></td>
								<td class="rsmw-num"><?php echo esc_html( (string) $rsmw_bis_row['demande'] ); ?></td>
								<td class="rsmw-num rsmw-col-secondary">
									<?php echo $rsmw_bis_row['libre'] ? esc_html( (string) $rsmw_bis_row['libre'] ) : '<span class="rsmw-zero">·</span>'; ?>
								</td>
								<td class="rsmw-num rsmw-col-secondary <?php echo $rsmw_bis_row['a_venir'] > 0 ? 'rsmw-ordered' : ''; ?>">
									<?php echo $rsmw_bis_row['a_venir'] ? esc_html( (string) $rsmw_bis_row['a_venir'] ) : '<span class="rsmw-zero">·</span>'; ?>
								</td>
								<td class="rsmw-num">
									<?php echo $rsmw_bis_row['disponible'] ? esc_html( (string) $rsmw_bis_row['disponible'] ) : '<span class="rsmw-zero">·</span>'; ?>
								</td>
								<td class="rsmw-num">
									<?php echo $rsmw_bis_row['satisfait'] ? esc_html( (string) $rsmw_bis_row['satisfait'] ) : '<span class="rsmw-zero">·</span>'; ?>
								</td>
								<td class="rsmw-num <?php echo $rsmw_bis_row['manque'] > 0 ? 'rsmw-lack' : 'rsmw-full'; ?>">
									<?php echo $rsmw_bis_row['manque'] > 0 ? esc_html( (string) $rsmw_bis_row['manque'] ) : '✓'; ?>
								</td>
								<td class="rsmw-num">
									<div class="rsmw-gauge" title="<?php echo esc_attr( number_format_i18n( (float) $rsmw_bis_row['taux'], 1 ) . ' %' ); ?>">
										<span class="rsmw-gauge__track" aria-hidden="true"><span class="rsmw-gauge__fill <?php echo esc_attr( $rsmw_bis_taux_class ); ?>" style="width:<?php echo esc_attr( number_format( (float) $rsmw_bis_row['taux'], 1, '.', '' ) ); ?>%"></span></span>
										<span class="rsmw-gauge__label"><?php echo esc_html( number_format_i18n( (float) $rsmw_bis_row['taux'], 0 ) ); ?> %</span>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<div class="rsmw-empty" id="rsmw-bis-noresult" style="display:none">
						<?php esc_html_e( 'Aucune référence ne correspond à ce filtre.', 'real-stock-manager-for-woocommerce' ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

	<?php endif; ?>
</div>
