<?php
/**
 * Journal des mouvements, partagé par les deux onglets de la page Gestion du stock.
 *
 * @var array $data Attend la clé `journal`.
 *
 * @package RealStockManager
 */

defined( 'ABSPATH' ) || exit;

$rsmw_journal = (array) $data['journal'];
?>
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
					<button type="button" class="rsmw-filters__button" data-rsmw-filter="supply" aria-pressed="false">
						<?php esc_html_e( 'Fournisseur', 'real-stock-manager-for-woocommerce' ); ?>
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
						<th class="rsmw-num"><?php esc_html_e( 'Stock libre', 'real-stock-manager-for-woocommerce' ); ?></th>
						<th class="rsmw-num"><?php esc_html_e( 'En commande', 'real-stock-manager-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Motif', 'real-stock-manager-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Par', 'real-stock-manager-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rsmw_journal as $rsmw_entry ) : ?>
					<?php
					// Les entrées antérieures n'ont ni type, ni motif, ni compteur commandé.
					$rsmw_type      = isset( $rsmw_entry['type'] ) ? $rsmw_entry['type'] : 'in';
					$rsmw_supply    = in_array( $rsmw_type, array( 'order', 'unorder' ), true );
					$rsmw_neg       = in_array( $rsmw_type, array( 'out', 'unorder' ), true );
					$rsmw_defective = isset( $rsmw_entry['defective'] ) ? (int) $rsmw_entry['defective'] : 0;
					$rsmw_orders    = isset( $rsmw_entry['orders'] )
						? (int) $rsmw_entry['orders']
						: (int) ( isset( $rsmw_entry['affecte'] ) ? $rsmw_entry['affecte'] : 0 );
					$rsmw_motif     = isset( $rsmw_entry['motif'] ) ? $rsmw_entry['motif'] : '';
					$rsmw_user      = isset( $rsmw_entry['user'] ) ? $rsmw_entry['user'] : '';

					if ( $rsmw_supply ) {
						$rsmw_move_class = 'rsmw-move-order';
					} elseif ( $rsmw_neg ) {
						$rsmw_move_class = 'rsmw-move-out';
					} else {
						$rsmw_move_class = 'rsmw-move-in';
					}
					?>
					<tr data-rsmw-type="<?php echo esc_attr( $rsmw_type ); ?>"
						data-rsmw-search="<?php echo esc_attr( strtolower( remove_accents( $rsmw_entry['label'] . ' ' . $rsmw_motif . ' ' . $rsmw_user ) ) ); ?>">
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $rsmw_entry['time'] ) ); ?></td>
						<td><?php echo esc_html( $rsmw_entry['label'] ); ?></td>
						<td class="rsmw-num <?php echo esc_attr( $rsmw_move_class ); ?>">
							<?php echo esc_html( ( $rsmw_neg ? '−' : '+' ) . (int) $rsmw_entry['qty'] ); ?>
							<?php if ( $rsmw_defective > 0 ) : ?>
								<span class="rsmw-lack"><?php echo esc_html( '−' . $rsmw_defective ); ?></span>
							<?php endif; ?>
						</td>
						<td class="rsmw-num">
							<?php
							echo $rsmw_orders
								? esc_html( ( $rsmw_neg ? '−' : '+' ) . $rsmw_orders )
								: '<span class="rsmw-zero">·</span>';
							?>
						</td>
						<td class="rsmw-num"><?php echo esc_html( (string) (int) $rsmw_entry['libre'] ); ?></td>
						<td class="rsmw-num rsmw-ordered">
							<?php
							echo isset( $rsmw_entry['commande'] )
								? esc_html( (string) (int) $rsmw_entry['commande'] )
								: '<span class="rsmw-zero">·</span>';
							?>
						</td>
						<td>
							<?php
							if ( $rsmw_defective > 0 && '' === $rsmw_motif ) {
								esc_html_e( 'défectueux', 'real-stock-manager-for-woocommerce' );
							} else {
								echo esc_html( $rsmw_motif );
							}
							?>
						</td>
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
