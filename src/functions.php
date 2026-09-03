<?php
/**
 * Helpers globaux du plugin.
 *
 * @package RealStockManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rsmw' ) ) {
	/**
	 * Accesseur global à l'instance du plugin.
	 *
	 * @return \RSMW\Plugin
	 */
	function rsmw(): \RSMW\Plugin {
		return \RSMW\Plugin::instance();
	}
}

if ( ! function_exists( 'rsmw_log' ) ) {
	/**
	 * Raccourci de journalisation.
	 *
	 * @param string $message Message.
	 * @param string $level   Niveau PSR-3.
	 * @param array  $context Contexte additionnel.
	 */
	function rsmw_log( string $message, string $level = 'info', array $context = array() ): void {
		\RSMW\Support\Logger::log( $message, $level, $context );
	}
}
