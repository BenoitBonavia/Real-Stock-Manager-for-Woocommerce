# Real Stock Manager for WooCommerce

Plugin maison regroupant les règles et automatismes de gestion des **stocks réels** d'une boutique WooCommerce, à la place de snippets dispersés dans `functions.php` ou Code Snippets.

- **Version** : 0.1.0
- **Prérequis** : WordPress 6.8+, PHP 7.4+, WooCommerce 9.9+ (testé jusqu'à 11.0)
- **Dépendance optionnelle** : pour valoriser le stock à son coût d'achat sur « Gestion du stock », le plugin lit le coût déclaré par « Cost of Goods Sold » (natif WooCommerce 10.3+, à activer dans Réglages → Avancé → Fonctionnalités) ou par le plugin gratuit « Cost of Goods for WooCommerce » (WPFactory). Sans l'un des deux, seule la valeur marchande est calculée.
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
| `rsmw_product_cost` | filtre | Ajuster le coût d'achat unitaire résolu pour un produit (`RSMW\Preparation\Cost::for_product()`). |

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
pages *Besoins pour commande* et *Gestion stock*, attribution FIFO, champs de stock sur les produits.

### Les trois états du stock

| État | Couleur | Clé produit | Clé ligne de commande |
|------|---------|-------------|------------------------|
| Préparé — en main, pointé | bleu | `_mh_stock_reel` (libre) | `_mh_prep_qty` |
| Commandé au fournisseur — pas encore reçu | orange | `_rsmw_stock_ordered` (libre) | `_rsmw_prep_ordered` |
| Manquant — ni l'un ni l'autre | vide | — | — |

**Invariant par ligne : `préparé + commandé ≤ quantité`.** Il est tenu en un seul endroit,
`Items::set_quantity()`, par où passe toute variation du préparé : à la hausse, la part commandée
fond d'autant, ce qui évite de compter deux fois une unité qui vient d'arriver ; à la baisse elle
reste intacte, un dépointage ne ressuscitant pas une commande fournisseur.

Corollaire dans `Allocator::receive()` : seul le **résidu** (`reçu − converti`) est retiré du
compteur libre, ce que les lignes ont déjà absorbé ne devant pas l'être une seconde fois.

Une commande fournisseur **ne synchronise jamais le statut** : la marchandise n'est pas là, la
commande ne peut donc pas devenir « À empaqueter ».

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

### Interface

La page *Gestion du stock* a trois onglets. **Réception d'un colis** liste ce qui est attendu et
propose deux champs par référence, conforme et défectueux, avec vérification avant écriture.
**Mouvement à l'unité** est la console : un formulaire unique à quatre sens, et un panneau qui se
renseigne en AJAX dès qu'une référence est choisie. **Inventaire** liste le catalogue entier —
voir plus bas. Le journal, commun aux deux premiers onglets, est filtrable par sens ; l'Inventaire
n'en a pas (voir la raison ci-dessous).

Les formulaires sont traités sur `load-{écran}`, avant l'envoi de l'en-tête d'administration —
seule fenêtre où une redirection reste possible. Sans elle, un rafraîchissement rejouerait
l'écriture, et sur une réception en lot c'est un colis entier qui serait enregistré deux fois.

### Inventaire : correction directe, pas un mouvement

Les deux autres onglets sont **demand-driven** : ils ne listent que des références déjà engagées
dans une commande client ou fournisseur. L'onglet Inventaire (`RSMW\Preparation\Inventory`) est le
premier écran du plugin à parcourir le **catalogue entier** — un produit simple par ligne, une
ligne par déclinaison pour un produit à variations, jamais le produit variable parent lui-même.
Aucune fonction WooCommerce ne permet de mélanger `product` et `product_variation` dans un seul
appel : la liste vient d'une requête SQL directe sur `wp_posts`, dans l'esprit de
`BackInStock\Demand`.

C'est une correction **directe** de la valeur affichée, au même titre que les champs « Stock
physique libre » / « Commandé au fournisseur » de la fiche produit (`ProductFields`) — pas un
mouvement : aucun passage par `Allocator` (pas de sens, pas de réaffectation FIFO aux commandes
clients), aucune entrée au journal ligne par ligne. `Inventory::apply()` n'écrit **que les valeurs
qui diffèrent réellement** de celles en base, un seul `Log::info()` récapitulatif à la fin — le
formulaire soumet tout le catalogue à chaque enregistrement, filtré ou non côté client, et la
quasi-totalité des lignes n'aura pas été touchée.

Catalogue de quelques centaines de références ou moins : recherche, filtre par catégorie
(`product_cat`) et tri se font entièrement côté navigateur (`assets/js/inventory-table.js`), sans
pagination — le même principe que `needs-table.js` pour « Besoins pour commande ». Les catégories
sont résolues en une seule requête groupée (`wp_get_object_terms()`), jamais `get_the_terms()`
par ligne : `Labels::prime()` n'amorce aucune taxonomie, seulement les posts et leurs métadonnées.

Une troisième colonne modifiable porte le **stock WooCommerce** — celui qui gouverne « en stock » /
« rupture » côté client, distinct des deux compteurs internes du plugin. Une variation peut suivre
sa propre quantité, ou l'hériter de son produit parent (stock mutualisé entre déclinaisons,
réglage « Gérer le stock ? » laissé sur « Parent » côté variation) : `Inventory::woo_stock_for()`
résout cette indirection avec `WC_Product::get_stock_managed_by_id()`, la même méthode
qu'utilise `wc_update_product_stock()` en écriture — lecture et écriture visent donc toujours le
même compteur. Une référence qui ne suit aucun stock affiche un tiret, jamais un champ : ce
tableau ne pose jamais `manage_stock` à la volée. La cellule passe en rouge dès que la quantité
tombe à zéro ou en dessous, y compris pendant la saisie (`assets/js/inventory-table.js`), puisque
c'est ce chiffre qui décide de ce que le client voit.

Chaque ligne s'ouvre sur une vignette carrée, la même que sur la fiche produit :
`WC_Product::get_image( 'woocommerce_thumbnail' )` — jamais la taille `thumbnail` de WordPress,
qui dépend des réglages Médias et n'est pas garantie carrée. Cette méthode gère elle-même le
repli sur l'image du produit parent pour une variation qui n'en a pas, puis sur le placeholder
WooCommerce : aucune logique de repli à écrire côté plugin.

### Réception : ce qu'un défectueux ne doit pas faire

Un article reçu défectueux **n'entre jamais en stock pour en ressortir aussitôt**. L'aller-retour
paraît neutre, il ne l'est pas : `Allocator::receive()` sert les commandes de la plus **ancienne** à
la plus récente, `Allocator::withdraw()` reprend de la plus **récente** à la plus ancienne. Le couple
transfère donc du stock réel d'une commande vers une autre, avec deux changements de statut et deux
notes sur des commandes étrangères au colis.

Un défectueux passe par `Allocator::cancel_supplier_order()` : il cesse d'être attendu, sans toucher
au stock physique ni aux statuts. La référence remonte alors dans « Reste à commander », où elle est
visible. Le cumul par référence vit dans `Defects` — le journal, borné et destiné à l'exploitation
courante, ne peut pas porter une réclamation fournisseur.

Le socle visuel n'introduit **aucune chaîne de build**. Il repose sur trois choses vérifiées dans
les sources de WordPress 7.1 et WooCommerce 11 :

- les **jetons `--wpds-*`** de la feuille `wp-theme`, déclarée en dépendance et systématiquement
  écrite avec une valeur de repli — `var(--wpds-border-radius-lg, 8px)` ;
- les **boutons du cœur** (`.button`, `.button-primary`), qui suivent déjà la refonte WordPress 7.0
  (40 px de haut, rayon 2 px, anneaux de focus) ;
- une **carte maison** reprenant les valeurs exactes de la Card de Gutenberg.

Attention : `.components-card` ne produit **aucun** style depuis PHP — Card, Flex et Text sont passés
en CSS-in-JS. Seuls `components-button`, `components-notice`, `components-panel`, `components-badge`
et `components-spinner` sont du CSS statique réellement utilisable sans React.

Le conteneur des deux pages doit porter la classe `woocommerce` : tout le style de formulaire de
WooCommerce y est scopé.

### Réglages

`Config` résout chaque réglage dans cet ordre : **constante `MH_PREP_*` → option `rsmw_*` → défaut**.
Une constante encore définie est signalée dans l'écran de réglages, et le champ correspondant reste
volontairement modifiable — le désactiver ferait enregistrer une valeur vide par WooCommerce.

## Module « Précommandes »

Quand le fournisseur n'a pas de stock mais peut fabriquer à la demande, la boutique ouvre une
**précommande**. Le module (`src/PreOrder/`) remplace cinq snippets : statut de commande, date
d'expédition estimée, texte de disponibilité, libellé du bouton, badge promo, vue « À traiter ».

### La trace n'est pas le statut

C'est le défaut que ce module corrige. Les snippets faisaient porter l'information « c'est une
précommande » par le **statut de commande**, or un statut est une valeur unique : dès que la commande
avance vers la préparation puis l'expédition, la trace disparaît. Impossible de savoir a posteriori
ce qui avait été précommandé.

Le module sépare donc les deux :

| | Où | Écrit quand | Réécrit ? |
|---|---|---|---|
| **La trace** | métas de ligne de commande | à l'achat | jamais |
| **L'état** | statut de commande | au fil du flux | librement, par WooCommerce ou à la main |

Conséquence directe : le statut n'est plus jamais *nécessaire*. Il redevient un simple état de flux —
ce qui, paradoxalement, permet de l'automatiser sans danger.

### La bascule automatique, rétablie en 0.7.0

`PreOrder\StatusFlip` replace la commande dans le statut « Précommande ». Optionnel, décoché par
défaut : *Réglages → Précommandes → Statut automatique*.

Trois différences avec le snippet remplacé, chacune corrigeant un défaut constaté :

| | Snippet | Module |
|---|---|---|
| Décision | `is_on_backorder()` rejoué après l'achat | le **marqueur** de la commande |
| Répétition | à chaque passage en « En cours » | **une seule fois** |
| Sortie | jamais — statut manuel | `StatusSync` → « À empaqueter » au pointage |

Le premier point est le plus important : `is_on_backorder()` lit l'état *courant* du produit, sans
contexte de commande. Rejoué après l'achat, il répond « non » dès que la marchandise est revenue — le
snippet ratait donc la bascule. Le marqueur, lui, dit ce qui a réellement été vendu.

**La bascule est suspendue tant que `precommande` ne figure pas dans les « Statuts à préparer ».**
Ce n'est pas de la prudence : sans cette garde, chaque précommande sortirait du circuit — absente de
`Demand`, ignorée par `Allocator`, jamais ramenée en « À empaqueter » par `StatusSync`. Elle resterait
bloquée. `Config::auto_status_is_operative()` porte cette règle, et le diagnostic affiche les trois
états séparément : *demandée / statut suivi / effective*.

**Priorité 30 sur `woocommerce_order_status_changed`, et ce n'est pas cosmétique.**
`Preparation\Allocator` est sur le même hook en priorité 20 : il sert la commande dans le stock libre
puis, si elle devient complète, appelle `StatusSync` qui la passe en « À empaqueter ». À égalité de
priorité nous étions départagés par l'ordre d'enregistrement des modules, et nous écrasions un
« À empaqueter » tout juste posé. Pour la même raison, `maybe_apply()` **ignore les arguments `$to` et
`$order` du hook** — figés avant que les autres n'agissent — et relit la commande.

### La sémantique du statut vit hors du module

Trois filtres décrivent ce que « Précommande » *signifie* pour WooCommerce, et sont donc enregistrés
avec le statut, pas avec le module :

- `woocommerce_order_is_paid_statuses` — sans quoi le chiffre d'affaires d'une précommande sort des
  rapports pendant toute l'attente du fournisseur, alors que l'argent est encaissé ;
- `woocommerce_valid_order_statuses_for_payment_complete` — sans quoi `payment_complete()` devient un
  **no-op silencieux** : une boutique encaissant par virement verrait ses précommandes bloquées là
  définitivement ;
- `woocommerce_order_is_download_permitted` — `WC_Order::is_download_permitted()` teste « Terminée »
  ou « En cours » en dur, la bascule retirerait donc l'accès aux fichiers.

Les laisser dans le module ferait qu'en le désactivant, des commandes qui n'ont pas bougé sortiraient
des rapports.

### Ce que la bascule faisait bien, et qu'il a fallu rendre

La bascule automatique avait une vertu : elle colorait la colonne Statut, donc le marchand repérait
ses précommandes en balayant la liste. La supprimer a laissé un trou — plus aucun marqueur visuel,
seulement un lien de vue à cliquer.

D'où la colonne **« Précommande »**, insérée après « Préparation ». Elle ne lit **que** le drapeau de
commande `_rsmw_has_preorder`, jamais un statut : c'est la garantie mécanique qu'une commande expédiée
ou terminée affiche encore sa puce, là précisément où la colonne « Préparation » se tait.

```
 ☐  Commande            Date      Statut       Préparation    Précommande            Total
 ─────────────────────────────────────────────────────────────────────────────────────────────
 ☐  #14827 C. Dubois    2 sept.   [En cours]   ▰▰▰▱▱ 2/4 +1   ● Précommande 12 oct.  184,50 €
 ☐  #14826 M. Ferrand   2 sept.   [Expédiée]   ·              ● Précommande 30 sept.  62,00 €
 ☐  #14825 A. Roy       1 sept.   [En cours]   ▰▰▰▰▰ 3/3      ·                      118,90 €
```

Trois contraintes ont dicté la forme :

**Abscisse fixe.** Un marqueur greffé sur le numéro de commande ou sur la pastille de statut flotte
horizontalement selon la longueur du nom de l'acheteur. Une colonne crée une bande où l'œil ne
distingue que deux formes — la puce, ou le point médian.

**Un mot, pas une couleur.** « Précommande » est écrit en clair ; le bordeaux et la puce ronde ne sont
que des accélérateurs de balayage. C'est aussi ce qui a écarté la classe CSS sur le `<tr>` : signal
purement chromatique, et disponible sous HPOS seulement.

**Coût nul par ligne.** On lit deux métas *de commande*, jamais les lignes. Sous HPOS elles sont déjà
en mémoire — `CustomMetaDataStore::get_meta_data_for_object_ids()` charge celles des vingt commandes
de l'écran en une requête. Passer par `Marker::order_has_marked_line()` aurait coûté une soixantaine
d'objets de ligne sous HPOS, et de l'ordre de cent cinquante requêtes en stockage historique.

Le corollaire est assumé : **la couverture du marqueur vaut exactement celle du marquage**. Une
commande que la reprise d'historique n'a pas pu marquer n'affiche rien — et n'apparaît pas non plus
dans la vue « Précommandes ». C'est le test de diagnostic : si une commande manque dans la vue, le
problème est le marquage, pas l'affichage.

L'ordre « Statut | Préparation | Précommande » tient sur les deux modes de stockage sans jamais nommer
la clé de la colonne Préparation, qui est une constante privée d'un autre module — voir le commentaire
de `PreOrder\Admin\OrdersColumn::add_column()`, les deux mécanismes diffèrent.

### Métas lisibles sur la fiche de commande

L'écran de modification d'une commande et la modale d'aperçu appellent `get_all_formatted_meta_data( '' )`
— **préfixe vide**. Contrairement au front, aux emails et à l'espace client, ils n'écartent donc pas
les clés soulignées. Sans traitement, le marchand y lit `_rsmw_preorder_qty: 2`.

`PreOrder\Admin\ItemMeta` masque ce qui fait doublon et renomme le reste : « Quantité précommandée »,
« Précommande levée le » avec l'horodatage mis en forme. Aucun risque de fuite côté client — en front
la boucle écarte les clés soulignées **avant** d'appliquer le filtre de libellé.

### Métas posées

| Donnée | Clé | Emplacement |
|---|---|---|
| Date d'expédition estimée | `_mh_preorder_date` | produit **et** variation |
| Date figée à l'achat | `_mh_preorder_date` | ligne de commande |
| Date lisible par le client | `Expédition estimée` | ligne, **visible** |
| Quantité précommandée | `_rsmw_preorder_qty` | ligne |
| Levée de la précommande | `_rsmw_preorder_filled_at` | ligne |
| Commande concernée | `_rsmw_has_preorder` | commande (index) |
| Date promise la plus lointaine | `_rsmw_preorder_date_max` | commande (tri) |

Les quatre premières clés sont **gelées** : elles sont écrites à l'identique par les snippets
remplacés. `Expédition estimée` est délibérément une **chaîne littérale, pas un `__()`** — sur une
méta de ligne, le libellé *est* la clé de stockage : le passer par la traduction rendrait orphelines
toutes les métas déjà en base au premier changement de locale.

Le drapeau au niveau commande n'est qu'un index. La vérité est sur la ligne ; sans lui, retrouver les
précommandes imposerait de parcourir toutes les lignes de toutes les commandes.

### Pose des marqueurs

Sur `woocommerce_checkout_create_order_line_item`, qui couvre aussi le tunnel en blocs — le Store API
délègue à `WC_Checkout::create_order_line_items()`. La quantité est lue en priorité sur la méta native
`Backordered` que WooCommerce vient d'écrire, sinon recalculée : cette méta n'existe que si le réglage
de rupture vaut « Autoriser, mais informer le client ».

Le callback est **idempotent et purement déclaratif** : il n'écrit que des métas, aucun journal,
aucun compteur, aucun mouvement de stock.

Deux limites assumées. `woocommerce_new_order_item` est **mort** — il n'est plus émis que par des
fonctions dépréciées : le back-office est rattrapé par `woocommerce_ajax_add_order_item_meta`, mais
**l'API REST et le Point de vente ne sont pas couverts**. Et `is_on_backorder()` lit l'état *courant*
du produit, sans contexte de commande : la condition n'est donc **jamais** rejouée après l'achat.

### Levée

`Preparation\Items::set_quantity()` émet `rsmw_line_prepared( $item, $prepared, $quantity )` — le seul
point par où passe toute variation du préparé. Le module s'y abonne pour horodater la levée, sans que
les deux modules aient à se connaître.

### Reprise de l'historique

Par lots, à chaque chargement de l'administration, en deux phases : les commandes portant
`wc-precommande` (**sans toucher au statut**), puis celles dont une ligne porte une date d'expédition
ou la méta native de rupture.

Une commande déjà repassée en « En cours » et dont aucune ligne ne porte de méta est **définitivement
perdue** — c'est exactement le trou que ce modèle vient boucher. Le volume repris s'affiche dans
l'écran de réglages.

### Bascule

`PreOrder\SnippetGuard` a sa **propre** liste de sentinelles, séparée de celle de `Preparation`. Ce
n'est pas de la duplication : `snippet_is_active()` est un OU plat sans notion de portée, et
`Plugin::boot()` s'en sert pour conditionner *à la fois* le module et l'enregistrement du statut.
Mutualiser les listes mettrait tout le module Préparation en veille dès qu'un seul snippet de
précommande resterait actif.

**Ce qui fait une sentinelle valable** — seulement un snippet qui **écrit ou déclare** quelque chose :
un statut, une méta, une vue, un automatisme. Un simple filtre d'affichage n'en est pas un.

La leçon a été apprise à ses dépens : `ds_change_sale_text` avait été mise dans la liste. Elle ne
surcharge pourtant que le libellé du badge promotionnel — aucun doublon de données possible. Or c'est
un nom générique qu'un marchand peut légitimement réutiliser pour une règle sans rapport (une
catégorie « outlet », par exemple). La sentinelle mettait alors tout le module en veille, **jusqu'à
désenregistrer le statut « Précommande »** — et les commandes concernées disparaissaient des écrans
d'administration. Elle a été retirée en 0.6.2.

Corollaire : quand un filtre d'affichage du marchand coexiste avec le module, les deux s'appliquent.
Le nôtre est enregistré plus tard — WPCode exécute ses snippets sur `plugins_loaded` en priorité 5, le
plugin démarre en 20 — donc il passe en second et l'emporte sur les articles précommandés.

L'avertissement d'administration et le panneau de diagnostic **nomment** les fonctions détectées.
Sans cela, le marchand doit deviner lequel de ses snippets bloque le module.

Ordre à respecter : **mettre à jour le plugin d'abord**, vérifier le diagnostic dans les réglages,
**puis** désactiver les snippets. Retour arrière : les réactiver, le module se remet en veille au
chargement suivant.

## Fournisseurs

Le marchand travaille en flux tendu avec plusieurs fournisseurs, et son geste réel est fournisseur par
fournisseur. La page « Besoins pour commande » a donc un onglet « Général », un onglet par fournisseur, et un
onglet « Sans fournisseur ».

### Une taxonomie, `rsmw_supplier`

Non hiérarchique, attachée à **`product` seulement**, `show_ui => true` mais `show_in_menu => false` :
l'écran natif `edit-tags.php` fournit tout le CRUD, sans apparaître sous « Produits ». Un
`add_submenu_page( 'woocommerce', … )` le place sous le menu de l'extension, et les filtres
`parent_file` / `submenu_file` empêchent WordPress d'ouvrir le menu Produits à la place.

Deux raisons l'emportent sur un type de contenu :

- le CRUD est **déjà écrit par WordPress**. Le contre-exemple chiffre l'alternative : la classe de
  livraison de WooCommerce a `show_ui => false`, et il a fallu lui réécrire tout un écran en Backbone ;
- `wp_delete_term()` **détache proprement chaque produit**. Un type de contenu laisserait des
  identifiants pointant dans le vide sur des centaines de produits.

`product_brand` a été écarté malgré la tentation : un distributeur livre plusieurs marques, et une
marque arrive parfois par deux distributeurs.

### Variations : remontée au parent

Une « référence » de la table des besoins est l'ID de la **variation** quand la ligne en porte une
(`Items::key()`). Or une variation ne porte pas de terme. `Suppliers\Resolver` remonte donc au parent —
patron de `WC_Product_Variation::get_shipping_class_id()`. Conséquence assumée : **toutes les
déclinaisons d'un produit partagent son fournisseur.**

**Une requête pour toute la page.** L'ID parent était déjà sélectionné par la requête de demande sous
l'alias `pid`, puis jeté ; `Demand::map()` le reporte maintenant sous la clé `parent`, et le résolveur
fait un unique `wp_get_object_terms( …, 'all_with_object_id' )`.

> Ne jamais passer par `wc_get_product_terms()` ici : il retombe sur `wp_get_post_terms()` et coûte
> **une requête par produit** — invisible sur une fiche, ruineux sur un tableau de centaines de lignes.

### L'onglet « Sans fournisseur » n'est pas décoratif

Au démarrage, 100 % des références y sont. Et si le marchand travaille fournisseur par fournisseur,
une référence sans terme **ne serait dans aucun onglet** : il ne la commanderait jamais. Il est donc en
dernier, compteur en rouge, et **masqué dès qu'il est vide** — sa disparition signale que le catalogue
est cartographié. L'onglet « Général » porte en renfort une colonne Fournisseur, et le nom du
fournisseur entre dans `data-search`, ce qui permet de filtrer par fournisseur à la recherche.

Les compteurs comptent les références à `manque > 0`, et non toutes celles du fournisseur : la case
« Manquants uniquement » étant cochée d'entrée, c'est exactement ce que le marchand verra en ouvrant
l'onglet. **Invariant, et test de recette** : somme des onglets fournisseurs + « Sans fournisseur » =
compteur de « Général ».

### Passer la commande depuis l'onglet

C'est le vrai besoin derrière « pouvoir passer les commandes plus facilement ». Enregistrer une
commande fournisseur imposait de passer par *Gestion stock → Mouvement à l'unité*, **une référence à la
fois** : quinze allers-retours pour quinze lignes. En pratique le marchand ne le faisait pas — et comme
il ne le faisait pas, `manque` ne baissait jamais et **la page lui redemandait chaque semaine de
commander ce qu'il avait déjà commandé.**

`Preparation\Purchase` est l'image miroir de `Reception` : saisie par ligne préremplie avec le manque,
vérification avant écriture, puis un seul enregistrement qui délègue à
`Allocator::order_from_supplier()`. Une commande fournisseur ne fait jamais basculer une commande
client en « À empaqueter » : la marchandise n'est pas arrivée.

**Prérequis** : `Pages::register()` accroche désormais `NeedsPage::handle_post` sur `load-{écran}`.
La page traitait ses POST dans `render()`, donc après l'envoi de l'en-tête — aucune redirection
possible. Tolérable pour une réaffectation, inacceptable pour « j'ai commandé douze articles », qu'un
F5 aurait enregistré deux fois.

### Filtrer la réception par fournisseur

L'onglet « Réception d'un colis » porte un sélecteur « Colis reçu de ». Trois décisions :

- **le filtre survit à la redirection** (`StockPage::redirect()`). Sans cela, le marchand qui vient de
  pointer un colis retomberait sur la liste complète, et la réapparition des autres références lui
  ferait croire que rien n'a été enregistré ;
- **le sélecteur ne liste que les fournisseurs qui attendent quelque chose**, avec le compteur. Il
  répond à « de qui ce colis peut-il venir ? », pas à « qui est-ce que je connais ? ». « Sans
  fournisseur » y figure dès qu'une référence non affectée est attendue — sinon elle ne serait
  réceptionnable par aucun filtre ;
- **c'est un formulaire GET autonome**, posé hors du formulaire de réception : deux formulaires ne
  peuvent pas s'imbriquer, et celui de saisie enveloppe déjà tout le tableau. Il marche sans
  JavaScript ; le script ne fait que le soumettre au changement.

### Deux slugs réservés

`general` et `sans-fournisseur` désignent les filtres du plugin dans l'URL. Un fournisseur nommé
« Général » ou « Sans fournisseur » produirait exactement le même slug — et ses références
deviendraient invisibles dans son propre onglet, derrière un compteur qui les annonce pourtant.

`Suppliers\Taxonomy::reserve_slugs()` règle le conflit **là où il naît**, sur le filtre
`wp_unique_term_slug`, plutôt que de l'arbitrer à la lecture dans chaque écran. Toute nouvelle
constante de filtre adossée à un slug de fournisseur doit être ajoutée à `RESERVED_SLUGS`.

### Contrat du JavaScript

`assets/js/needs-table.js` filtre, trie et exporte **côté client**, sur les `data-*` du `<tr>` — jamais
sur les cellules. Deux règles à respecter en touchant au gabarit :

- les clés `data-*` restent **un seul mot en minuscules**. `data-supplier-id` deviendrait
  `dataset.supplierId` et casserait la correspondance avec `th[data-key]`, sur laquelle repose le tri ;
- le script déréférence une douzaine d'identifiants **sans test de nullité**. Barre d'outils et
  `#mh-table` sont indissociables : quand il n'y a aucune ligne, le gabarit retire le bloc entier.

## Module « Besoins Back in Stock »

Confronte les inscriptions à une alerte de retour en stock — posées via l'extension tierce
« Back In Stock Notifier for WooCommerce » (ProPluginsLab) — au stock libre du module Préparation.
Page WooCommerce → Besoins Back in Stock : pour chaque référence demandée, combien d'unités le
stock libre (physique déjà en boutique, ou commandé au fournisseur et non attribué à une commande
client) permettrait d'honorer, combien il en manquerait, et le taux de couverture.

**Désactivé par défaut**, contrairement aux autres modules : il ne sert à rien sur une boutique qui
ne gère pas de liste d'attente de retour en stock.

### Une dépendance souple, jamais déclarée en dur

`src/BackInStock/Host.php` concentre tout ce que ce plugin sait de l'extension hôte — type de
contenu, statuts d'inscription, métadonnées, réglages — sur le modèle de `RSMW\Preparation\Cost`
avec « Cost of Goods for WooCommerce ». Aucune autre classe n'écrit `cwginstock`, `cwg_*` ou
`CWGINSTOCK_*` en dur, et rien n'appelle les classes `CWG_*` de l'hôte : la lecture se fait
exclusivement en base (`src/BackInStock/Demand.php`), ce qui permet à l'extension d'être absente,
inactive ou remplacée sans casser l'amorçage. `Host::is_active()` gouverne l'affichage de la page :
sans l'hôte, elle explique quoi installer plutôt que de rester vide sans explication.

### Le calcul, miroir de « Besoins pour commande »

`src/BackInStock/Coverage.php` calcule, référence par référence, le stock libre au-delà de ce que
réclament déjà les commandes clients en attente — exactement le miroir de la colonne « Manque » de
`NeedsPage::build_rows()` : là où elle retranche le stock du besoin client, ici on retranche le
besoin client du stock, pour ne jamais promettre à la liste d'attente une unité que le module
Préparation doit déjà à une commande. Le physique (`Stock`) est consommé avant le commandé
fournisseur (`Supply`), pour que les colonnes « Stock libre » et « À venir » restent lisibles
séparément.

Une inscription posée sur un produit variable pris dans son ensemble (réglage hôte
`variable_any_variation_backinstock`) n'a pas de stock qui lui soit propre : elle apparaît sur une
ligne « toutes déclinaisons », servie uniquement par ce que les variations laissent de côté après
leurs propres demandes. « Satisfait » (demande plafonnée au disponible) est la seule colonne de
couverture affichée : elle coïncide avec le disponible brut dès que celui-ci ne dépasse pas la
demande, le cas dominant sur cet écran.

### Ajouter du stock commandé depuis l'écran

Chaque ligne porte un champ de quantité, préchargé avec le manque, et un bouton « Valider la
commande » en pied de tableau. La saisie est déléguée telle quelle à
`RSMW\Preparation\Purchase::apply()` — la même classe que la commande fournisseur en lot de
« Besoins pour commande » — pour que l'ajout suive exactement les mêmes règles : le compteur
« commandé fournisseur » (`Supply`) monte, puis l'attribution FIFO sert d'abord les commandes
clients les plus anciennes encore non couvertes. Une ligne « toutes déclinaisons » n'a pas de champ :
commander au niveau du parent n'aurait pas de référence de stock à créditer, il faut viser la
déclinaison précise.

### Réglages

WooCommerce → Réglages → Stocks réels → Besoins Back in Stock : statuts d'inscription comptés comme
demande active (« En attente » et « Alerte envoyée » par défaut), et un panneau de diagnostic
(extension hôte détectée, champ quantité, suppression automatique des inscrits côté hôte, module
Préparation actif).

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

`Updater::register()` n'est en revanche appelé (`src/Plugin.php`) que si `is_admin()`,
`wp_doing_cron()` ou `WP_CLI` — jamais sur une requête boutique ordinaire. Charger et instancier
toute la bibliothèque à chaque vue de fiche produit ou de panier n'aurait servi à rien : seuls
l'écran Extensions et la vérification programmée par WP-Cron en tirent parti.

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
