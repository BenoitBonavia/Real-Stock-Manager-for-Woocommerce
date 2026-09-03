# Real Stock Manager for WooCommerce

Plugin maison regroupant les règles et automatismes de gestion des **stocks réels** d'une boutique WooCommerce, à la place de snippets dispersés dans `functions.php` ou Code Snippets.

- **Version** : 0.1.0
- **Prérequis** : WordPress 6.8+, PHP 7.4+, WooCommerce 9.9+ (testé jusqu'à 11.0)
- **Préfixe** : `rsmw_` (options, hooks) / `RSMW\` (namespace PHP)
- **Text domain** : `real-stock-manager-for-woocommerce` (doit rester identique au slug du dossier)

## Arborescence

```
real-stock-manager-for-woocommerce/
├── real-stock-manager-for-woocommerce.php  Fichier principal : en-tête, constantes, hooks d'amorçage
├── uninstall.php                           Purge des options rsmw_* à la suppression
├── composer.json                           Autoload PSR-4 + outillage de dev (PHPCS, PHPStan)
├── phpcs.xml.dist                          Règles WordPress Coding Standards
├── phpstan.neon.dist                       Analyse statique avec stubs WordPress/WooCommerce
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── languages/                              Fichiers .pot / .po / .mo
└── src/
    ├── Autoloader.php                      Autoload PSR-4 sans Composer
    ├── functions.php                       Helpers globaux : rsmw(), rsmw_log()
    ├── Plugin.php                          Conteneur : amorçage + registre des modules
    ├── Requirements.php                    Vérification WooCommerce
    ├── Installer.php                       Activation / désactivation / migrations
    ├── Admin/
    │   ├── Admin.php                       Hooks admin, assets, lien « Réglages »
    │   └── SettingsTab.php                 WooCommerce → Réglages → Stocks réels
    ├── Modules/
    │   ├── ModuleInterface.php             Contrat d'un module
    │   └── AbstractModule.php              Base : activation pilotée par option
    └── Support/
        ├── Settings.php                    Lecture/écriture des options rsmw_*
        └── Logger.php                      Journaux WooCommerce (source real-stock-manager)
```

## Convertir un snippet en module

1. Créer `src/Modules/MonModule.php` :

```php
<?php

declare( strict_types=1 );

namespace RSMW\Modules;

defined( 'ABSPATH' ) || exit;

final class MonModule extends AbstractModule {

	protected $id    = 'mon_module';
	protected $title = 'Titre affiché dans les réglages';

	public function register(): void {
		add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'filter_stock' ), 10, 2 );
	}

	public function filter_stock( $quantity, $product ) {
		// Le contenu du snippet vient ici.
		return $quantity;
	}
}
```

2. Le déclarer dans `Plugin::get_module_classes()` :

```php
return (array) apply_filters(
	'rsmw_module_classes',
	array(
		\RSMW\Modules\MonModule::class,
	)
);
```

Une case à cocher `rsmw_module_mon_module_enabled` apparaît automatiquement dans
**WooCommerce → Réglages → Stocks réels → Modules**. Le module n'est chargé que si elle est cochée.

## Points d'extension

| Hook | Type | Description |
|------|------|-------------|
| `rsmw_loaded` | action | Le plugin est amorcé, tous les modules sont enregistrés. |
| `rsmw_module_classes` | filtre | Ajouter ou retirer des classes de modules. |
| `rsmw_module_is_enabled` | filtre | Forcer l'état d'un module (contourne les réglages). |
| `rsmw_setting` | filtre | Filtrer la valeur d'un réglage. |
| `rsmw_upgrade` | action | Migrations de données à l'activation après changement de version. |
| `rsmw_activated` / `rsmw_deactivated` | actions | Activation / désactivation. |

## Développement

```bash
composer install   # outillage de dev uniquement, l'autoload de prod ne dépend pas de vendor/
composer lint      # PHPCS (WordPress Coding Standards 3.4)
composer lint:fix  # PHPCBF
composer analyse   # PHPStan niveau 6 + stubs WordPress/WooCommerce
```

L'autoloader maison (`src/Autoloader.php`) suffit en production : le plugin fonctionne sans exécuter `composer install`.

## Compatibilité déclarée

- HPOS / `custom_order_tables`
- Blocs Cart & Checkout

Ces déclarations sont faites sur `before_woocommerce_init` dans le fichier principal ; à réévaluer si un module venait à manipuler directement les tables de commandes historiques.

## Choix conformes aux standards actuels

| Point | Choix | Raison |
|-------|-------|--------|
| `Update URI: false` | présent | Empêche WordPress.org d'écraser ce plugin par une extension homonyme du dépôt officiel. |
| `load_plugin_textdomain()` | **absent** | Depuis WordPress 6.8, le chargement « just-in-time » couvre toutes les extensions via les en-têtes `Text Domain` / `Domain Path`. Appeler la fonction n'apporte rien et expose au `_doing_it_wrong` « translation loading triggered too early ». |
| `declare( strict_types=1 )` | **absent** | WooCommerce ne l'utilise pas, y compris dans son code moderne sous `src/`. En mode strict, une valeur transmise par un filtre WordPress (souvent `string` là où l'on attend `int`) lève une `TypeError` au lieu d'être convertie. Les types de paramètres et de retour sont conservés, en mode coercitif. |
| `wp_enqueue_script()` | `$args` en tableau | Signature WordPress 6.3+ (`in_footer`, `strategy`) plutôt que le booléen historique. |
| Données JS | `wp_add_inline_script()` | `wp_localize_script()` est destinée aux chaînes traduisibles et convertit tout en `string`. |
| Réglages | `WC_Settings_Page` + `get_own_sections()` / `get_settings_for_{section}_section()` | API courante ; `get_settings()` est dépréciée depuis WooCommerce 5.4. |
| `WC tested up to` | tenu à jour | WooCommerce n'affiche les avertissements de compatibilité HPOS que pour les extensions qui renseignent cet en-tête. |

**À ajuster selon ton installation** : `Requires at least`, `WC requires at least` et la constante `RSMW_MIN_WC_VERSION` (fichier principal) sont calés sur WP 6.8 / WC 9.9. Baisse-les si ton site tourne sur des versions plus anciennes — mais WP 6.8 est le plancher réel pour l'i18n sans `load_plugin_textdomain()`.
