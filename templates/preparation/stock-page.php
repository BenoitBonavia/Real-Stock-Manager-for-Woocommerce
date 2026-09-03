<?php
/**
 * Gabarit de la page « Gestion stock ».
 *
 * @var array $data Données fournies par RSMW\Preparation\Admin\StockPage.
 *
 * @package RealStockManager
 */

defined( 'ABSPATH' ) || exit;

$rsmw_incoming = $data['incoming'];
$rsmw_outgoing = $data['outgoing'];
$rsmw_errors   = (array) $data['errors'];
$rsmw_journal  = (array) $data['journal'];
$rsmw_reasons  = (array) $data['reasons'];
$rsmw_nonce    = (string) $data['search_nonce'];
?>
<div class="wrap mh-wrap">
	<h1><?php esc_html_e( 'Gestion stock', 'real-stock-manager-for-woocommerce' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Faites entrer ce que vous recevez, sortez ce que vous écartez. Une entrée est affectée automatiquement aux commandes les plus anciennes qui l’attendent ; une sortie puise d’abord dans le stock libre, puis reprend aux commandes les plus récentes.', 'real-stock-manager-for-woocommerce' ); ?>
	</p>

	<?php foreach ( $rsmw_errors as $rsmw_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $rsmw_error ); ?></p></div>
	<?php endforeach; ?>

	<?php if ( $rsmw_incoming ) : ?>
		<div class="mh-report">
			<h2><?php esc_html_e( 'Entrée enregistrée', 'real-stock-manager-for-woocommerce' ); ?></h2>
			<p>
				<span class="big"><?php echo esc_html( (string) (int) $rsmw_incoming['affecte'] ); ?></span>
				<?php esc_html_e( 'affecté(s) aux commandes', 'real-stock-manager-for-woocommerce' ); ?> ·
				<span class="big"><?php echo esc_html( (string) (int) $rsmw_incoming['libre'] ); ?></span>
				<?php esc_html_e( 'en stock libre.', 'real-stock-manager-for-woocommerce' ); ?>
			</p>

			<?php if ( ! empty( $rsmw_incoming['lignes'] ) ) : ?>
				<ul>
					<?php foreach ( $rsmw_incoming['lignes'] as $rsmw_line ) : ?>
						<li>
							<a href="<?php echo esc_url( $rsmw_line['url'] ); ?>">#<?php echo esc_html( $rsmw_line['num'] ); ?></a>
							<?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'du %s', 'real-stock-manager-for-woocommerce' ), $rsmw_line['date'] ) ); ?>
							<?php if ( '' !== $rsmw_line['client'] ) : ?>
								(<?php echo esc_html( $rsmw_line['client'] ); ?>)
							<?php endif; ?>
							— <strong><?php echo esc_html( (string) (int) $rsmw_line['qty'] ); ?></strong>
							<?php esc_html_e( 'pointé(s)', 'real-stock-manager-for-woocommerce' ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'Aucune commande n’attendait cette référence. Tout est parti en stock libre.', 'real-stock-manager-for-woocommerce' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $rsmw_incoming['basculees'] ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Passées en « À empaqueter » :', 'real-stock-manager-for-woocommerce' ); ?></strong>
					<?php echo esc_html( '#' . implode( ', #', $rsmw_incoming['basculees'] ) ); ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $rsmw_outgoing ) : ?>
		<div class="mh-report out">
			<h2><?php esc_html_e( 'Retrait enregistré', 'real-stock-manager-for-woocommerce' ); ?></h2>
			<p>
				<span class="big"><?php echo esc_html( (string) (int) $rsmw_outgoing['du_libre'] ); ?></span>
				<?php esc_html_e( 'pris sur le stock libre', 'real-stock-manager-for-woocommerce' ); ?> ·
				<span class="big"><?php echo esc_html( (string) (int) $rsmw_outgoing['repris'] ); ?></span>
				<?php esc_html_e( 'repris à des commandes', 'real-stock-manager-for-woocommerce' ); ?> ·
				<?php esc_html_e( 'reste', 'real-stock-manager-for-woocommerce' ); ?>
				<span class="big"><?php echo esc_html( (string) (int) $rsmw_outgoing['libre'] ); ?></span>
				<?php esc_html_e( 'en stock libre.', 'real-stock-manager-for-woocommerce' ); ?>
			</p>

			<?php if ( ! empty( $rsmw_outgoing['lignes'] ) ) : ?>
				<ul>
					<?php foreach ( $rsmw_outgoing['lignes'] as $rsmw_line ) : ?>
						<li>
							<a href="<?php echo esc_url( $rsmw_line['url'] ); ?>">#<?php echo esc_html( $rsmw_line['num'] ); ?></a>
							<?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'du %s', 'real-stock-manager-for-woocommerce' ), $rsmw_line['date'] ) ); ?>
							<?php if ( '' !== $rsmw_line['client'] ) : ?>
								(<?php echo esc_html( $rsmw_line['client'] ); ?>)
							<?php endif; ?>
							— <strong><?php echo esc_html( (string) (int) $rsmw_line['qty'] ); ?></strong>
							<?php esc_html_e( 'dépointé(s)', 'real-stock-manager-for-woocommerce' ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( 0 === (int) $rsmw_outgoing['repris'] ) : ?>
				<p><?php esc_html_e( 'Aucune commande n’a été touchée.', 'real-stock-manager-for-woocommerce' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $rsmw_outgoing['rendues'] ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Sorties de « À empaqueter » :', 'real-stock-manager-for-woocommerce' ); ?></strong>
					<?php echo esc_html( '#' . implode( ', #', $rsmw_outgoing['rendues'] ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $rsmw_outgoing['manquant'] > 0 ) : ?>
				<p class="mh-lack">
					<?php
					printf(
						esc_html(
							/* translators: %d: nombre d'articles introuvables. */
							__( '%d article(s) n’ont pas pu être retirés : ni en stock libre, ni pointés sur une commande.', 'real-stock-manager-for-woocommerce' )
						),
						(int) $rsmw_outgoing['manquant']
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="mh-cards">

		<form method="post" class="mh-form in">
			<?php wp_nonce_field( 'mh_prep_receive' ); ?>
			<h2><?php esc_html_e( 'Entrée en stock', 'real-stock-manager-for-woocommerce' ); ?></h2>
			<p class="lede"><?php esc_html_e( 'Réception d’un colis fournisseur, ou tout ajout à votre stock physique.', 'real-stock-manager-for-woocommerce' ); ?></p>

			<div class="row">
				<label for="mh_prep_product"><?php esc_html_e( 'Produit reçu', 'real-stock-manager-for-woocommerce' ); ?></label>
				<select class="wc-product-search" id="mh_prep_product" name="mh_prep_product"
					style="width:100%"
					data-placeholder="<?php esc_attr_e( 'Tapez un nom de produit ou une taille…', 'real-stock-manager-for-woocommerce' ); ?>"
					data-action="woocommerce_json_search_products_and_variations"
					data-security="<?php echo esc_attr( $rsmw_nonce ); ?>"
					data-allow_clear="true"></select>
				<div class="hint"><?php esc_html_e( 'Pour un produit à variations, choisissez bien la variation concernée.', 'real-stock-manager-for-woocommerce' ); ?></div>
			</div>

			<div class="row">
				<label for="mh_prep_sku"><?php esc_html_e( 'Ou saisissez un SKU / un ID', 'real-stock-manager-for-woocommerce' ); ?></label>
				<input type="text" id="mh_prep_sku" name="mh_prep_sku" class="regular-text">
			</div>

			<div class="row">
				<label for="mh_prep_qty"><?php esc_html_e( 'Quantité reçue', 'real-stock-manager-for-woocommerce' ); ?></label>
				<input type="number" id="mh_prep_qty" name="mh_prep_qty" min="1" step="1" value="1" style="width:110px">
			</div>

			<button type="submit" name="mh_prep_receive" value="1" class="button button-primary button-large">
				<?php esc_html_e( 'Enregistrer l’entrée', 'real-stock-manager-for-woocommerce' ); ?>
			</button>
		</form>

		<form method="post" class="mh-form out">
			<?php wp_nonce_field( 'mh_prep_withdraw' ); ?>
			<h2><?php esc_html_e( 'Retrait de stock', 'real-stock-manager-for-woocommerce' ); ?></h2>
			<p class="lede">
				<?php esc_html_e( 'Article défectueux, cassé, perdu ou renvoyé. Le stock libre est puisé en premier ; si besoin, l’article est repris à la commande la plus récente qui le détient.', 'real-stock-manager-for-woocommerce' ); ?>
			</p>

			<div class="row">
				<label for="mh_prep_out_product"><?php esc_html_e( 'Produit à retirer', 'real-stock-manager-for-woocommerce' ); ?></label>
				<select class="wc-product-search" id="mh_prep_out_product" name="mh_prep_out_product"
					style="width:100%"
					data-placeholder="<?php esc_attr_e( 'Tapez un nom de produit ou une taille…', 'real-stock-manager-for-woocommerce' ); ?>"
					data-action="woocommerce_json_search_products_and_variations"
					data-security="<?php echo esc_attr( $rsmw_nonce ); ?>"
					data-allow_clear="true"></select>
			</div>

			<div class="row">
				<label for="mh_prep_out_sku"><?php esc_html_e( 'Ou saisissez un SKU / un ID', 'real-stock-manager-for-woocommerce' ); ?></label>
				<input type="text" id="mh_prep_out_sku" name="mh_prep_out_sku" class="regular-text">
			</div>

			<div class="row">
				<label for="mh_prep_out_qty"><?php esc_html_e( 'Quantité à retirer', 'real-stock-manager-for-woocommerce' ); ?></label>
				<input type="number" id="mh_prep_out_qty" name="mh_prep_out_qty" min="1" step="1" value="1" style="width:110px">
			</div>

			<div class="row">
				<label for="mh_prep_out_motif"><?php esc_html_e( 'Motif', 'real-stock-manager-for-woocommerce' ); ?></label>
				<select id="mh_prep_out_motif" name="mh_prep_out_motif">
					<?php foreach ( $rsmw_reasons as $rsmw_value => $rsmw_label ) : ?>
						<option value="<?php echo esc_attr( $rsmw_value ); ?>"><?php echo esc_html( $rsmw_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="hint"><?php esc_html_e( 'Reporté dans la note des commandes touchées.', 'real-stock-manager-for-woocommerce' ); ?></div>
			</div>

			<button type="submit" name="mh_prep_withdraw" value="1" class="button button-large">
				<?php esc_html_e( 'Enregistrer le retrait', 'real-stock-manager-for-woocommerce' ); ?>
			</button>
		</form>

	</div>

	<p>
		<a class="button" href="<?php echo esc_url( $data['needs_page_url'] ); ?>">
			<?php esc_html_e( 'Voir les besoins & stock', 'real-stock-manager-for-woocommerce' ); ?>
		</a>
	</p>

	<?php if ( ! empty( $rsmw_journal ) ) : ?>
		<h2><?php esc_html_e( 'Derniers mouvements', 'real-stock-manager-for-woocommerce' ); ?></h2>
		<table class="mh-table">
			<thead>
				<tr>
					<th class="no-sort"><?php esc_html_e( 'Date', 'real-stock-manager-for-woocommerce' ); ?></th>
					<th class="no-sort"><?php esc_html_e( 'Référence', 'real-stock-manager-for-woocommerce' ); ?></th>
					<th class="no-sort mh-num"><?php esc_html_e( 'Mouvement', 'real-stock-manager-for-woocommerce' ); ?></th>
					<th class="no-sort mh-num"><?php esc_html_e( 'Commandes', 'real-stock-manager-for-woocommerce' ); ?></th>
					<th class="no-sort mh-num"><?php esc_html_e( 'Libre après', 'real-stock-manager-for-woocommerce' ); ?></th>
					<th class="no-sort"><?php esc_html_e( 'Motif', 'real-stock-manager-for-woocommerce' ); ?></th>
					<th class="no-sort"><?php esc_html_e( 'Par', 'real-stock-manager-for-woocommerce' ); ?></th>
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
				?>
				<tr>
					<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $rsmw_entry['time'] ) ); ?></td>
					<td><?php echo esc_html( $rsmw_entry['label'] ); ?></td>
					<td class="mh-num <?php echo $rsmw_out ? 'mh-move-out' : 'mh-move-in'; ?>">
						<?php echo esc_html( ( $rsmw_out ? '−' : '+' ) . (int) $rsmw_entry['qty'] ); ?>
					</td>
					<td class="mh-num">
						<?php
						echo $rsmw_orders
							? esc_html( ( $rsmw_out ? '−' : '+' ) . $rsmw_orders )
							: '<span class="mh-zero">·</span>';
						?>
					</td>
					<td class="mh-num"><?php echo esc_html( (string) (int) $rsmw_entry['libre'] ); ?></td>
					<td><?php echo esc_html( isset( $rsmw_entry['motif'] ) ? $rsmw_entry['motif'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $rsmw_entry['user'] ) ? $rsmw_entry['user'] : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
