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
├── readme.txt                              Métadonnées au format WordPress.org (lues pour la fiche de mise à jour)
├── uninstall.php                           Purge des options rsmw_* à la suppression
├── .gitattributes                          export-ignore : fichiers exclus des archives
├── .github/workflows/release.yml           Construit et publie l'archive sur push d'un tag v*
├── bin/build-plugin-zip.sh                 Construction reproductible de l'archive d'installation
├── lib/plugin-update-checker/              Bibliothèque tierce embarquée (Plugin Update Checker 5.7)
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
    ├── Updater.php                         Mises à jour depuis les releases GitHub
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

## Module « Préparation des commandes & stock physique »

Remplace le snippet WPCode « Maison Hespérides — Préparation ». Gère un stock physique réel,
distinct du stock WooCommerce : statut de commande « À empaqueter », métabox de pointage,
pages *Besoins & stock* et *Gestion stock*, attribution FIFO, champs de stock sur les produits.

### Continuité des données

Le module lit et écrit **exactement les mêmes clés** que le snippet — aucune migration.
Elles sont figées dans `src/Preparation/Legacy.php` et ne doivent jamais changer :

`_mh_stock_reel` · `_mh_prep_qty` · `_mh_prep_from_stock` · `_mh_prep_date` · `_mh_prep_user` ·
`_mh_prep_prev_status` · statut `wc-mh-empaqueter` · option `mh_prep_receptions` ·
pages `mh-prep-stock` et `mh-prep-reception`.

### Bascule sans coupure

Tant que le snippet est chargé, `SnippetGuard` met le module **et** l'enregistrement du statut en
veille, et affiche un avertissement. Sans cela les deux accrocheraient les mêmes hooks : double
attribution automatique, double métabox, entrées de menu en double.

Cette garde repose sur un point de calendrier : WPCode exécute les snippets « Exécuter partout »
sur `plugins_loaded` **priorité 5**, le plugin s'amorce sur ce même hook en **priorité 20**.
Abaisser cette priorité sous 5 casserait la détection sans aucun signal.

**Procédure :**

1. Sauvegarder la base de données.
2. Mettre à jour le plugin **en laissant le snippet actif** — la configuration est reprise depuis
   les constantes `MH_PREP_*` tant qu'elles existent.
3. Vérifier les statuts suivis dans *WooCommerce → Réglages → Stocks réels → Préparation*.
4. Désactiver le snippet WPCode.
5. Contrôler le panneau **Diagnostic** du même onglet : il affiche la volumétrie des données
   reprises (références avec stock, lignes pointées, mouvements) et l'état du statut.

**Retour arrière** : réactiver le snippet. Le module se remet en veille au chargement suivant,
les données n'ayant bougé dans aucun sens.

### Réglages

`Config` résout chaque réglage dans cet ordre : **constante `MH_PREP_*` → option `rsmw_*` → défaut**.
Une constante encore définie est signalée dans l'écran de réglages, et le champ correspondant reste
volontairement modifiable — le désactiver ferait enregistrer une valeur vide par WooCommerce.

## Mises à jour depuis GitHub

Le plugin embarque [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) 5.7
(`lib/plugin-update-checker/`, copie amont non modifiée). Les mises à jour apparaissent dans
l'écran Extensions de WordPress comme pour n'importe quelle extension.

Configuration dans `src/Updater.php` :

| Réglage | Valeur | Pourquoi |
|---------|--------|----------|
| Dépôt | `BenoitBonavia/Real-Stock-Manager-for-Woocommerce` | Public : aucun jeton requis. |
| `setBranch( 'main' )` | obligatoire | La bibliothèque suppose `master`. Les stratégies « dernière release » puis « tag le plus élevé » ne sont activées que si la branche vaut exactement `master` ou `main`. |
| `enableReleaseAssets( '/\.zip($\|[?&#])/i' )` | avec expression régulière | Sans filtre, le **premier** fichier attaché à la release est retenu, quel qu'il soit. |
| Slug | `dirname( RSMW_BASENAME )` | Doit correspondre au nom du dossier d'installation, sinon la mise à jour s'installe à côté. |
| `RSMW_GITHUB_TOKEN` | facultative | À définir dans `wp-config.php` pour relever la limite de l'API GitHub (60 req/h par IP sans jeton). Deviendrait obligatoire si le dépôt passait en privé. |

Le vérificateur est branché **avant** le contrôle des prérequis WooCommerce : si WooCommerce
venait à manquer, le site doit rester capable de recevoir un correctif.

### Règle absolue : le tag ne fait pas la version

La bibliothèque lit l'en-tête `Version:` du fichier principal **tel qu'il est dans le tag distant**.
Le tag indique seulement *où* lire. Taguer `v0.2.0` en laissant `Version: 0.1.0` ne déclenche
**aucune** mise à jour, et sans message d'erreur.

Trois valeurs doivent rester alignées, plus le tag git :

1. l'en-tête `Version:` du fichier principal ;
2. la constante `RSMW_VERSION` ;
3. le `Stable tag:` de `readme.txt`.

`bin/build-plugin-zip.sh` échoue si elles divergent, et le workflow de release compare
en plus le tag git (`--expect`).

## Publier une version

```bash
# 1. Bumper les trois numéros de version (fichier principal ×2, readme.txt)
# 2. Committer, puis :
git tag v0.2.0
git push origin main --tags
```

Le workflow `.github/workflows/release.yml` construit alors l'archive et la publie
en pièce jointe de la release. Les sites vérifient au plus toutes les 12 heures ;
le lien « Check for updates » de l'écran Extensions force une vérification.

Construire l'archive localement :

```bash
bin/build-plugin-zip.sh              # produit dist/real-stock-manager-for-woocommerce-<version>.zip
bin/build-plugin-zip.sh --ref v0.2.0 # à partir d'un tag précis
```

Le script archive une **référence git**, pas le répertoire de travail : les modifications
non commitées sont ignorées, volontairement, pour que l'archive soit reproductible.

## Choix conformes aux standards actuels

| Point | Choix | Raison |
|-------|-------|--------|
| `Update URI: false` | présent, **conservé** | Empêche WordPress.org d'écraser ce plugin par une extension homonyme. Aucun conflit avec le vérificateur de mises à jour : celui-ci injecte via le filtre de lecture `site_transient_update_plugins`, jamais via `update_plugins_{$hostname}`. Mettre l'URL GitHub à la place serait pire : le cœur en extrait le hostname `github.com` et n'importe quelle autre extension accrochée à `update_plugins_github.com` pourrait injecter une mise à jour arbitraire. |
| `load_plugin_textdomain()` | **absent** | Depuis WordPress 6.8, le chargement « just-in-time » couvre toutes les extensions via les en-têtes `Text Domain` / `Domain Path`. Appeler la fonction n'apporte rien et expose au `_doing_it_wrong` « translation loading triggered too early ». |
| `declare( strict_types=1 )` | **absent** | WooCommerce ne l'utilise pas, y compris dans son code moderne sous `src/`. En mode strict, une valeur transmise par un filtre WordPress (souvent `string` là où l'on attend `int`) lève une `TypeError` au lieu d'être convertie. Les types de paramètres et de retour sont conservés, en mode coercitif. |
| `wp_enqueue_script()` | `$args` en tableau | Signature WordPress 6.3+ (`in_footer`, `strategy`) plutôt que le booléen historique. |
| Données JS | `wp_add_inline_script()` | `wp_localize_script()` est destinée aux chaînes traduisibles et convertit tout en `string`. |
| Réglages | `WC_Settings_Page` + `get_own_sections()` / `get_settings_for_{section}_section()` | API courante ; `get_settings()` est dépréciée depuis WooCommerce 5.4. |
| `WC tested up to` | tenu à jour | WooCommerce n'affiche les avertissements de compatibilité HPOS que pour les extensions qui renseignent cet en-tête. |

**À ajuster selon ton installation** : `Requires at least`, `WC requires at least` et la constante `RSMW_MIN_WC_VERSION` (fichier principal) sont calés sur WP 6.8 / WC 9.9. Baisse-les si ton site tourne sur des versions plus anciennes — mais WP 6.8 est le plancher réel pour l'i18n sans `load_plugin_textdomain()`.
