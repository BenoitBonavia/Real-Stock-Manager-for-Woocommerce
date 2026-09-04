=== Real Stock Manager for WooCommerce ===
Contributors: benoitbonavia
Tags: woocommerce, stock, inventaire, gestion de stock
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
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

= 0.5.0 =
* Nouvel onglet « Réception d’un colis » : la page Gestion du stock propose la liste de ce qui est attendu, avec deux champs par référence — conforme et défectueux.
* Vérification avant écriture : le total, les commandes qui passeraient en « À empaqueter » et les anomalies s’affichent d’abord ; rien n’est enregistré tant que la réception n’est pas confirmée.
* Les défectueux n’entrent jamais en stock : ils cessent d’être attendus et la référence remonte dans « Reste à commander ». Ils ne sont pas non plus reçus puis écartés — l’aller-retour aurait transféré du stock réel entre commandes clients, la réception servant les plus anciennes et le retrait reprenant aux plus récentes.
* Cumul des défectueux par référence, exploitable pour une réclamation fournisseur.
* Correctif : les formulaires de la page sont traités avant l’envoi de l’en-tête et suivis d’une redirection. Un rafraîchissement de page ne rejoue plus le mouvement — sur une réception en lot, c’était un colis entier qui pouvait être enregistré deux fois.
* Le journal conserve 200 mouvements au lieu de 40, et chaque entrée porte l’identifiant de la référence et celui du colis.

= 0.4.0 =
* Suivi des commandes fournisseur : troisième état du stock, entre le manquant et le reçu.
* Console de mouvement à quatre sens : Entrée, Commande fournisseur, Annulation, Retrait.
* Attribution FIFO des quantités commandées sur les commandes clients les plus anciennes, sans effet sur le statut — une commande fournisseur ne rend jamais une commande « À empaqueter ».
* Une nouvelle commande client se sert dans le commandé non encore attribué.
* Barre de progression à deux segments, bleu pour le préparé et orange pour le commandé, sur la fiche commande et dans la liste des commandes.
* Page « Besoins & stock » : colonnes « En commande » et « Reste à commander », qui déduit désormais ce qui est déjà commandé.
* Champ « Commandé au fournisseur » sur les produits simples et sur chaque variation.
* Journal des mouvements : filtre « Fournisseur » et colonne « En commande ».

= 0.3.1 =
* Refonte de la page « Gestion stock » en console de mouvement : un seul formulaire avec sélecteur Entrée/Retrait, au lieu de deux formulaires dupliqués.
* Panneau de contexte : la sélection d'une référence affiche son stock libre, ce qu'il reste à préparer, le nombre de commandes en attente et la plus ancienne d'entre elles.
* Journal des mouvements filtrable par sens et par recherche.
* Socle visuel aligné sur les écrans WooCommerce récents : cartes aux valeurs Gutenberg, boutons du cœur, jetons de design --wpds-* de WordPress 7.1 avec valeurs de repli.
* Page « Besoins & stock » réalignée sur le même socle.
* Correctif : le conteneur des deux pages porte désormais la classe « woocommerce », sans laquelle les styles de formulaire de WooCommerce ne s'appliquaient pas.

= 0.2.0 =
* Module « Préparation des commandes & stock physique » : reprise intégrale du snippet, sans migration de données.
* Statut de commande « À empaqueter », métabox de pointage, colonne d'avancement sur la liste des commandes.
* Pages « Besoins & stock » et « Gestion stock » (entrées, retraits, réaffectation FIFO, journal des mouvements).
* Champs de stock physique sur les produits simples et sur chaque variation.
* Mise en veille automatique tant que le snippet WPCode équivalent est actif, pour permettre une bascule sans coupure.
* Reprise automatique de la configuration depuis les constantes MH_PREP_*.
* Panneau de diagnostic dans les réglages : volumétrie des données reprises et état du statut.

= 0.1.0 =
* Version initiale : structure du plugin, registre de modules, onglet de réglages WooCommerce.
* Déclaration de compatibilité HPOS et blocs Panier/Commande.
* Mises à jour automatiques depuis GitHub.

== Upgrade Notice ==

= 0.5.0 =
Nouvel écran de réception en lot. Aucune donnée existante n’est modifiée.

= 0.4.0 =
Nouveau suivi des commandes fournisseur. Aucune donnée existante n’est modifiée : les nouveaux compteurs démarrent à zéro, ce qui signifie « rien en commande ».

= 0.3.1 =
Refonte de l’interface de gestion du stock. Aucune donnée n’est modifiée, aucune action requise.

= 0.2.0 =
Mettez à jour l’extension AVANT de désactiver le snippet : la configuration est reprise depuis ses constantes tant qu’il est encore actif. Le module reste en veille jusqu’à sa désactivation.

= 0.1.0 =
Première version.
