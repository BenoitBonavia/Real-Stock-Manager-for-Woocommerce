<?php
/**
 * Gabarit de la métabox « Préparation ».
 *
 * @var array $data Données fournies par RSMW\Preparation\Admin\Metabox.
 *
 * @package RealStockManager
 */

defined( 'ABSPATH' ) || exit;

$rsmw_order_id      = (int) $data['order_id'];
$rsmw_nonce         = (string) $data['nonce'];
$rsmw_done          = (int) $data['done'];
$rsmw_ordered       = (int) $data['ordered'];
$rsmw_total         = (int) $data['total'];
$rsmw_percent       = (int) $data['percent'];
$rsmw_ordered_pct   = (int) $data['ordered_percent'];
$rsmw_lines         = (array) $data['lines'];
?>
<div id="mh-prep-box-inner"
	data-order="<?php echo esc_attr( (string) $rsmw_order_id ); ?>"
	data-nonce="<?php echo esc_attr( $rsmw_nonce ); ?>">

	<div class="mh-head">
		<span class="mh-count">
			<span id="mh-prep-done"><?php echo esc_html( (string) $rsmw_done ); ?></span>
			/ <?php echo esc_html( (string) $rsmw_total ); ?>
			<?php esc_html_e( 'articles prêts', 'real-stock-manager-for-woocommerce' ); ?>
			<span id="mh-prep-ordered" class="mh-ordered-badge" <?php echo $rsmw_ordered > 0 ? '' : 'hidden'; ?>>
				<?php
				printf(
					/* translators: %d: nombre d'articles en commande fournisseur. */
					esc_html__( '+%d en commande', 'real-stock-manager-for-woocommerce' ),
					esc_html( (string) $rsmw_ordered )
				);
				?>
			</span>
		</span>

		<?php
		// Deux segments : le préparé, puis le commandé au fournisseur. Ce qui
		// reste vide est ce qui manque encore.
		?>
		<span class="mh-bar">
			<span id="mh-prep-fill" style="width:<?php echo esc_attr( (string) $rsmw_percent ); ?>%"></span>
			<span id="mh-prep-fill-ordered" class="mh-bar__ordered" style="width:<?php echo esc_attr( (string) $rsmw_ordered_pct ); ?>%"></span>
		</span>

		<button type="button" class="button" data-mh-all="1"><?php esc_html_e( 'Tout est prêt', 'real-stock-manager-for-woocommerce' ); ?></button>
		<button type="button" class="button" data-mh-all="0"><?php esc_html_e( 'Tout remettre à zéro', 'real-stock-manager-for-woocommerce' ); ?></button>
	</div>

	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Article', 'real-stock-manager-for-woocommerce' ); ?></th>
				<th class="mh-c" style="width:70px"><?php esc_html_e( 'Quantité', 'real-stock-manager-for-woocommerce' ); ?></th>
				<th class="mh-c" style="width:130px"><?php esc_html_e( 'Préparé', 'real-stock-manager-for-woocommerce' ); ?></th>
				<th class="mh-c" style="width:90px"><?php esc_html_e( 'En commande', 'real-stock-manager-for-woocommerce' ); ?></th>
				<th class="mh-c" style="width:90px"><?php esc_html_e( 'Stock libre', 'real-stock-manager-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rsmw_lines as $rsmw_line ) : ?>
			<tr data-item="<?php echo esc_attr( (string) $rsmw_line['id'] ); ?>"
				data-max="<?php echo esc_attr( (string) $rsmw_line['quantity'] ); ?>"
				class="<?php echo $rsmw_line['prepared'] >= $rsmw_line['quantity'] ? 'is-done' : ''; ?>">
				<td>
					<strong><?php echo esc_html( $rsmw_line['name'] ); ?></strong>
					<?php if ( '' !== $rsmw_line['variant'] ) : ?>
						<span class="mh-variant"> — <?php echo esc_html( $rsmw_line['variant'] ); ?></span>
					<?php endif; ?>
				</td>
				<td class="mh-c"><?php echo esc_html( (string) $rsmw_line['quantity'] ); ?></td>
				<td class="mh-c">
					<span class="mh-step">
						<button type="button" data-mh-delta="-1" <?php disabled( 0, $rsmw_line['prepared'] ); ?>>−</button>
						<span class="mh-qty"><?php echo esc_html( $rsmw_line['prepared'] . ' / ' . $rsmw_line['quantity'] ); ?></span>
						<button type="button" data-mh-delta="1" <?php disabled( $rsmw_line['prepared'] >= $rsmw_line['quantity'] ); ?>>+</button>
					</span>
				</td>
				<?php // Modificateur plutôt qu'un span interne : le JS remplace le contenu de la cellule. ?>
				<td class="mh-c mh-ordered <?php echo $rsmw_line['ordered'] > 0 ? '' : 'is-empty'; ?>">
					<?php echo esc_html( $rsmw_line['ordered'] > 0 ? (string) $rsmw_line['ordered'] : '·' ); ?>
				</td>
				<td class="mh-c mh-free <?php echo $rsmw_line['free'] < 0 ? 'neg' : ''; ?>">
					<?php echo esc_html( (string) $rsmw_line['free'] ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p class="mh-msg"
		data-saving="<?php esc_attr_e( 'Enregistrement…', 'real-stock-manager-for-woocommerce' ); ?>"
		data-failed="<?php esc_attr_e( 'Échec de l’enregistrement.', 'real-stock-manager-for-woocommerce' ); ?>"
		data-network="<?php esc_attr_e( 'Erreur réseau, rien n’a été enregistré.', 'real-stock-manager-for-woocommerce' ); ?>"
		data-reloading="<?php esc_attr_e( 'Rechargement…', 'real-stock-manager-for-woocommerce' ); ?>"
		data-ordered="<?php esc_attr_e( '+%d en commande', 'real-stock-manager-for-woocommerce' ); ?>"></p>
</div>
