<?php
/**
 * Partiel : valorisation du stock réel (achat / marchande).
 *
 * @var array $data Fournit la clé `valuation`, calculée par RSMW\Preparation\Valuation::compute().
 *
 * @package RealStockManager
 */

use RSMW\Admin\Admin;
use RSMW\Preparation\Cost;

defined( 'ABSPATH' ) || exit;

$rsmw_val = (array) $data['valuation'];
?>
<div class="rsmw-kpis">
	<div class="rsmw-kpi">
		<div class="rsmw-kpi__label"><?php esc_html_e( 'Valeur d’achat du stock', 'real-stock-manager-for-woocommerce' ); ?></div>
		<?php if ( Cost::SOURCE_NONE === Cost::source() ) : ?>
			<div class="rsmw-kpi__value">&mdash;</div>
			<div class="rsmw-kpi__sub">
				<a href="<?php echo esc_url( Admin::get_settings_url() . '&section=preparation' ); ?>">
					<?php esc_html_e( 'Aucune source de coût configurée', 'real-stock-manager-for-woocommerce' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div class="rsmw-kpi__value"><?php echo wp_kses_post( wc_price( $rsmw_val['cost_free'] ) ); ?></div>
			<div class="rsmw-kpi__sub">
				<?php
				printf(
					/* translators: %s: valeur du stock, réassort commandé compris. */
					esc_html__( 'Avec le réassort commandé : %s', 'real-stock-manager-for-woocommerce' ),
					wp_kses_post( wc_price( $rsmw_val['cost_all'] ) )
				);
				?>
			</div>
			<?php if ( $rsmw_val['priced'] < $rsmw_val['refs'] ) : ?>
				<div class="rsmw-kpi__sub">
					<?php
					printf(
						/* translators: %s: nombre de références sans coût d'achat déclaré. */
						esc_html__( 'Coût manquant sur %s référence(s) : leur part n’est pas comptée ci-dessus.', 'real-stock-manager-for-woocommerce' ),
						'<strong>' . esc_html( number_format_i18n( $rsmw_val['refs'] - $rsmw_val['priced'] ) ) . '</strong>'
					);
					?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<div class="rsmw-kpi">
		<div class="rsmw-kpi__label"><?php esc_html_e( 'Valeur marchande du stock', 'real-stock-manager-for-woocommerce' ); ?></div>
		<div class="rsmw-kpi__value"><?php echo wp_kses_post( wc_price( $rsmw_val['market_free'] ) ); ?></div>
		<div class="rsmw-kpi__sub">
			<?php
			printf(
				/* translators: %s: valeur du stock, réassort commandé compris. */
				esc_html__( 'Avec le réassort commandé : %s', 'real-stock-manager-for-woocommerce' ),
				wp_kses_post( wc_price( $rsmw_val['market_all'] ) )
			);
			?>
		</div>
	</div>
</div>
