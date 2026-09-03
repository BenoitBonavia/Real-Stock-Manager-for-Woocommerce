<?php
/**
 * Base commune aux modules.
 *
 * @package RealStockManager
 */

namespace RSMW\Modules;

use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Implémente le comportement par défaut d'un module : activation pilotée par
 * une option `rsmw_module_{id}_enabled`, surchargeable par filtre.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Identifiant machine unique.
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Libellé lisible.
	 *
	 * @var string
	 */
	protected $title = '';

	/**
	 * Le module est-il actif par défaut ?
	 *
	 * @var bool
	 */
	protected $enabled_by_default = true;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_enabled(): bool {
		$enabled = Settings::get_bool(
			'module_' . $this->get_id() . '_enabled',
			$this->enabled_by_default
		);

		/**
		 * Force l'activation ou la désactivation d'un module.
		 *
		 * @param bool            $enabled État calculé depuis les réglages.
		 * @param ModuleInterface $module  Instance du module.
		 */
		return (bool) apply_filters( 'rsmw_module_is_enabled', $enabled, $this );
	}

	/**
	 * {@inheritDoc}
	 */
	abstract public function register(): void;
}
