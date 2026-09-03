=== Real Stock Manager for WooCommerce ===
Contributors: benoitbonavia
Tags: woocommerce, stock, inventaire, gestion de stock
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Centralise la gestion des stocks réels de WooCommerce : règles, automatismes et outils regroupés dans un plugin unique.

== Description ==

Real Stock Manager for WooCommerce remplace les snippets épars de gestion de stock par une extension structurée.

Chaque règle de gestion devient un « module » autonome, activable individuellement depuis
WooCommerce → Réglages → Stocks réels → Modules.

Le plugin déclare sa compatibilité avec le stockage haute performance des commandes (HPOS)
et avec les blocs Panier et Commande.

Les mises à jour sont distribuées depuis le dépôt GitHub du projet et apparaissent
directement dans l'écran Extensions de WordPress.

== Installation ==

1. Téléverser l'archive depuis Extensions → Ajouter → Téléverser une extension.
2. Activer l'extension. WooCommerce 9.9 ou supérieur doit être actif.
3. Configurer depuis WooCommerce → Réglages → Stocks réels.

== Frequently Asked Questions ==

= Le plugin nécessite-t-il un jeton GitHub ? =

Non. Le dépôt est public : les mises à jour fonctionnent sans configuration.
Définir la constante `RSMW_GITHUB_TOKEN` dans wp-config.php reste possible pour relever
la limite de l'API GitHub (60 requêtes par heure et par adresse IP sans jeton).

= Comment forcer une vérification des mises à jour ? =

Depuis l'écran Extensions, le lien « Check for updates » sous la ligne du plugin.
La vérification automatique a lieu au plus toutes les 12 heures.

== Changelog ==

= 0.1.0 =
* Version initiale : structure du plugin, registre de modules, onglet de réglages WooCommerce.
* Déclaration de compatibilité HPOS et blocs Panier/Commande.
* Mises à jour automatiques depuis GitHub.

== Upgrade Notice ==

= 0.1.0 =
Première version.
