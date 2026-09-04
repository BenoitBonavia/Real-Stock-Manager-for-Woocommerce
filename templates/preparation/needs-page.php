<?php
/**
 * Gabarit de la page « Besoins & stock ».
 *
 * @var array $data Données fournies par RSMW\Preparation\Admin\NeedsPage.
 *
 * @package RealStockManager
 */

defined( 'ABSPATH' ) || exit;

$rsmw_rows        = (array) $data['rows'];
$rsmw_totals      = (array) $data['totals'];
$rsmw_statuses    = (array) $data['statuses'];
$rsmw_unknown     = (array) $data['unknown_statuses'];
$rsmw_negatives   = (array) $data['negatives'];
$rsmw_repaired    = $data['repaired'];
$rsmw_realloc     = $data['reallocation'];
$rsmw_allocatable = (int) $data['allocatable'];
$rsmw_cache_meta  = (array) $data['cache_meta'];
$rsmw_outside     = (array) $data['outside'];
$rsmw_status_list = implode( ', ', $rsmw_statuses );
?>
<div class="wrap woocommerce rsmw-wrap">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Besoins & stock', 'real-stock-manager-for-woocommerce' ); ?></h1>
	<a href="<?php echo esc_url( $data['stock_page_url'] ); ?>" class="page-title-action">
		<?php esc_html_e( 'Gestion du stock', 'real-stock-manager-for-woocommerce' ); ?>
	</a>
	<hr class="wp-header-end">

	<p class="description">
		<?php esc_html_e( 'Ce qu’il reste à préparer sur les commandes en attente, face à votre stock physique libre. Le stock libre est celui qui n’est encore affecté à aucune commande.', 'real-stock-manager-for-woocommerce' ); ?>
	</p>

	<?php if ( ! empty( $rsmw_unknown ) ) : ?>
		<div class="notice notice-error inline">
			<p>
				<strong><?php esc_html_e( 'Statut inconnu dans les réglages :', 'real-stock-manager-for-woocommerce' ); ?></strong>
				<?php foreach ( $rsmw_unknown as $rsmw_slug ) : ?>
					<span class="rsmw-slug"><?php echo esc_html( $rsmw_slug ); ?></span>
				<?php endforeach; ?>
				<?php esc_html_e( 'Les commandes de ce statut ne seront jamais comptées. Corrigez le slug avec la liste en bas de page.', 'real-stock-manager-for-woocommerce' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $rsmw_repaired ) : ?>
		<div class="notice notice-success inline">
			<p>
				<?php
				printf(
					esc_html(
						/* translators: %d: nombre de références corrigées. */
						__( '%d référence(s) remise(s) à zéro.', 'real-stock-manager-for-woocommerce' )
					),
					(int) $rsmw_repaired
				);
				?>
			</p>
		</div>
	<?php elseif ( ! empty( $rsmw_negatives ) ) : ?>
		<div class="rsmw-card rsmw-card--dry">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title"><?php esc_html_e( 'Stock négatif hérité', 'real-stock-manager-for-woocommerce' ); ?></h2>
			</div>
			<div class="rsmw-card__body">
				<p>
					<?php
					printf(
						esc_html(
							/* translators: %d: nombre de références sous zéro. */
							__( '%d référence(s) sont sous zéro, pointées avant que le pointage ne vaille entrée en stock. Elles n’ont plus de sens aujourd’hui et bloquent le décompte.', 'real-stock-manager-for-woocommerce' )
						),
						count( $rsmw_negatives )
					);
					?>
				</p>
			</div>
			<div class="rsmw-card__footer">
				<form method="post">
					<?php wp_nonce_field( 'mh_prep_repair' ); ?>
					<button type="submit" name="mh_prep_repair" value="1" class="button button-primary">
						<?php esc_html_e( 'Remettre à zéro', 'real-stock-manager-for-woocommerce' ); ?>
					</button>
				</form>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $rsmw_realloc ) : ?>
		<div class="rsmw-card <?php echo $rsmw_realloc['dry'] ? 'rsmw-card--dry' : 'rsmw-card--report'; ?>">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title">
					<?php
					echo $rsmw_realloc['dry']
						? esc_html__( 'Simulation — rien n’a été enregistré', 'real-stock-manager-for-woocommerce' )
						: esc_html__( 'Réaffectation effectuée', 'real-stock-manager-for-woocommerce' );
					?>
				</h2>
			</div>
			<div class="rsmw-card__body">
				<?php if ( 0 === (int) $rsmw_realloc['total'] ) : ?>
					<p><?php esc_html_e( 'Aucune unité de stock libre ne correspond à une commande en attente.', 'real-stock-manager-for-woocommerce' ); ?></p>
				<?php else : ?>
					<p>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) (int) $rsmw_realloc['total'] ); ?></span>
						<?php
						echo $rsmw_realloc['dry']
							? esc_html__( 'article(s) seraient pointés sur', 'real-stock-manager-for-woocommerce' )
							: esc_html__( 'article(s) pointés sur', 'real-stock-manager-for-woocommerce' );
						?>
						<span class="rsmw-report__figure"><?php echo esc_html( (string) count( $rsmw_realloc['commandes'] ) ); ?></span>
						<?php esc_html_e( 'commande(s).', 'real-stock-manager-for-woocommerce' ); ?>
					</p>
					<ul>
						<?php foreach ( $rsmw_realloc['commandes'] as $rsmw_line ) : ?>
							<li>
								<a href="<?php echo esc_url( $rsmw_line['url'] ); ?>">#<?php echo esc_html( $rsmw_line['num'] ); ?></a>
								<?php if ( $rsmw_line['date'] ) : ?>
									<span class="rsmw-variant"><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'du %s', 'real-stock-manager-for-woocommerce' ), $rsmw_line['date'] ) ); ?></span>
								<?php endif; ?>
								— <strong><?php echo esc_html( (string) (int) $rsmw_line['qty'] ); ?></strong>
								<?php esc_html_e( 'article(s)', 'real-stock-manager-for-woocommerce' ); ?>
								<?php if ( ! empty( $rsmw_line['full'] ) ) : ?>
									<span class="rsmw-full">· <?php esc_html_e( 'complète', 'real-stock-manager-for-woocommerce' ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php if ( ! empty( $rsmw_realloc['basculees'] ) ) : ?>
						<p>
							<strong>
								<?php
								echo $rsmw_realloc['dry']
									? esc_html__( 'Passeraient en « À empaqueter » :', 'real-stock-manager-for-woocommerce' )
									: esc_html__( 'Passées en « À empaqueter » :', 'real-stock-manager-for-woocommerce' );
								?>
							</strong>
							<?php echo esc_html( '#' . implode( ', #', $rsmw_realloc['basculees'] ) ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<?php if ( $rsmw_realloc['dry'] && (int) $rsmw_realloc['total'] > 0 ) : ?>
				<div class="rsmw-card__footer">
					<form method="post">
						<?php wp_nonce_field( 'mh_prep_realloc' ); ?>
						<button type="submit" name="mh_prep_realloc" value="appliquer" class="button button-primary">
							<?php esc_html_e( 'Appliquer cette réaffectation', 'real-stock-manager-for-woocommerce' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>
		</div>

	<?php elseif ( $rsmw_allocatable > 0 ) : ?>

		<div class="rsmw-card rsmw-card--report">
			<div class="rsmw-card__header">
				<h2 class="rsmw-card__title"><?php esc_html_e( 'Du stock libre attend d’être affecté', 'real-stock-manager-for-woocommerce' ); ?></h2>
			</div>
			<div class="rsmw-card__body">
				<p>
					<?php
					printf(
						esc_html(
							/* translators: %d: nombre d'articles affectables. */
							__( '%d article(s) en stock chez vous correspondent à des commandes en attente qui ne les ont pas encore reçus. C’est le cas des réceptions saisies avant qu’un statut ne soit pris en compte, ou d’un stock corrigé à la main.', 'real-stock-manager-for-woocommerce' )
						),
						$rsmw_allocatable
					);
					?>
				</p>
			</div>
			<div class="rsmw-card__footer">
				<form method="post" class="rsmw-toolbar">
					<?php wp_nonce_field( 'mh_prep_realloc' ); ?>
					<button type="submit" name="mh_prep_realloc" value="simuler" class="button">
						<?php esc_html_e( 'Simuler d’abord', 'real-stock-manager-for-woocommerce' ); ?>
					</button>
					<button type="submit" name="mh_prep_realloc" value="appliquer" class="button button-primary">
						<?php esc_html_e( 'Réaffecter maintenant', 'real-stock-manager-for-woocommerce' ); ?>
					</button>
				</form>
			</div>
		</div>

	<?php endif; ?>

	<div class="rsmw-kpis">
		<div class="rsmw-kpi">
			<div class="rsmw-kpi__label"><?php esc_html_e( 'Références concernées', 'real-stock-manager-for-woocommerce' ); ?></div>
			<div class="rsmw-kpi__value" id="k-refs"><?php echo esc_html( (string) count( $rsmw_rows ) ); ?></div>
		</div>
		<div class="rsmw-kpi">
			<div class="rsmw-kpi__label"><?php esc_html_e( 'Articles à préparer', 'real-stock-manager-for-woocommerce' ); ?></div>
			<div class="rsmw-kpi__value" id="k-restant"><?php echo esc_html( (string) (int) $rsmw_totals['restant'] ); ?></div>
		</div>
		<div class="rsmw-kpi rsmw-kpi--ordered">
			<div class="rsmw-kpi__label"><?php esc_html_e( 'En commande', 'real-stock-manager-for-woocommerce' ); ?></div>
			<div class="rsmw-kpi__value" id="k-commande"><?php echo esc_html( (string) (int) $rsmw_totals['commande'] ); ?></div>
		</div>
		<div class="rsmw-kpi rsmw-kpi--alert">
			<div class="rsmw-kpi__label"><?php esc_html_e( 'Reste à commander', 'real-stock-manager-for-woocommerce' ); ?></div>
			<div class="rsmw-kpi__value" id="k-manque"><?php echo esc_html( (string) (int) $rsmw_totals['manque'] ); ?></div>
		</div>
		<div class="rsmw-kpi rsmw-kpi--alert">
			<div class="rsmw-kpi__label"><?php esc_html_e( 'Références à commander', 'real-stock-manager-for-woocommerce' ); ?></div>
			<div class="rsmw-kpi__value" id="k-refsmanque"><?php echo esc_html( (string) (int) $rsmw_totals['refs_manque'] ); ?></div>
		</div>
		<div class="rsmw-kpi">
			<div class="rsmw-kpi__label"><?php esc_html_e( 'Valeur du manque', 'real-stock-manager-for-woocommerce' ); ?></div>
			<?php
			// Le recalcul côté client doit suivre le formatage de la boutique :
			// sans ces attributs, le JS figerait un format qui divergerait de
			// wc_price() dès le premier filtre appliqué.
			?>
			<div class="rsmw-kpi__value" id="k-valeur"
				data-currency="<?php echo esc_attr( get_woocommerce_currency() ); ?>"
				data-locale="<?php echo esc_attr( str_replace( '_', '-', get_user_locale() ) ); ?>"
				data-decimals="<?php echo esc_attr( (string) wc_get_price_decimals() ); ?>"><?php echo wp_kses_post( wc_price( $rsmw_totals['valeur'] ) ); ?></div>
		</div>
	</div>

	<div class="rsmw-card">
		<div class="rsmw-card__body">
			<div class="rsmw-freshness">
				<span class="rsmw-freshness__dot"></span>
				<?php
				printf(
					/* translators: 1: heure du calcul, 2: nombre de commandes, 3: liste des statuts. */
					esc_html__( 'Calculé à %1$s sur %2$d commande(s) au statut %3$s.', 'real-stock-manager-for-woocommerce' ),
					'<strong>' . esc_html( wp_date( 'H:i:s' ) ) . '</strong>',
					(int) $rsmw_cache_meta['orders'],
					'<span class="rsmw-slug">' . esc_html( $rsmw_status_list ) . '</span>'
				);
				?>
				<a class="button button-small" href="<?php echo esc_url( $data['refresh_url'] ); ?>">
					<?php esc_html_e( 'Actualiser', 'real-stock-manager-for-woocommerce' ); ?>
				</a>

				<?php if ( $rsmw_outside['count'] > 0 ) : ?>
					<div class="rsmw-outside">
						<?php esc_html_e( 'Hors périmètre :', 'real-stock-manager-for-woocommerce' ); ?>
						<?php foreach ( $rsmw_outside['detail'] as $rsmw_index => $rsmw_detail ) : ?>
							<?php echo $rsmw_index > 0 ? ' &middot; ' : ''; ?>
							<?php echo esc_html( $rsmw_detail['label'] ); ?>
							<span class="rsmw-slug"><?php echo esc_html( $rsmw_detail['slug'] ); ?></span>
							× <strong><?php echo esc_html( (string) (int) $rsmw_detail['count'] ); ?></strong>
						<?php endforeach; ?>
						<br>
						<?php esc_html_e( 'Une commande récente absente du tableau est probablement dans l’un de ces statuts : ajoutez-le aux statuts suivis dans les réglages pour le prendre en compte.', 'real-stock-manager-for-woocommerce' ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="rsmw-card">
		<div class="rsmw-card__header">
			<h2 class="rsmw-card__title"><?php esc_html_e( 'Références à préparer', 'real-stock-manager-for-woocommerce' ); ?></h2>

			<div class="rsmw-toolbar">
				<label class="screen-reader-text" for="mh-search"><?php esc_html_e( 'Rechercher une référence', 'real-stock-manager-for-woocommerce' ); ?></label>
				<input type="search" id="mh-search" placeholder="<?php esc_attr_e( 'Rechercher (nom, taille, SKU…)', 'real-stock-manager-for-woocommerce' ); ?>" autocomplete="off">
				<?php /* Coché d'entrée : la page sert d'abord à savoir quoi commander. Le script applique le filtre au chargement, sans quoi la case serait cochée devant un tableau complet. */ ?>
				<label><input type="checkbox" id="mh-only-lack" checked> <?php esc_html_e( 'Manquants uniquement', 'real-stock-manager-for-woocommerce' ); ?></label>
				<label><input type="checkbox" id="mh-only-stock"> <?php esc_html_e( 'Avec stock libre', 'real-stock-manager-for-woocommerce' ); ?></label>
				<a class="button" id="mh-export" href="#" data-filename="<?php esc_attr_e( 'besoins-stock', 'real-stock-manager-for-woocommerce' ); ?>">
					<?php esc_html_e( 'Exporter en CSV', 'real-stock-manager-for-woocommerce' ); ?>
				</a>
			</div>
		</div>

		<?php if ( empty( $rsmw_rows ) ) : ?>
			<div class="rsmw-card__body">
				<div class="rsmw-empty">
					<?php
					printf(
						/* translators: %s: liste des statuts suivis. */
						esc_html__( 'Aucune commande à préparer dans les statuts %s.', 'real-stock-manager-for-woocommerce' ),
						'<span class="rsmw-slug">' . esc_html( $rsmw_status_list ) . '</span>'
					);
					?>
					<br>
					<?php esc_html_e( 'Vérifiez que ces slugs correspondent bien à vos statuts réels (liste en bas de page).', 'real-stock-manager-for-woocommerce' ); ?>
				</div>
			</div>
		<?php else : ?>
			<div class="rsmw-card__body rsmw-card__body--flush">
				<table class="rsmw-table" id="mh-table">
					<thead>
						<tr>
							<th data-key="name"><?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num" data-key="demande"><?php esc_html_e( 'Demandé', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num" data-key="pointe"><?php esc_html_e( 'Déjà pointé', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num" data-key="restant"><?php esc_html_e( 'Reste à préparer', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num" data-key="libre"><?php esc_html_e( 'Stock libre', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num" data-key="commande"><?php esc_html_e( 'En commande', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num" data-key="manque"><?php esc_html_e( 'Reste à commander', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th class="rsmw-num" data-key="commandes"><?php esc_html_e( 'Commandes', 'real-stock-manager-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Plus ancienne', 'real-stock-manager-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rsmw_rows as $rsmw_row ) : ?>
						<?php
						// remove_accents() AVANT strtolower() : strtolower() ne traite que
						// l'ASCII, un « É » initial y survivrait puis deviendrait un « E »
						// majuscule, introuvable par une recherche en minuscules.
						$rsmw_search = strtolower( remove_accents( $rsmw_row['name'] . ' ' . $rsmw_row['variant'] . ' ' . $rsmw_row['sku'] ) );
						?>
						<tr data-search="<?php echo esc_attr( $rsmw_search ); ?>"
							data-name="<?php echo esc_attr( trim( $rsmw_row['name'] . ' ' . $rsmw_row['variant'] ) ); ?>"
							data-demande="<?php echo esc_attr( (string) $rsmw_row['demande'] ); ?>"
							data-pointe="<?php echo esc_attr( (string) $rsmw_row['pointe'] ); ?>"
							data-restant="<?php echo esc_attr( (string) $rsmw_row['restant'] ); ?>"
							data-libre="<?php echo esc_attr( (string) $rsmw_row['libre'] ); ?>"
							data-commande="<?php echo esc_attr( (string) $rsmw_row['commande'] ); ?>"
							data-manque="<?php echo esc_attr( (string) $rsmw_row['manque'] ); ?>"
							data-commandes="<?php echo esc_attr( (string) $rsmw_row['commandes'] ); ?>"
							data-valeur="<?php echo esc_attr( (string) $rsmw_row['valeur'] ); ?>">
							<td>
								<strong><?php echo esc_html( $rsmw_row['name'] ); ?></strong>
								<?php if ( '' !== $rsmw_row['variant'] ) : ?>
									<span class="rsmw-variant"> — <?php echo esc_html( $rsmw_row['variant'] ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $rsmw_row['sku'] ) : ?>
									<br><span class="rsmw-sku"><?php echo esc_html( $rsmw_row['sku'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="rsmw-num">
								<?php echo $rsmw_row['demande'] ? esc_html( (string) $rsmw_row['demande'] ) : '<span class="rsmw-zero">·</span>'; ?>
							</td>
							<td class="rsmw-num">
								<?php echo $rsmw_row['pointe'] ? esc_html( (string) $rsmw_row['pointe'] ) : '<span class="rsmw-zero">·</span>'; ?>
							</td>
							<td class="rsmw-num">
								<?php echo $rsmw_row['restant'] ? esc_html( (string) $rsmw_row['restant'] ) : '<span class="rsmw-zero">·</span>'; ?>
							</td>
							<td class="rsmw-num <?php echo $rsmw_row['libre'] < 0 ? 'rsmw-lack' : ''; ?>">
								<?php echo 0 === $rsmw_row['libre'] ? '<span class="rsmw-zero">·</span>' : esc_html( (string) $rsmw_row['libre'] ); ?>
							</td>
							<td class="rsmw-num <?php echo $rsmw_row['commande'] > 0 ? 'rsmw-ordered' : ''; ?>">
								<?php echo $rsmw_row['commande'] > 0 ? esc_html( (string) $rsmw_row['commande'] ) : '<span class="rsmw-zero">·</span>'; ?>
							</td>
							<td class="rsmw-num <?php echo $rsmw_row['manque'] > 0 ? 'rsmw-lack' : 'rsmw-full'; ?>">
								<?php echo $rsmw_row['manque'] > 0 ? esc_html( (string) $rsmw_row['manque'] ) : '✓'; ?>
							</td>
							<td class="rsmw-num">
								<?php echo $rsmw_row['commandes'] ? esc_html( (string) $rsmw_row['commandes'] ) : '<span class="rsmw-zero">·</span>'; ?>
							</td>
							<td>
								<?php if ( $rsmw_row['oldest'] ) : ?>
									<a href="<?php echo esc_url( $rsmw_row['oldest']['url'] ); ?>">#<?php echo esc_html( $rsmw_row['oldest']['num'] ); ?></a>
									<span class="rsmw-variant"><?php echo esc_html( $rsmw_row['oldest']['date'] ); ?></span>
								<?php else : ?>
									<span class="rsmw-zero">·</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="rsmw-empty" id="mh-noresult" style="display:none">
					<?php esc_html_e( 'Aucune référence ne correspond à ce filtre.', 'real-stock-manager-for-woocommerce' ); ?>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<div class="rsmw-card">
		<div class="rsmw-card__header">
			<h2 class="rsmw-card__title"><?php esc_html_e( 'Diagnostic', 'real-stock-manager-for-woocommerce' ); ?></h2>
		</div>
		<div class="rsmw-card__body rsmw-note">
			<strong><?php esc_html_e( 'Vos statuts de commande', 'real-stock-manager-for-woocommerce' ); ?></strong>
			— <?php esc_html_e( 'les slugs utilisables dans les réglages :', 'real-stock-manager-for-woocommerce' ); ?><br>
			<?php foreach ( wc_get_order_statuses() as $rsmw_slug => $rsmw_label ) : ?>
				<?php
				$rsmw_clean  = preg_replace( '/^wc-/', '', $rsmw_slug );
				$rsmw_active = in_array( $rsmw_clean, $rsmw_statuses, true );
				?>
				<span class="rsmw-slug <?php echo $rsmw_active ? 'is-active' : ''; ?>"><?php echo esc_html( $rsmw_clean ); ?></span>
				<?php echo esc_html( $rsmw_label ); ?>
				&nbsp;·&nbsp;
			<?php endforeach; ?>

			<br><br>
			<strong><?php esc_html_e( 'Attribution automatique', 'real-stock-manager-for-woocommerce' ); ?></strong> —
			<?php if ( $data['auto_allocate'] ) : ?>
				<span class="rsmw-full"><?php esc_html_e( 'activée', 'real-stock-manager-for-woocommerce' ); ?></span> :
				<?php esc_html_e( 'une commande qui entre dans le périmètre se sert aussitôt dans le stock libre.', 'real-stock-manager-for-woocommerce' ); ?>
			<?php else : ?>
				<span class="rsmw-lack"><?php esc_html_e( 'désactivée', 'real-stock-manager-for-woocommerce' ); ?></span> —
				<?php esc_html_e( 'activez-la dans les réglages du plugin.', 'real-stock-manager-for-woocommerce' ); ?>
			<?php endif; ?>

			<br><br>
			<strong><?php echo esc_html( sprintf( /* translators: %s: libellé du statut. */ __( 'Statut « %s »', 'real-stock-manager-for-woocommerce' ), $data['status_label'] ) ); ?></strong> —
			<?php esc_html_e( 'enregistré dans WordPress :', 'real-stock-manager-for-woocommerce' ); ?>
			<?php echo $data['status_ok'] ? '<span class="rsmw-full">' . esc_html__( 'oui', 'real-stock-manager-for-woocommerce' ) . '</span>' : '<span class="rsmw-lack">' . esc_html__( 'non', 'real-stock-manager-for-woocommerce' ) . '</span>'; ?>
			·
			<?php esc_html_e( 'déclaré à WooCommerce :', 'real-stock-manager-for-woocommerce' ); ?>
			<?php echo $data['status_declared'] ? '<span class="rsmw-full">' . esc_html__( 'oui', 'real-stock-manager-for-woocommerce' ) . '</span>' : '<span class="rsmw-lack">' . esc_html__( 'non', 'real-stock-manager-for-woocommerce' ) . '</span>'; ?>
			·
			<?php esc_html_e( 'commandes dans ce statut :', 'real-stock-manager-for-woocommerce' ); ?>
			<strong><?php echo esc_html( (string) (int) $data['status_count'] ); ?></strong>
		</div>
	</div>
</div>
