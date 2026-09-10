<?php
/**
 * Contrat commun à tous les modules du plugin.
 *
 * @package RealStockManager
 */

namespace RSMW\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Un module encapsule une règle de gestion de stock autonome
 * (l'équivalent structuré d'un snippet).
 */
interface ModuleInterface {

	/**
	 * Identifiant machine unique, en snake_case.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Libellé lisible, affiché dans les réglages.
	 *
	 * @return string
	 */
	public function get_title(): string;

	/**
	 * Indique si le module doit être chargé.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * Le module est-il activé par défaut, avant toute configuration ?
	 *
	 * Sert à afficher la case des réglages dans le bon état initial — voir
	 * `SettingsTab::get_settings_for_modules_section()`.
	 *
	 * @return bool
	 */
	public function is_enabled_by_default(): bool;

	/**
	 * Accroche les hooks du module. Appelé une seule fois, si is_enabled().
	 */
	public function register(): void;
}
