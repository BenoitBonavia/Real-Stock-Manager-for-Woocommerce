# TODO — Audit du système d'attribution / désattribution de stock réel

Ce fichier fait office de gestion de tickets en l'absence d'outil dédié.
Issu d'un audit exhaustif (13.09.2026) du moteur d'attribution de stock réel
aux commandes WooCommerce : 104 agents, 6 cartographes + 8 lentilles
d'invariants + double vérification adversariale par constat + critique de
complétude. 44 constats bruts, 36 confirmés après double réfutation, 5
constats supplémentaires de la critique de complétude, 8 écartés.

**Règle de tenue de ce fichier** : chaque chantier ci-dessous est supprimé du
fichier une fois **effectivement implémenté** (pas seulement planifié).

## Rappel du modèle

Trois compteurs par référence (product_id, ou variation_id si variation —
`Items::key()`) :

1. **Stock physique libre** — postmeta `_mh_stock_reel` (`Stock` class), plancher 0.
2. **Commandé fournisseur libre** — postmeta `_rsmw_stock_ordered` (`Supply` class), plancher 0.
3. Par ligne de commande (order_itemmeta) :
   - `_mh_prep_qty` = quantité pointée (`Items::prepared`)
   - `_mh_prep_from_stock` = part réellement prélevée sur le stock physique (`Items::from_stock`)
   - `_rsmw_prep_ordered` = part couverte par une commande fournisseur (`Items::ordered`)

Invariants visés : **I1** physique total = libre + Σ from_stock. **I2** fournisseur
total = libre + Σ ordered. **I3** prepared+ordered ≤ qty ligne. **I4** équité
FIFO réception / LIFO retrait. **I5** 100% pointé ⇒ statut `mh-empaqueter`.
**I6** stock libre ⇒ personne n'attend cette référence.

**Constat central** : `holder_order_ids()` = `Config::statuses()` +
`mh-empaqueter` (`Demand.php:90`). Tout ce qui sort de cette liste (annulation,
remboursement, corbeille, suppression, changement de réglage...) emporte son
`from_stock`/`ordered` sans restitution. **L'entrée dans le périmètre est
automatisée, la sortie ne l'est pas du tout.**

---

## FAIT — Points 1 à 5 (impact fort / effort faible)

Implémentés le 14.09.2026. Voir `git log` / diff pour le détail exact ; résumé
de ce qui a été livré pour référence future :

1. **Dépointage métabox + bascules manquées** (B2, B3) — `Ajax::handle()`
   enveloppe désormais ses écritures dans `Allocator::without_auto_allocation()` ;
   `StatusSync::sync()` n'est plus conditionné par « quelque chose a été pris
   cette passe » dans `maybe_auto_allocate()`, `receive()` et la première passe
   de `reallocate_all()`.
2. **Repli de statut arbitraire** (B1, critique) — `StatusSync::sync()`
   accepte tout statut existant dans `wc_get_order_statuses()` comme retour
   légitime (peu importe le périmètre courant), ne retombe sur
   `Config::DEFAULT_STATUSES[0]` qu'en dernier recours, efface la meta après
   usage et journalise en `Log::error` quand le repli s'est déclenché.
3. **Téléchargements révoqués** (D1) — `Preparation\OrderStatus` pose
   désormais le même filtre `woocommerce_order_is_download_permitted` que
   `PreOrder\OrderStatus`.
4. **Garde de périmètre/appartenance/capability sur le pointage AJAX** (C1,
   C2) — `Ajax::handle()` vérifie le statut de la commande, utilise
   `current_user_can('edit_shop_order', $order_id)`, résout la ligne via
   `get_item($item_id, false)` + vérifie son appartenance et son type, et le
   nonce est scopé par commande (`Ajax::nonce_action()`). La métabox passe en
   lecture seule hors périmètre.
5. **Retrait à l'unité sur-efface** (B4) — corrigé avec un design différent de
   la formulation littérale de l'audit (qui sur-corrigeait, voir le plan
   `lucky-snacking-honey.md` conservé pour référence) : `run_withdraw()`
   normalise `$prepared` sur `get_quantity()`, mais ne plafonne la mise au
   rebut (`Stock::adjust` négatif) qu'à hauteur de `$take` réellement demandé,
   laissant le surplus de rattrapage légitimement au libre.

## FAIT — Chantier 6, libération des compteurs à la sortie du périmètre

Implémenté le 14.09.2026 (`src/Preparation/Allocator.php`,
`src/Modules/OrderPreparation.php`). Deux méthodes privées partagées
(`release_item()`, `release_order()`), idempotentes, réutilisées par tous les
points d'entrée :

- **A1** — `release_if_out_of_scope()` sur `woocommerce_order_status_changed` :
  restitue le stock détenu dès qu'une commande sort du périmètre suivi
  (annulation, remboursement total, échec, statut retiré du réglage...).
- **A2** — `release_on_trash()` sur `woocommerce_trash_order` (hook
  indispensable et non redondant : vérifié dans le cœur WooCommerce, la mise
  à la corbeille NE déclenche PAS `woocommerce_order_status_changed`),
  `release_on_delete()` sur `woocommerce_before_delete_order` (suppression
  directe, sans passage par la corbeille), `release_on_delete_item()` sur
  `woocommerce_before_delete_order_item` (suppression d'une seule ligne).
- **A3** — `on_order_refunded()` sur `woocommerce_order_refunded` : réduit le
  pointage à hauteur du remboursement, traité en mise au rebut (pas de
  restitution automatique au libre — le hook ne dit pas si l'article a été
  physiquement repris). **Limite assumée, non résolue** : `get_quantity()`
  sur la ligne d'origine n'est jamais abaissée par WooCommerce après un
  remboursement, donc un repointage manuel ultérieur (bouton « + » de la
  métabox) peut remonter au-delà de ce qui est réellement encore dû. Corriger
  ce résidu demanderait de faire descendre une notion de « quantité
  effective » dans `Items::prepared()`, `order_is_ready()`, le clamp de
  `set_quantity()` et la requête SQL brute de `Demand::map()` — chantier à
  part entière, non traité ici.
- **A4** — `release_removed_statuses()` sur `update_option_rsmw_prep_statuses` :
  libère automatiquement les commandes des statuts retirés du réglage,
  bornée à 2000 commandes par sécurité (`Log::error` si le plafond est
  atteint).
- **A5** — `normalize_saved_items()` sur `woocommerce_saved_order_items` :
  reborne `_mh_prep_qty`/`_rsmw_prep_ordered` sur la nouvelle quantité d'une
  ligne éditée, et rappelle `StatusSync::sync()` (une ligne neuve incomplète
  ajoutée à une commande « À empaqueter » la redescend enfin).
- **A6** — `flag_completed_without_prep()` sur `woocommerce_order_status_changed` :
  signale seulement (note de commande + `Log::error`), **aucune écriture sur
  `Stock`** — décision produit actée avec l'utilisateur : le plugin ne
  suppose jamais qu'une commande a été expédiée en dehors de son propre
  système de pointage.

---

## FAIT — Lot 1 : chantiers 7 + 8 + 9

Implémenté le 14.09.2026 (`src/Preparation/Items.php`, `Allocator.php`,
`Demand.php`, `Inventory.php`, `src/Preparation/Admin/StockPage.php`,
`templates/preparation/inventory-page.php`, `valuation-kpis.php`). Deux
points retracés à la main s'écartent de la formulation littérale de
l'audit — détail conservé dans le plan `lucky-snacking-honey.md` :

- **Chantier 7 (B5, B6, B7)** — `Items::set_quantity()` gagne un paramètre
  `$supply_arrived` : la conversion commandé→préparé recrédite désormais
  `Supply` partout sauf dans `Allocator::receive()` (seul appelant où la
  marchandise est réellement arrivée). Le résidu de `receive()` puise
  d'abord dans le pool libre fournisseur PUIS, si besoin, directement dans
  les lignes détentrices via une nouvelle méthode partagée
  `reclaim_ordered_from_holders()` (réutilisée aussi par
  `cancel_supplier_order()`). Les 4 sites qui augmentent `ordered` exploitent
  désormais la valeur réellement écrite par `Items::set_ordered()`, avec
  `Log::error` si le bornage mord.
- **Chantier 8 (E1, C3, E4)** — `Demand::map()` calcule une nouvelle clé
  `detenu` (Σ `_mh_prep_from_stock`, scopée à `holder_order_ids()` donc
  « À empaqueter » comprises), utilisée par la colonne « Déjà attribué » de
  l'Inventaire. `read_inventory_input()`/`Inventory::apply()` préservent le
  signe de bout en bout au lieu de clamper à zéro en lecture (qui aurait
  silencieusement remis à 0 toute référence négative héritée non éditée à
  chaque enregistrement) ; `min="0"` retiré du gabarit, seul le plancher
  d'écriture de `Stock::set()`/`Supply::set()` fait foi. Le stock WooCommerce
  mutualisé entre variations n'est plus éditable que depuis la ligne qui le
  gère réellement (`get_stock_managed_by_id() === $id`), les autres l'affichent
  en lecture seule — supprime mécaniquement le bug d'écrasement mutuel.
- **Chantier 9 (E2, E3, unification)** — colonne Inventaire « Commandé »
  renommée « Commandé (non affecté) » avec infobulle ; KPI de valorisation
  renommés « … du stock libre » (option légère retenue plutôt que d'ajouter
  un troisième périmètre `Σ from_stock` à `Valuation::compute()`, pour ne pas
  charger l'onglet Réception d'une requête `Demand::map()` supplémentaire) ;
  colonne Inventaire « Stock réel » → « Stock libre », seule appellation
  activement trompeuse des trois relevées par l'audit.

---

## FAIT — Pointage borné au stock réel + purge des pointages gelés

Implémenté le 15.09.2026 (`src/Preparation/Items.php`, `Allocator.php`,
`Demand.php`, nouveau `src/Preparation/FrozenHolds.php`,
`src/Preparation/Admin/{Ajax,Metabox,NeedsPage}.php`,
`src/Modules/OrderPreparation.php`,
`templates/preparation/{metabox,needs-page}.php`,
`assets/js/preparation-metabox.js`). Suite directe du symptôme production
« commandes pointées sans stock disponible » (voir diagnostic du 15.09.2026,
16 agents). Deux correctifs distincts, demandés explicitement par
l'utilisateur, planifiés dans `lucky-snacking-honey.md` :

- **Bornage du pointage au stock physique libre** — `Items::set_quantity()`
  n'écrit plus la hausse demandée en entier : elle est désormais plafonnée par
  `Stock::get()` avant écriture, le retour (`delta`/`qty`) portant ce qui a
  réellement été appliqué. Inverse délibérément la règle documentée « pointer
  vaut entrée en stock implicite, jamais une dette » (toujours vraie pour une
  baisse). Seuls les deux appelants non bornés en amont (`Ajax::handle()`,
  boutons « Tout est prêt » et +/-) sont concrètement affectés ; les 7 autres
  appelants (`allocate_order()`, `receive()`, `reallocate_all()`...) étaient
  déjà bornés par `Stock::get()`/`Stock::free_map()` avant appel — trois d'entre
  eux exploitent désormais la valeur réellement écrite (`$applied`) avec
  `Log::error` si le bornage mord, même patron que le chantier 7 sur
  `set_ordered()`. `receive()` solde d'abord une dette héritée négative avant de
  créditer le colis reçu, sans quoi le bornage aurait fait disparaître les
  unités du colis. `Ajax::handle()` borne `$delta` à ±1 (seul contrat émis par
  l'interface) et renvoie un message explicite quand le stock manque ; la
  métabox désactive le bouton « + » à stock nul et pointe vers Mouvement à
  l'unité. Effet dérivé : `_mh_prep_from_stock` suit désormais exactement
  `_mh_prep_qty` pour toute donnée neuve, rendant I1 vrai par construction.
- **Purge des pointages gelés hérités d'avant le chantier 6** — nouvelle classe
  `FrozenHolds`, balayage par lots sur le patron de `PreOrder\Migration`
  (état + curseur, `admin_init`, une fois). Détecte par une requête directe sur
  l'itemmeta (identique sous HPOS et en stockage historique, visible jusque
  dans la corbeille) toute ligne encore pointée ou réservée fournisseur dont la
  commande n'est plus détentrice (`Demand::holder_order_ids()`), et la remet à
  zéro **sans rien recréditer** — décision utilisateur : restituer aurait pu
  doubler un stock déjà recompté à la main entre-temps. Rapport cumulé
  (références, commandes, unités), plafonné pour rester petit, affiché en carte
  sur « Besoins pour commande » avec bouton d'acquittement ; `Demand::map()`
  gagne une clé `pointe_detenu` (symétrique de `detenu`, sans repli d'absence)
  dont l'écart avec `detenu` mesure directement, en lecture seule, le volume
  encore pointé sans prélèvement correspondant sur les commandes actives.

**Correctif d'urgence le même jour (v3.6.1)** : en relisant le code pour
répondre à une question de l'utilisateur sur la purge ci-dessus, découvert que
`release_if_out_of_scope()` (chantier 6, v3.5.0, plus tôt le même jour)
restituait au stock libre la part préparée d'une commande **quel que soit le
statut de sortie** — y compris « Terminée », c'est-à-dire une commande
normalement expédiée. Chaque commande correctement préparée puis marquée
Terminée recréditait donc son stock comme si la marchandise n'était jamais
sortie : un bug actif, en production depuis le déploiement du matin, qui
fabrique du stock fantôme en continu (pas seulement sur l'historique). `Items`
préparé/prélevé n'est plus jamais restitué quand `$to === 'completed'` — seule
une éventuelle réserve fournisseur encore en attente l'est, cas résiduel
distinct. Corrigé sur les 5 points d'entrée de `release_order()`
(`release_if_out_of_scope`, `release_on_trash`, `release_on_delete`,
`release_on_delete_item`, `release_removed_statuses`) via un nouveau paramètre
`$keep_prepared` sur `release_item()`/`release_order()`. Effet de bord
corrigé au passage : `flag_completed_without_prep()` lisait `Items::prepared()`
*après* que `release_if_out_of_scope()` l'ait déjà remis à zéro sur le même
hook — il signalait donc à tort « jamais pointé » sur des commandes en réalité
entièrement préparées. **`FrozenHolds` n'est pas concerné** (aucun crédit de
stock, quel que soit le statut) mais reste, par la même logique, imprécis sur
une commande Terminée : il remet `_mh_prep_qty` à zéro au lieu de préserver la
trace historique de ce qui a été expédié — résidu mineur, non traité ici, sans
incidence sur l'exactitude du stock.

---

## À PLANIFIER — Chantiers restants, par impact/effort

### 7. [moyen/moyen] Réparation des négatifs hérités : la rendre conservative

- **Fichiers** : `src/Preparation/Admin/NeedsPage.php`, `templates/preparation/needs-page.php`

Le bouton "Remettre à zéro" (`NeedsPage.php:118-121`, `Stock::set(id, 0)` sur
chaque référence de `Stock::negative_ids()`) efface la dette négative héritée
du snippet mais laisse intact le CRÉDIT implicite porté par
`Items::from_stock()`, qui retourne `prepared()` quand `_mh_prep_from_stock`
est absent (repli documenté "COMPATIBILITÉ — ne pas simplifier"). Le couple
dette+crédit est cohérent TANT QUE le négatif survit ; le supprimer crée
exactement `|négatif|` unités, ensuite restituées au libre au premier
dépointage puis redistribuées à des commandes clients. Sur un parc de 50
références héritées à -3 en moyenne : 150 unités fantômes créées en un clic.

**Correctif proposé** : rendre la réparation conservative. Avant remise à
zéro, matérialiser la meta de repli sur les lignes détentrices
(`Demand::holder_order_ids()`), pour chaque ligne sans `_mh_prep_from_stock`
écrire explicitement `max(0, prepared - part de dette imputée)` en consommant
le négatif au fur et à mesure — de sorte que `Stock::get + Σ from_stock` soit
identique avant/après le clic. À défaut, afficher sur la carte le nombre
d'unités qui vont apparaître et exiger une confirmation explicite.

### 8. [moyen/moyen] Références supprimées : purge à la suppression et filtrage des orphelins

- **Fichiers** : `src/Modules/OrderPreparation.php`, `src/Preparation/Stock.php`, `src/Preparation/Supply.php`, `src/Preparation/Reception.php`, `src/Preparation/Purchase.php`, `src/Preparation/Inventory.php`, `src/Preparation/Admin/StockPage.php`

- **G1** (`OrderPreparation.php:101`) : passer un produit à variations en
  "produit simple" fait supprimer DÉFINITIVEMENT les variations par
  WooCommerce (`delete_variations()` → `wp_delete_post($id, true)`), qui
  efface toutes leurs postmeta — `_mh_stock_reel` et `_rsmw_stock_ordered` de
  TOUTES les variations disparaissent. Aucun hook produit n'existe dans le
  plugin (`before_delete_post`, `woocommerce_before_delete_product_variation` :
  zéro occurrence).
- **G2** (`Items.php:139`) : `update_post_meta()` ne vérifie pas l'existence
  du post. Un dépointage sur une référence supprimée RECRÉE un postmeta de
  stock orphelin — visible dans `Stock::free_map()` (pas de jointure
  `wp_posts`), jamais éditable (Inventaire et Mouvement à l'unité exigent
  `wc_get_product()` truthy), entretient en permanence la carte "N articles
  affectables" et fait basculer une commande en "À empaqueter" pour un
  article qui n'existe plus. Nouveau dépointage → recrée le fantôme → boucle
  sans fin.
- **G3** (`Inventory.php:33`) : un produit mis à la corbeille sort de
  l'onglet Inventaire (seul écran de correction en masse — statuts retenus
  publish/private/draft) mais reste compté dans `Valuation::compute()` et
  dans `Demand::allocatable_count()`, jusqu'au vidage automatique à 30 jours
  où le compteur s'évapore sans trace.
- **C5** (`Reception.php:363`, `Purchase.php:198`, `Inventory.php:231`) :
  ces trois normaliseurs de saisie castent la clé en entier sans jamais
  appeler `wc_get_product()` — contrairement à `StockPage::resolve_product()`
  qui, lui, valide. Un produit supprimé entre la génération de l'écran et la
  soumission du formulaire crée un postmeta orphelin sur un `post_id` mort.

**Correctif proposé** : accrocher `woocommerce_before_delete_product_variation`
et `before_delete_post` (filtré sur `product`/`product_variation`) pour
journaliser puis solder les compteurs avant destruction (router vers
`Allocator::withdraw()` pour dépointer proprement les lignes détentrices).
Filtrer `woocommerce_delete_variations_on_product_type_change` pour bloquer
ou avertir tant qu'une variation porte un compteur non nul. Ajouter
`wc_get_product()` dans les trois normaliseurs de saisie. Ajouter une
jointure `INNER JOIN wp_posts ... post_type IN ('product','product_variation')`
dans `Stock::free_map()`, `Supply::free_map()`, `Stock::negative_ids()`.

### 9. [moyen/fort] Atomicité des compteurs et idempotence des formulaires

- **Fichiers** : `src/Preparation/Stock.php`, `src/Preparation/Supply.php`, `src/Preparation/Allocator.php`, `src/Preparation/Admin/StockPage.php`, `src/Preparation/Journal.php`, `assets/js/reception.js`

- **F1** (`Stock.php:59`) : `Stock::adjust()`/`Supply::adjust()` sont des
  read-modify-write (get puis set) sans verrou. Deux paiements simultanés sur
  la dernière unité produisent deux commandes "À empaqueter" pour une seule
  unité réelle — le double plancher `max(0,...)` absorbe l'écart SANS laisser
  de négatif, donc invisible même de `Stock::negative_ids()` et du bouton de
  réparation.
- **F2** (`Allocator.php:197`) : `$in_progress` est une propriété STATIQUE DE
  PROCESSUS — zéro protection inter-requêtes. Un webhook de paiement dupliqué
  (IPN PayPal/Stripe classique, retour client + webhook simultanés) fait
  débiter le stock DEUX FOIS pour la même commande — preuve observable : deux
  notes de commande identiques. Le docbloc `Allocator.php:56` promet une
  idempotence que le code ne tient qu'en séquentiel.
- **F3** (`Reception.php:245`) : `Reception::apply()` n'est pas idempotente ;
  le nonce WordPress est rejouable 12h, le bouton n'est jamais désactivé côté
  JS, la redirection PRG n'intervient qu'APRÈS traitement complet de tout le
  colis. Un timeout PHP en milieu de colis (ex. 400 commandes actives × 15
  références = 6000 chargements de commande) laisse un état partiel sans
  compte rendu ; le réflexe de re-soumission double les références déjà
  reçues.
- **F4** (`Journal.php:50`) : `Journal::add()` est un read-modify-write sur
  une option UNIQUE (`get_option` puis `update_option`). Deux opérateurs
  validant au même instant peuvent voir une salve de 15 entrées écrasée par
  un mouvement concurrent d'une seule entrée — dernier écrivain gagne.
- **F5** (`Allocator.php:316`) : `receive()` est le SEUL des 11
  `wc_get_order()` du fichier sans test `instanceof \WC_Order` derrière — il
  est déréférencé aussitôt (`order_summary`, `add_order_note`,
  `StatusSync::sync`). Fenêtre étroite mais réelle (suppression concurrente,
  hooks tiers sur `rsmw_line_prepared` invalidant le cache commande entre les
  deux lectures).

**Correctif proposé** : remplacer `Stock::adjust()`/`Supply::adjust()` par un
`UPDATE ... SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d)`
atomique côté SQL, suivi de `wp_cache_delete()`, en faisant retourner la
quantité RÉELLEMENT appliquée. Remplacer la garde statique `$in_progress` par
un verrou inter-processus (`wp_cache_add()` sur cache objet persistant, ou
`GET_LOCK`/`RELEASE_LOCK` MySQL dans le `finally` existant). Ajouter un jeton
de soumission à usage unique sur les 4 formulaires d'écriture
(`rsmw_reception_submit`, `rsmw_stock_submit`, `rsmw_purchase_submit`,
`mh_prep_realloc`), désactiver le bouton au premier submit côté JS, faire
porter le `$batch` de réception par le formulaire plutôt que généré côté
serveur pour permettre une reprise. Verrouiller ou rendre append-only
`Journal::add()`.

### 10. [moyen/moyen] Rendre l'écart mesurable : diagnostic et réglages

- **Fichiers** : `src/Preparation/Demand.php`, `src/Modules/OrderPreparation.php`, `src/Admin/SettingsTab.php`, `src/Preparation/OrderStatus.php`

- **E5** (`Demand.php:226`) : `Config::cache_ttl()` peut valoir 0 (champ
  `min=0` sans avertissement) — transmis tel quel à `set_transient()`, ce qui
  pour WordPress signifie "PAS D'EXPIRATION". Régler 0 pour désactiver le
  cache produit un cache PERPÉTUEL, l'inverse de l'intention. Aucun code
  n'observe la sauvegarde du réglage "Statuts à préparer"
  (`update_option_rsmw_prep_statuses` : zéro occurrence) — changer le
  périmètre ne flush jamais le cache, et deux écrans peuvent afficher deux
  "reste à préparer" différents pour la même référence, l'un pouvant ne
  jamais se corriger tant qu'aucun événement de commande ne survient.
- **C6 (critique de complétude)** (`SettingsTab.php:115`) : le multiselect
  "Statuts à préparer" propose TOUS les statuts WooCommerce, y compris les
  statuts terminaux (`completed`, `cancelled`, `refunded`, `failed`), sans
  avertissement ni compteur de volumétrie. Sur une boutique à 32 000
  commandes `completed`, cocher ce statut génère des requêtes préparées à
  32 000 marqueurs à CHAQUE affichage des 3 écrans principaux (tous forcent
  `map(false)`), met les pages hors service (504), et fait pointer
  automatiquement des commandes déjà expédiées (stock décrémenté pour de la
  marchandise déjà partie). Aucune limite (`'limit' => -1` partout), aucun
  lot, aucun `set_time_limit`.
- Le panneau Diagnostic actuel ne compte que des volumes
  (`tracked_reference_count`, `prepared_line_count`, `ordered_line_count`) —
  jamais un déséquilibre. Asymétrie relevée en note :
  `Stock::tracked_reference_count()` compte tout (y compris les zéros) vs
  `Supply::tracked_reference_count()` filtre `> 0`, présentés côte à côte
  comme comparables.
- L'action groupée native WooCommerce "Marquer À empaqueter"
  (`OrderStatus.php:152`) bascule une commande sans pointer aucune ligne, sans
  écrire `_mh_prep_prev_status` — traité par vérification comme non
  bloquant en soi (voir Écartés ci-dessous) mais reste une ergonomie à
  surveiller une fois le chantier 1 en place (le repli `$actives[0]` devient
  alors la seule protection).

**Correctif proposé** : dans `Demand::map()`, ne mettre en cache que si
`$ttl > 0` (sortir sans `set_transient` sinon). Suffixer `Legacy::CACHE_KEY`
d'un hash court de `Config::statuses()` pour invalider mécaniquement au
changement de périmètre. Accrocher `update_option_{prefixe}prep_statuses` sur
`Demand::flush()` + la routine de libération du chantier 6. Dans
`SettingsTab`, exclure les statuts terminaux du multiselect ou suffixer
chaque libellé du nombre de commandes concernées (`wc_orders_count()`) avec
avertissement au-delà d'un seuil ; borner `Demand::active_order_ids()` et
`holder_order_ids()` par un filtre `rsmw_prep_max_orders` (défaut 2000) et
signaler toute troncature. Ajouter au panneau Diagnostic : total
`_mh_prep_from_stock` hors périmètre courant (mesure directe de la fuite I1),
équivalent pour I2, nombre de commandes terminées récemment au pointage
incomplet.

---

## Zones non instruites par l'audit (à auditer séparément si besoin)

- `src/Preparation/Cost.php` : mémoïsation statique de la source de coût
  (fige pour toute la requête même si un plugin active COGS après coup),
  héritage parent sur variation non vérifié, filtre `rsmw_product_cost` non
  documenté au README.
- `src/Preparation/Defects.php` : le docbloc promet "une remise à zéro est un
  geste explicite" mais aucun écran n'appelle `Defects::set()` — le compteur
  est en réalité monotone croissant, irréversible depuis l'interface.
- `src/Preparation/SnippetGuard.php` et `src/PreOrder/SnippetGuard.php` :
  décident si `OrderStatus::register()` est posé (`Plugin.php:117-127`). Un
  faux positif de détection du snippet rend invisible tout un pan de
  commandes `mh-empaqueter`.
- `src/Installer.php`, action `rsmw_upgrade`, `src/Updater.php` : aucun
  abonné à `rsmw_upgrade` n'existe — aucune migration n'a jamais matérialisé
  `_mh_prep_from_stock` sur les lignes héritées, le repli
  `Items::from_stock() → prepared()` est un état PERMANENT, pas transitoire.
- `src/Suppliers/Resolver.php` : `map_for()` reçoit les parents depuis
  `Demand::map()['parent']`, donc depuis un TRANSIENT — un transient d'une
  version antérieure n'a pas la clé, retombe sur `get_post()` ligne par
  ligne, annulant la promesse "une requête pour toute la page".
- `NeedsPage::count_by_supplier()` (`NeedsPage.php:317-344`) : un fournisseur
  dont le slug vaudrait `general` casserait le compteur et l'onglet
  "Général" (protégé à l'écriture par `Taxonomy::reserve_slugs`, jamais
  revalidé à la lecture) — invariant README "somme des onglets = compteur
  Général" jamais testé.
- `src/BackInStock/Coverage.php`, `src/BackInStock/Demand.php` : formules
  jamais auditées en détail — `available_breakdown()` avec un `Stock::get()`
  négatif hérité pourrait sur-soustraire.
- `Reception::simulate()` vs `Reception::apply()` : `simulate` charge la
  liste des commandes actives UNE fois, `apply` la relit à CHAQUE référence
  du colis (donc après les bascules déjà provoquées) — divergence de
  périmètre au sein d'un même enregistrement, jamais testée.
- `Purchase::simulate()` (`Purchase.php:68`) : `$uncovered` ne déduit pas
  `Stock::get()`, alors que `missing_for()` le déduit — deux colonnes de la
  même carte de vérification reposent sur deux définitions du besoin.
- Événements WooCommerce jamais instruits : `woocommerce_new_order_item` /
  `woocommerce_update_order_item` (ajout de ligne hors `saved_order_items`,
  ex. REST), `woocommerce_order_partially_refunded`,
  `woocommerce_payment_complete`, `woocommerce_cancel_unpaid_orders` (cron
  horaire — c'est lui qui transforme le repli `$actives[0]` en perte de
  commande, voir chantier 2), `wp_scheduled_delete` (vidage corbeille 30j).
- Non vérifiés faute de plugin installé : WooCommerce Subscriptions, point
  de vente, création de commande par API REST.
- Concurrence : les constats F1-F4 sont des raisonnements de code, jamais
  rejoués en conditions réelles (aucun harnais de test dans le dépôt).
- `Items::key()` sur une ligne non-produit (`WC_Order_Item_Fee`/`_Shipping`/
  `_Coupon`, qui n'ont pas `get_variation_id()`) : fatale PHP atteignable via
  le chemin du chantier 4 (C1) — corrigé par ce chantier, à revérifier après.

## Écarté après double vérification (ne pas re-soulever sans nouvel élément)

- **"Le plancher à 0 crée des unités sur un stock négatif"** (`Stock.php:59`) :
  réfuté — l'écrêtage est un one-shot borné par `|négatif|`, aucun écrivain du
  plugin ne peut produire un négatif (seul un état hérité du snippet le peut),
  et la valeur cible d'une référence négative EST zéro (le bouton de
  réparation fait exactement ça). Pas d'accumulation possible.
- **"`_mh_prep_from_stock` n'est affiché nulle part donc I1 est invérifiable"**
  (`Inventory.php:119`) : réfuté comme défaut de correction — I1 n'est
  l'invariant revendiqué par AUCUNE partie du code (Stock.php documente
  explicitement le compteur comme "LIBRE", jamais "physique total"). Reste
  une nuance de formulation dans un attribut `title` et un souhait
  d'observabilité — E1 (le vrai défaut : le périmètre `holder_order_ids`) a
  depuis été corrigé au lot « chantiers 7+8+9 ». **Caduc depuis la v3.6.0** :
  le pointage étant désormais borné au stock libre, `from_stock` suit
  exactement `prepared` pour toute donnée neuve — I1 devient vrai par
  construction, pas seulement par convention documentaire.
- **"L'action groupée 'Marquer À empaqueter' gèle définitivement une commande
  non pointée"** (`OrderStatus.php:152`) : réfuté — la métabox reste active
  sans condition de statut, un clic sur "+"/"Tout remettre à zéro" appelle
  `StatusSync::sync()` inconditionnellement (`Ajax.php:71`) et déclenche le
  repli `$actives[0]` puis la reprise automatique via
  `maybe_auto_allocate`. La commande n'est PAS invisible : `OrdersColumn`
  inclut explicitement `mh-empaqueter` dans les statuts suivis. Reste une
  suggestion d'ergonomie mineure, absorbée par les chantiers 1 et 2 [FAIT]
  (sync inconditionnel, repli de statut).
- **"`receive()` relit la commande sans vérifier l'instance"**
  (`Allocator.php:316`) : confirmé comme défaut mais traité dans le
  chantier 9 (atomicité et idempotence, F5) plutôt qu'en item séparé —
  fenêtre étroite, correctif trivial (aligner sur les 10 autres sites du
  fichier).
- **"'Déjà attribué' compte le pointé et non le prélevé, contredisant son
  infobulle"** (`inventory-page.php:103`) : réfuté — le mot "prélevé" est
  exact dans le modèle du plugin (pointer sans stock déclaré = entrée en
  stock implicite documentée comme "règle structurante"), et aucun écran ni
  doc ne promet l'arithmétique d'I1 sur cette colonne. E1 (le vrai défaut :
  le périmètre `active_order_ids()` au lieu de `holder_order_ids()`) a
  depuis été corrigé au lot « chantiers 7+8+9 ». **Prémisse disparue en
  v3.6.0** : l'entrée en stock implicite qui justifiait la nuance n'existe
  plus, le pointage étant désormais borné au stock libre.
- **"Le stock d'un produit passé en 'à variations' devient invisible et non
  corrigeable"** (`Inventory.php:52`) : réfuté sur ses deux jambes — corrigeable
  via "Mouvement à l'unité" (`resolve_product` accepte le SKU/ID du parent),
  et la contradiction "affectable mais inattribuable" ne tient pas
  (`allocatable_count` ne compte que s'il existe une ligne qui réclame
  vraiment la référence, auquel cas `reallocate_all` la sert effectivement).
  Résidu mineur (gêne ergonomique) absorbé par le chantier 8 (références
  supprimées, G1).
- **"Aucune borne haute sur les quantités de mouvement"** (`StockPage.php:536`) :
  réfuté — aucune rupture d'invariant (le système enregistre fidèlement une
  saisie fausse, il ne dérive pas), aucune borne haute n'est définissable sans
  arbitraire métier, le chemin est déjà verrouillé par `manage_woocommerce` +
  nonce, et la correction existe déjà (champ "libre"/"commande" de
  l'Inventaire).
- **"Le motif de retrait n'est jamais confronté à une liste blanche"**
  (`StockPage.php:551`) : réfuté — champ purement descriptif, n'entre dans
  aucun calcul de Stock/Supply/Items/StatusSync, aucune capability nouvelle
  franchie, pas de XSS (échappement correct partout). Remarque de rigueur
  défensive, pas un défaut.
