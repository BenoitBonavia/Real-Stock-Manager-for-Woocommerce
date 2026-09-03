<?php
/**
 * Bootstrap du plugin.
 *
 * @package RealStockManager
 */

namespace RSMW;

use RSMW\Admin\Admin;
use RSMW\Modules\ModuleInterface;
use RSMW\Preparation\Config;
use RSMW\Preparation\OrderStatus;
use RSMW\Preparation\SnippetGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Conteneur principal : vérifie les prérequis puis enregistre les modules.
 */
final class Plugin {

	/**
	 * Instance unique.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Modules actifs, indexés par identifiant.
	 *
	 * @var ModuleInterface[]
	 */
	private $modules = array();

	/**
	 * Retourne (et amorce) l'instance unique.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Constructeur privé : passer par instance().
	 */
	private function __construct() {}

	/**
	 * Amorce le plugin si l'environnement le permet.
	 */
	private function boot(): void {
		/*
		 * Pas de load_plugin_textdomain() : depuis WordPress 6.8, le chargement
		 * « just-in-time » des traductions couvre toutes les extensions dès lors
		 * que les en-têtes Text Domain et Domain Path sont présents et que le
		 * text domain correspond au slug du dossier du plugin.
		 */

		/*
		 * Les mises à jour sont branchées AVANT le contrôle des prérequis : si
		 * WooCommerce venait à manquer, le site doit rester capable de recevoir
		 * un correctif du plugin.
		 */
		Updater::register();

		/*
		 * Reprise de la configuration du snippet remplacé.
		 *
		 * Placée avant le contrôle des prérequis et appelée à chaque amorçage,
		 * jamais depuis `rsmw_upgrade` : cette action n'est émise qu'une fois, et
		 * pas nécessairement sur une requête où les constantes du snippet sont
		 * chargées. L'adosser au marqueur de version ferait échouer la reprise
		 * définitivement et sans signal. Sans constante, l'appel sort avant toute
		 * lecture d'option.
		 */
		Config::capture_legacy_constants();

		if ( ! Requirements::are_met() ) {
			add_action( 'admin_notices', array( Requirements::class, 'render_notice' ) );

			return;
		}

		/*
		 * WordPress n'exécute pas le hook d'activation lors d'une mise à jour :
		 * les migrations doivent donc aussi être déclenchées depuis une requête
		 * ordinaire. Le coût est d'une lecture d'option.
		 */
		Installer::maybe_upgrade();

		if ( SnippetGuard::snippet_is_active() ) {
			/*
			 * Le snippet remplacé est encore chargé. Tout enregistrer une seconde
			 * fois provoquerait des doublons : statut, métabox, entrées de menu et
			 * attribution automatique. Le plugin se met donc en veille.
			 */
			add_action( 'admin_notices', array( SnippetGuard::class, 'render_notice' ) );
		} else {
			/*
			 * Enregistré hors module : si le module était désactivé alors que des
			 * commandes portent encore ce statut, elles disparaîtraient des écrans
			 * d'administration.
			 */
			OrderStatus::register();
		}

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		$this->register_modules();

		/**
		 * Le plugin est prêt : tous les modules sont enregistrés.
		 *
		 * @param Plugin $plugin Instance du plugin.
		 */
		do_action( 'rsmw_loaded', $this );
	}

	/**
	 * Liste des classes de modules déclarées.
	 *
	 * Chaque snippet de gestion de stock devient un module : créez une classe
	 * dans src/Modules/, étendez AbstractModule, puis ajoutez-la ci-dessous.
	 *
	 * @return string[] Noms de classes implémentant ModuleInterface.
	 */
	public static function get_module_classes(): array {
		/**
		 * Liste des classes de modules à charger.
		 *
		 * @param string[] $classes Noms de classes implémentant ModuleInterface.
		 */
		return (array) apply_filters(
			'rsmw_module_classes',
			array(
				\RSMW\Modules\OrderPreparation::class,
			)
		);
	}

	/**
	 * Instancie et enregistre les modules actifs.
	 */
	private function register_modules(): void {
		foreach ( self::get_module_classes() as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			$module = new $class_name();

			if ( ! $module instanceof ModuleInterface ) {
				continue;
			}

			if ( ! $module->is_enabled() ) {
				continue;
			}

			$module->register();

			$this->modules[ $module->get_id() ] = $module;
		}
	}

	/**
	 * Retourne les modules actifs.
	 *
	 * @return ModuleInterface[]
	 */
	public function get_modules(): array {
		return $this->modules;
	}

	/**
	 * Retourne un module actif par son identifiant.
	 *
	 * @param string $id Identifiant du module.
	 *
	 * @return ModuleInterface|null
	 */
	public function get_module( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Empêche le clonage.
	 */
	private function __clone() {}

	/**
	 * Empêche la désérialisation.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Unserializing Plugin is not allowed.' );
	}
}
