=== Real Stock Manager for WooCommerce ===
Contributors: benoitbonavia
Tags: woocommerce, stock, inventaire, gestion de stock
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
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

= 1.1.2 =
* Correctif : dans la vue « À traiter » de la liste des commandes, le nombre total d’éléments et le nombre de pages étaient ceux de TOUTES les commandes, pas celles de la vue. Le tableau affichait bien les bonnes commandes, mais la pagination annonçait des pages qui n’existaient pas.
* Cause : WooCommerce transmet aux extensions une copie de ses arguments de requête. La liste interroge la copie filtrée, mais son comptage relit l’objet d’origine, où le filtre n’a jamais été appliqué. Ce raccourci de comptage n’est emprunté que si la requête n’emploie que des arguments standard ; la vue emploie désormais un argument qui l’écarte, et WooCommerce compte réellement les lignes de la requête filtrée.
* La vue « Précommandes » n’était pas touchée : son filtre par méta écartait déjà ce raccourci. Les deux vues le font maintenant explicitement, plutôt que de dépendre d’un effet de bord.

= 1.1.1 =
* Le tableau de « Besoins & stock » ne déborde plus de l’écran. Les gouttières des cellules passent de 24 à 10 pixels — avec dix colonnes, elles consommaient à elles seules près de 500 pixels — et les 24 pixels sont conservés aux deux extrémités.
* En-têtes raccourcis, libellé complet en infobulle : « Reste à commander » réservait 130 pixels pour afficher des nombres à deux chiffres. Il devient « Manque », ce qui le distingue aussi du champ de saisie « À commander » voisin dans un onglet fournisseur.
* Les en-têtes des colonnes chiffrées passent sur deux lignes au lieu d’imposer leur longueur à toute la colonne, et le numéro de commande la plus ancienne est empilé sous sa date.
* Sur un écran étroit, « Demandé », « Pointé » et le nombre de commandes s’effacent : les deux premiers se déduisent de « À préparer », le troisième est un indice de contexte. Le tri, l’export CSV et les indicateurs sont inchangés — ils lisent la ligne, pas les cellules affichées.
* Un nom de fournisseur long est tronqué avec son nom complet au survol, plutôt que de repousser les chiffres hors de l’écran.
* Filet de sécurité : si le tableau déborde malgré tout, le défilement horizontal reste confiné à la carte au lieu de décaler toute la page.

= 1.1.0 =
* Onglet « Réception d’un colis » : un sélecteur « Colis reçu de » filtre les références attendues par fournisseur, avec le nombre attendu chez chacun. On ne pointe plus que ce que le colis peut contenir.
* Le filtre survit à l’enregistrement : après avoir pointé un colis, la page revient sur le même fournisseur. Sans cela, la réapparition des autres références donnerait à croire que rien n’a été enregistré.
* Le sélecteur ne propose que les fournisseurs qui attendent réellement quelque chose, et « Sans fournisseur » si des références non affectées sont attendues — faute de quoi elles ne seraient réceptionnables par aucun filtre.
* Le nom du fournisseur entre dans la recherche de l’onglet, et le filtre fonctionne sans JavaScript.
* Correctif : un fournisseur nommé « Général » ou « Sans fournisseur » produisait un slug identique à celui d’un filtre du plugin. Ses références devenaient alors invisibles dans son propre onglet, derrière un compteur qui les annonçait pourtant. Ces deux slugs sont désormais réservés à la création du fournisseur.

= 1.0.0 =
* Première version majeure. Les snippets WPCode que cette extension remplaçait sont tous repris : préparation des commandes et stock physique, commandes fournisseur, réception de colis, précommandes, et désormais les fournisseurs eux-mêmes.
* Gestion des fournisseurs : les créer, les renommer, les supprimer depuis WooCommerce → Fournisseurs, et désigner celui qui fournit chaque produit dans l’onglet Inventaire de sa fiche.
* La page « Besoins & stock » a maintenant un onglet « Général » puis un onglet par fournisseur, chacun avec le nombre de références qu’il reste à lui commander. Les indicateurs — articles, reste à commander, valeur — ne portent que sur l’onglet ouvert : c’est le montant de la commande en cours de préparation.
* Commande fournisseur en lot depuis son onglet : un champ par ligne, prérempli avec ce qui manque, une vérification avant écriture, puis un seul enregistrement. Il fallait auparavant passer par Gestion stock → Mouvement, une référence à la fois — quinze allers-retours pour une commande de quinze lignes.
* Deux boutons de copie, l’un pour un e-mail, l’autre pour un tableur, et un export CSV dont le nom porte celui du fournisseur.
* Onglet « Sans fournisseur », en dernier et masqué dès qu’il est vide. Sans lui, une référence non affectée n’apparaîtrait dans aucun onglet et ne serait jamais commandée. L’onglet « Général » porte en plus une colonne Fournisseur, avec un tiret rouge quand il manque.
* Sur un produit à variations, le fournisseur du produit s’applique à toutes ses déclinaisons.
* Le compteur « commandé au fournisseur » n’est pas modifié : un produit n’ayant qu’un fournisseur, celui-ci se déduit de la référence. Aucune migration, aucune donnée reprise.
* Les formulaires de la page sont désormais traités avant l’envoi de l’en-tête, puis suivis d’une redirection : un rafraîchissement ne peut plus enregistrer deux fois la même commande fournisseur.
* Supprimer un fournisseur détache simplement les produits qui le référençaient, sans autre effet.

= 0.7.1 =
* Page « Besoins & stock » : la case « Manquants uniquement » est cochée d’entrée. La page sert d’abord à savoir quoi commander ; le filtre est appliqué dès le chargement, indicateurs compris.

= 0.7.0 =
* La bascule automatique vers le statut « Précommande » est de retour, en option (Réglages → Précommandes → Statut automatique, décochée par défaut). Une commande contenant des articles précommandés reçoit désormais le marqueur ET le statut.
* Trois différences avec le snippet remplacé : la décision se prend sur le marqueur et non sur is_on_backorder(), qui relit l’état courant du produit et se trompe dès que la marchandise est revenue ; la bascule n’a lieu qu’une fois, donc sortir une commande du statut à la main reste possible ; et la sortie est automatique, StatusSync ramenant la commande en « À empaqueter » dès que ses lignes sont pointées.
* La bascule est suspendue tant que « precommande » ne figure pas dans les « Statuts à préparer ». Sans cette garde, chaque précommande sortirait du circuit : absente de « Besoins & stock », jamais servie par l’entrée de stock, jamais ramenée en « À empaqueter ».
* Le statut « Précommande » compte maintenant comme un statut payé. Le chiffre d’affaires d’une précommande restait hors des rapports pendant toute l’attente du fournisseur alors que l’argent est encaissé, et la note d’achat disparaissait de l’espace client.
* Correctif : payment_complete() devenait un no-op sur une commande en « Précommande » — ni identifiant de transaction, ni date de paiement, ni passage en « En cours ». Une boutique encaissant par virement y aurait vu ses précommandes bloquées définitivement.
* Correctif : les téléchargements du client restent accordés en « Précommande », comme en « En cours ».
* La sémantique du statut (rapports, encaissement, téléchargements) est enregistrée hors module : désactiver le module ne fait plus sortir des rapports les commandes qui le portent.
* Correctif : à la désinstallation, les métas de commande sont nettoyées dans wc_orders_meta comme dans postmeta, et le balayage multisite ne s’arrête plus aux 100 premiers sites.

= 0.6.3 =
* Le panneau de diagnostic alerte quand des commandes portent le statut « Précommande » alors que ce statut ne figure pas dans les statuts suivis. Ces commandes sont alors hors du circuit de préparation : absentes de « Besoins & stock », et l’entrée de stock ne leur attribue rien. Rien ne le signalait à l’écran — la commande avait simplement l’air d’aller bien.

= 0.6.2 =
* Correctif : `ds_change_sale_text` ne fait plus partie des sentinelles qui mettent le module Précommandes en veille. Ce snippet ne surcharge que le libellé du badge promotionnel — il ne crée ni statut, ni méta, ni vue — mais son nom est générique et un marchand peut légitimement le réutiliser pour une règle sans rapport, une catégorie « outlet » par exemple. Le module se mettait alors en veille et le statut « Précommande » n’était plus enregistré, faisant disparaître les commandes concernées des écrans d’administration.
* Règle retenue : ne servent de sentinelle que les snippets qui écrivent ou déclarent quelque chose. Un simple filtre d’affichage n’en est pas un ; s’il coexiste avec le module, celui du plugin s’applique après et l’emporte sur les articles précommandés.
* L’avertissement d’administration et le panneau de diagnostic nomment désormais les fonctions détectées, pour les deux modules. Il fallait auparavant deviner lequel de ses snippets mettait le module en veille.

= 0.6.1 =
* Nouvelle colonne « Précommande » dans la liste des commandes, après « Préparation » : une commande contenant des articles précommandés se repère en balayant la liste, sans cliquer sur la vue. Elle affiche aussi la date d’expédition annoncée.
* Le marqueur ne lit aucun statut : il reste affiché quand la commande passe en préparation, puis expédiée, puis terminée — là où la colonne « Préparation » se tait. C’est ce que la suppression de la bascule automatique de statut avait fait perdre en 0.6.0.
* Le mot « Précommande » est écrit en clair dans la puce : l’information ne repose jamais sur la seule couleur. Le orange reste réservé aux commandes fournisseur.
* La date annoncée est désormais rafraîchie quand une ligne de précommande est ajoutée à une commande déjà marquée. Elle restait figée à sa première valeur.
* Métas de précommande lisibles sur la fiche de commande et dans l’aperçu : « Quantité précommandée » et « Précommande levée le » remplacent les clés techniques, et la date figée en doublon est masquée. Ces écrans affichent les métas soulignées, contrairement au front, aux emails et à l’espace client.
* Nettoyage de deux sélecteurs CSS hérités des snippets, qui ne correspondaient à aucun balisage de WooCommerce.

= 0.6.0 =
* Nouveau module « Précommandes », qui remplace cinq snippets : statut de commande, date d’expédition estimée, texte de disponibilité, libellé du bouton d’achat, badge promo et vue « À traiter ».
* La trace d’une précommande ne repose plus sur le statut de commande. Elle est posée à l’achat sur la ligne, en métas jamais réécrites : la commande peut passer en préparation puis être expédiée, elle reste identifiable comme précommande.
* Plus aucune bascule automatique de statut. Le statut « Précommande » reste enregistré et sélectionnable à la main ; les commandes qui le portent le gardent.
* Nouvelle vue « Précommandes » dans la liste des commandes, filtrée par méta et donc insensible au statut, plus la vue « À traiter » reconduite à partir des statuts réellement configurés.
* Date d’expédition estimée par produit et par variation, figée sur la ligne au moment de l’achat : modifier la fiche produit n’altère plus les commandes déjà passées. Une variation sans date propre reprend celle du parent.
* Horodatage de la levée : la ligne enregistre le moment où la marchandise est arrivée, ce qui permet de comparer le délai promis au délai tenu.
* Reprise de l’historique par lots à la mise à jour, sur les commandes portant le statut et sur celles dont une ligne porte une date d’expédition ou la méta native de rupture. Le volume repris s’affiche dans les réglages.
* La quantité précommandée n’est reconstituée que lorsque la méta native en donne le chiffre exact : une ligne qui ne porte qu’une date reste sans quantité, plutôt que de gonfler les statistiques d’un chiffre inventé. La commande, elle, est bien marquée dans tous les cas.
* Le module reste en veille tant qu’un des cinq snippets est actif, avec une liste de sentinelles distincte de celle du module Préparation.

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

= 1.1.2 =
Corrige la pagination de la vue « À traiter », qui annonçait bien plus de commandes et de pages qu’il n’en existait. Aucune donnée n’est modifiée.

= 1.1.1 =
Mise en page du tableau « Besoins & stock ». Aucune donnée ni aucun comportement n’est modifié.

= 1.1.0 =
Filtre par fournisseur sur la réception de colis. Aucune donnée n’est modifiée. Si vous aviez déjà créé un fournisseur nommé « Général » ou « Sans fournisseur », renommez-le : son slug entrait en conflit avec les filtres du plugin.

= 1.0.0 =
Nouvelle gestion des fournisseurs et onglets sur « Besoins & stock ». Aucune donnée existante n’est modifiée. Au premier chargement, toutes vos références seront dans l’onglet « Sans fournisseur » : affectez-les depuis la fiche produit, onglet Inventaire.

= 0.7.1 =
La case « Manquants uniquement » de Besoins & stock est cochée par défaut. Décochez-la pour retrouver la totalité des références.

= 0.7.0 =
La bascule automatique de statut revient, en option et décochée par défaut. Pour l’activer : ajoutez « precommande » aux Statuts à préparer, PUIS cochez Statut automatique. Le statut compte désormais comme payé, ce qui fait remonter le chiffre d’affaires des précommandes dans vos rapports, y compris passés.

= 0.6.3 =
Ajoute une alerte de diagnostic sur le statut « Précommande » laissé hors des statuts suivis. Aucune donnée n’est modifiée.

= 0.6.2 =
Correctif important si vous avez gardé une fonction nommée ds_change_sale_text : elle mettait le module Précommandes en veille et désenregistrait le statut « Précommande ». Aucune donnée n’est modifiée.

= 0.6.1 =
Colonne « Précommande » dans la liste des commandes. Aucune donnée n’est modifiée. Si la colonne n’apparaît pas, vérifiez « Options de l’écran » en haut de la liste.

= 0.6.0 =
Nouveau module Précommandes. Mettez à jour AVANT de désactiver les cinq snippets : le module reste en veille tant qu’ils sont actifs, et la reprise de l’historique démarre dès la mise à jour. Aucun statut de commande n’est modifié.

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
