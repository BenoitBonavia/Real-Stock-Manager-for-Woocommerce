<?php
/**
 * Configuration du module de préparation.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Résout les réglages du module selon la précédence :
 *
 *     constante MH_PREP_* définie  →  option rsmw_*  →  valeur par défaut
 *
 * Les constantes sont celles du snippet remplacé. Tant qu'il est actif, elles
 * existent encore : c'est ce qui permet à `capture_legacy_constants()` de
 * recopier la configuration en place avant que le snippet ne disparaisse.
 */
final class Config {

	/** Clé d'option : statuts de commande considérés « à préparer ». */
	public const KEY_STATUSES = 'prep_statuses';

	/** Clé d'option : attribution automatique du stock à l'arrivée d'une commande. */
	public const KEY_AUTO_ALLOCATE = 'prep_auto_allocate';

	/** Clé d'option : durée de vie du cache des besoins, en secondes. */
	public const KEY_CACHE_TTL = 'prep_cache_ttl';

	/** Statuts suivis par défaut, si rien n'est configuré. */
	public const DEFAULT_STATUSES = array( 'processing' );

	/** Durée de cache par défaut, en secondes. */
	public const DEFAULT_CACHE_TTL = 120;

	/**
	 * Statuts de commande considérés « à préparer », slugs nus et dédoublonnés.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		if ( defined( 'MH_PREP_STATUSES' ) ) {
			$raw = constant( 'MH_PREP_STATUSES' );
		} else {
			$raw = Settings::get( self::KEY_STATUSES, self::DEFAULT_STATUSES );
		}

		$statuses = self::normalize_statuses( $raw );

		if ( empty( $statuses ) ) {
			$statuses = self::DEFAULT_STATUSES;
		}

		/**
		 * Filtre les statuts de commande considérés « à préparer ».
		 *
		 * @param string[] $statuses Slugs nus.
		 */
		return (array) apply_filters( 'rsmw_prep_statuses', $statuses );
	}

	/**
	 * L'attribution automatique du stock libre est-elle active ?
	 *
	 * @return bool
	 */
	public static function auto_allocate(): bool {
		if ( defined( 'MH_PREP_AUTO_ALLOCATE' ) ) {
			return (bool) constant( 'MH_PREP_AUTO_ALLOCATE' );
		}

		return Settings::get_bool( self::KEY_AUTO_ALLOCATE, true );
	}

	/**
	 * Durée de vie du cache des besoins, en secondes.
	 *
	 * @return int
	 */
	public static function cache_ttl(): int {
		if ( defined( 'MH_PREP_CACHE' ) ) {
			return max( 0, (int) constant( 'MH_PREP_CACHE' ) );
		}

		return max( 0, (int) Settings::get( self::KEY_CACHE_TTL, self::DEFAULT_CACHE_TTL ) );
	}

	/**
	 * La journalisation des opérations de stock est-elle active ?
	 *
	 * MH_PREP_DEBUG conserve la priorité ; à défaut on retombe sur le réglage
	 * « Journalisation » de l'onglet Général, partagé avec le reste du plugin.
	 *
	 * @return bool
	 */
	public static function logging_enabled(): bool {
		if ( defined( 'MH_PREP_DEBUG' ) ) {
			return (bool) constant( 'MH_PREP_DEBUG' );
		}

		return Settings::get_bool( 'enable_logging' );
	}

	/**
	 * Réglages actuellement imposés par une constante, et donc non modifiables
	 * depuis l'écran de réglages.
	 *
	 * @return array<string, string> Clé d'option => nom de la constante.
	 */
	public static function constant_overrides(): array {
		$map = array(
			self::KEY_STATUSES      => 'MH_PREP_STATUSES',
			self::KEY_AUTO_ALLOCATE => 'MH_PREP_AUTO_ALLOCATE',
			self::KEY_CACHE_TTL     => 'MH_PREP_CACHE',
		);

		return array_filter( $map, 'defined' );
	}

	/**
	 * Recopie la configuration du snippet dans les options du plugin.
	 *
	 * Appelée à chaque amorçage plutôt qu'une seule fois sur `rsmw_upgrade` : le
	 * marqueur de version est écrit dès la première requête suivant la mise à jour,
	 * qui peut très bien être une visite front ou un cron où les constantes du
	 * snippet ne sont pas chargées. Adosser la reprise à ce marqueur la ferait
	 * échouer définitivement et en silence.
	 *
	 * Le coût est nul hors présence du snippet : sans constante, on sort avant
	 * toute lecture d'option.
	 *
	 * N'écrase jamais une option déjà renseignée : un réglage saisi dans
	 * l'interface reste prioritaire sur une reprise.
	 */
	public static function capture_legacy_constants(): void {
		if (
			! defined( 'MH_PREP_STATUSES' )
			&& ! defined( 'MH_PREP_AUTO_ALLOCATE' )
			&& ! defined( 'MH_PREP_CACHE' )
		) {
			return;
		}

		if ( defined( 'MH_PREP_STATUSES' ) && null === Settings::get( self::KEY_STATUSES ) ) {
			Settings::update( self::KEY_STATUSES, self::normalize_statuses( constant( 'MH_PREP_STATUSES' ) ) );
		}

		if ( defined( 'MH_PREP_AUTO_ALLOCATE' ) && null === Settings::get( self::KEY_AUTO_ALLOCATE ) ) {
			Settings::update( self::KEY_AUTO_ALLOCATE, constant( 'MH_PREP_AUTO_ALLOCATE' ) ? 'yes' : 'no' );
		}

		if ( defined( 'MH_PREP_CACHE' ) && null === Settings::get( self::KEY_CACHE_TTL ) ) {
			Settings::update( self::KEY_CACHE_TTL, max( 0, (int) constant( 'MH_PREP_CACHE' ) ) );
		}
	}

	/**
	 * Normalise une liste de statuts : accepte la chaîne du snippet
	 * (« processing,precommande ») comme le tableau du multiselect.
	 *
	 * Le préfixe « wc- » est retiré, les entrées vides écartées, les doublons
	 * supprimés. Le statut cible « À empaqueter » est exclu par sécurité : le
	 * suivre reviendrait à demander à une commande de basculer vers elle-même.
	 *
	 * @param mixed $raw Chaîne séparée par des virgules, ou tableau de slugs.
	 *
	 * @return string[]
	 */
	public static function normalize_statuses( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$statuses = array();

		foreach ( $raw as $status ) {
			if ( ! is_string( $status ) ) {
				continue;
			}

			$status = preg_replace( '/^wc-/', '', trim( $status ) );

			if ( '' === $status || Legacy::STATUS_SLUG === $status ) {
				continue;
			}

			$statuses[] = $status;
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
