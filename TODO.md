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

---

## À PLANIFIER — Chantiers restants, par impact/effort

### 6. [fort/fort] Libération des compteurs à la sortie du périmètre — LE CHANTIER STRUCTUREL

- **Fichiers** : `src/Preparation/Allocator.php`, `src/Modules/OrderPreparation.php`

Huit événements de cycle de vie (`trash`, `untrash`, `delete_order`,
`saved_order_items`, etc.) ne sont câblés que sur `Demand::flush` —
invalidation de cache, aucune restitution de stock. `before_delete_order`,
`before_delete_order_item`, `order_refunded`, `refund_created` ne sont câblés
nulle part.

- **A1 — Sortie du périmètre (annulé/remboursé/échec/on-hold retiré/corbeille)**
  (`Demand.php:90`) : le `from_stock` détenu n'est jamais restitué au libre.
  La commande quitte `active_order_ids()` ET `holder_order_ids()` → `withdraw()`
  et `cancel_supplier_order()` (qui bouclent sur `holder_order_ids()`) ne
  peuvent plus jamais le reprendre. `reallocate_all()` ne fait que descendre du
  libre vers les commandes, jamais remonter. Aggravation : si le marchand
  corrige l'Inventaire à la main pour compenser, écriture absolue sans reprise
  → double comptage au retour de la commande dans le périmètre.
- **A2 — Suppression définitive** (`OrderPreparation.php:109`) : WooCommerce
  détruit les order_itemmeta AVANT d'émettre `woocommerce_delete_order` — à ce
  moment `_mh_prep_from_stock` n'existe déjà plus. Perte sèche, aucune donnée
  en base ne permet un rattrapage automatique. Seules fenêtres où les metas
  sont encore lisibles : `woocommerce_before_delete_order` et
  `woocommerce_before_delete_order_item` (non câblés).
- **A3 — Remboursement partiel** (`OrderPreparation.php:101`) : aucun hook
  `woocommerce_order_refunded`/`woocommerce_refund_created`. La commande RESTE
  dans le périmètre (seul cas de toute la liste) et continue d'afficher 100%
  préparé pendant que le stock dérive en permanence, sans qu'aucun mécanisme
  de sortie ne puisse la rattraper.
- **A4 — Changement du réglage "Statuts à préparer"** (`Demand.php:60`) :
  aucun `update_option_*` observé. Retirer un statut gèle instantanément tout
  le stock détenu par le parc de commandes concerné (40 commandes × plusieurs
  unités chacune), sans la moindre transition, sans log, sans notice. Ajouter
  un statut fait entrer des commandes sans qu'aucun `maybe_auto_allocate` ne
  tourne pour elles (I6 rompu jusqu'au clic "Réaffecter").
- **A5 — Baisse de quantité / suppression de ligne** (`OrderPreparation.php:106`) :
  `woocommerce_saved_order_items` ne fait que `Demand::flush`. `_mh_prep_qty`
  n'est jamais reborné à la nouvelle quantité — c'est le prérequis exact du
  bug #5 ci-dessus (run_withdraw).
- **A6 — `completed` sans pointage préalable** (`Allocator.php:189`) : chemin
  dégradé mais fréquent (auto_allocate décoché, création API REST, emballage
  manuel puis clic direct "Terminée"). `_mh_stock_reel` reste inchangé alors
  que la marchandise est physiquement partie → sur-estimation permanente,
  redistribuée ensuite à d'autres commandes qui basculent "À empaqueter" sans
  marchandise réelle.

**Correctif proposé** : ajouter dans `Allocator::register()` une méthode
`release_order($order)` accrochée à `woocommerce_order_status_changed` prio 20 :
si `$from ∈ Config::statuses()+STATUS_SLUG` et `$to ∉` cet ensemble, parcourir
les lignes et restituer `Items::set_quantity($item, 0)` (restitue `from_stock`
au libre) + `Items::set_ordered($item, 0)` avec `Supply::adjust(+ordered)` —
sous `without_auto_allocation()`. Câbler la même routine sur
`woocommerce_trash_order`, `woocommerce_before_delete_order`,
`woocommerce_before_delete_order_item` (seules fenêtres où les metas sont
lisibles). Ajouter un handler `woocommerce_order_refunded` qui abaisse le
pointé de la quantité remboursée via `_refunded_item_id`. Ajouter une
normalisation sur `woocommerce_saved_order_items` qui reborne
`_mh_prep_qty`/`_mh_prep_from_stock` sur la nouvelle quantité et rappelle
`StatusSync::sync()`. Accrocher `update_option_{prefixe}prep_statuses` sur une
routine de libération pour les statuts retirés et sur une notice de
réaffectation pour les statuts ajoutés.

### 7. [fort/moyen] Rendre la conversion commandé→préparé symétrique

- **Fichiers** : `src/Preparation/Items.php`, `src/Preparation/Allocator.php`

- **B5** (`Items.php:162`) : `Items::set_quantity()` rabaisse
  `_rsmw_prep_ordered` quand le préparé monte (règle anti double-comptage) et
  retourne `$converted`, mais ne recrédite JAMAIS `Supply` du montant
  converti. Un seul appelant sur quatre exploite cette valeur de retour
  (`Allocator::receive()` ligne 305). Les trois autres — `allocate_order`
  (`:86`, déclenché automatiquement), `reallocate_all` passe 1 (`:822`),
  `Ajax::handle` (`:53`/`:65`) — la jettent. Rejoué : marchand commande 4 au
  fournisseur → FIFO sert la commande client (`Supply`=0, `ordered`=4,
  correct) → marchand retrouve 4 unités en réserve et les saisit dans
  l'Inventaire (`Stock::set`) → il clique "Tout est prêt" dans la métabox →
  `Ajax::handle` → `set_quantity` prend le stock, convertit `ordered` à 0,
  **jette `$converted`**. Résultat : `Supply`=0 ET Σ`ordered`=0 alors que 4
  unités sont TOUJOURS chez le fournisseur. Conséquences en cascade : la
  référence disparaît du tableau "Réception d'un colis" (`expected` calculé à
  0 → `continue`), et une nouvelle commande client de 4 déclenche une
  sur-commande fournisseur de 4 unités déjà payées et en transit.
- **B6** (`Allocator.php:346`) : dans `receive()`, `$residual = max(0, $qty -
  $converted)` puis `Supply::adjust(-$residual)` — le plancher à 0 écrête en
  silence quand une ligne couverte par du fournisseur n'a pas pu être servie
  faute de stock suffisant pour tout le monde (FIFO ASC sert d'abord une autre
  commande). Résultat rejoué à la main : `Supply`=0 après réception complète
  d'un colis, mais une ligne reste avec `ordered`=2 — la page Besoins calcule
  "manque=0" pour une commande réellement découverte de 2 unités, sans que le
  fournisseur n'ait plus rien en route.
- **B7** (`Items.php:88`) : `Items::set_ordered()` borne sur
  `room = quantity - prepared` sans jamais rendre l'écart à `Supply`, et sa
  valeur de retour (la quantité RÉELLEMENT écrite) n'est exploitée par AUCUN
  des 4 appelants. Rejoué : ligne à `ordered`=6 dont la quantité client a été
  réduite à 2 après coup (I3 déjà rompu silencieusement) ; annulation
  fournisseur de 1 unité → `set_ordered` rabote la ligne de 6 à 2 (perte de 4,
  pas 1) ; le compte rendu annonce "1 unité annulée".

**Correctif proposé** : ajouter un paramètre
`Items::set_quantity($item, $new_qty, bool $supply_arrived = false)`. Quand
`$supply_arrived` est faux et `$converted > 0`, appeler
`Supply::adjust($product_id, +$converted)` juste après la conversion — la
marchandise n'est pas arrivée, elle redevient du réassort libre. Seul
`Allocator::receive()` passe `true`. Remplacer
`Supply::adjust($product_id, -$residual)` (`receive()` ligne 346) par une
reprise qui puise d'abord dans le pool libre PUIS dans les
`_rsmw_prep_ordered` des lignes (sémantique de `cancel_supplier_order`, à
extraire en méthode privée partagée sans note ni log). Exploiter la valeur de
retour de `Items::set_ordered()` aux 4 sites d'appel
(`allocate_ordered_to_order:145-146`, `order_from_supplier:438-439`,
`cancel_supplier_order:551`, `reallocate_all:918-919`) : n'ajuster `Supply`
que de l'écart réellement écrit, journaliser en `Log::error` quand le
bornage mord.

### 8. [fort/moyen] Onglet Inventaire : mesurer le physique, borner les saisies, dédoublonner le stock WooCommerce

- **Fichiers** : `src/Preparation/Inventory.php`, `src/Preparation/Admin/StockPage.php`, `templates/preparation/inventory-page.php`

- **E1** (`Inventory.php:119`) : "Déjà attribué" lit `Demand::map(false)['pointe']`,
  qui n'agrège que `active_order_ids()` — donc PAS les commandes
  `mh-empaqueter`, celles qui détiennent le plus de stock. La colonne affiche
  "·" précisément à l'instant où une commande vient de se compléter. Le
  marchand qui compte physiquement son rayon (ex : 10 unités = 6 libres + 4
  dans un carton "À empaqueter") lit "Stock réel 0" (sic, colonne mal nommée,
  voir aussi ticket 9) + "Déjà attribué ·" = 6 au lieu de 10, et "corrige" à
  10 → double comptage immédiat, redistribué à une autre commande.
- **C3** (`StockPage.php:497`) : `read_inventory_input()` applique `absint()`
  aux deux champs — `absint()` retourne la VALEUR ABSOLUE, pas un écrêtage à
  zéro. Une valeur négative héritée postée devient positive. Et côté rendu,
  la valeur affichée n'est pas bornée (`value="-3"` avec `min="0"` sur
  l'input) : si la ligne est masquée par le filtre de recherche JS
  (`display:none` sans retrait du DOM), le navigateur ne peut rien focaliser
  et échoue en silence — toute la soumission (potentiellement 40 corrections)
  est perdue sans le moindre message.
- **E4** (`Inventory.php:155`) : pour une variation à stock mutualisé au
  niveau du parent, les N variations affichent/éditent le même compteur
  "Stock WooCommerce". `Inventory::apply()` boucle dans l'ordre du POST et
  écrit à chaque ligne : la correction ne "prend" que si le marchand édite la
  DERNIÈRE variation du produit dans l'ordre du tableau ; sinon les lignes
  suivantes écrasent la correction avec l'ancienne valeur affichée. Compte
  rendu "2 références mises à jour", valeur finale inchangée.

**Correctif proposé** : alimenter "Déjà attribué" depuis
`Demand::holder_order_ids()` (inclut `mh-empaqueter`) via une requête agrégée
dédiée `SUM(_mh_prep_from_stock)`, par ex. `Items::held_map()`. Remplacer
`absint()` par `max(0, (int) $values['libre'])` en lecture, et borner la
valeur rendue par `max(0, Stock::get($id))` (ou retirer `min="0"` de l'input).
Dans `Inventory::all_rows()`, ne marquer `woo_managed = true` que si
`get_stock_managed_by_id() === $id` ; pour les variations héritant du parent,
rendre en lecture seule. Dans `Inventory::apply()`, dédoublonner par
identifiant gérant le stock avant d'appeler `apply_woo_stock()`.

### 9. [moyen/faible] Aligner les définitions et libellés entre écrans

- **Fichiers** : `src/Preparation/Inventory.php`, `templates/preparation/inventory-page.php`, `src/Preparation/Valuation.php`, `templates/preparation/valuation-kpis.php`, `src/Preparation/Admin/NeedsPage.php`

- **E2** (`Inventory.php:114` vs `NeedsPage.php:232-233`) : "Commandé" =
  `Supply::get` SEUL sur Inventaire et fiche produit, mais
  `map['commande'] + Supply::get` (réservé + libre) sur Besoins, Réception et
  panneau Sélection. Quatre écrans disent 8, deux disent 4, sous des libellés
  interchangeables. Le champ de l'Inventaire est ÉDITABLE et écrit en
  ABSOLU : recopier le chiffre lu sur Besoins (8) dans l'Inventaire
  (`Supply::set(id, 8)`) laisse les 4 déjà réservées gravées sur leur ligne —
  "En commande" affiche ensuite 12 pour 8 unités réellement en route. Seule
  la fiche produit verbalise la distinction (`supply_hint`).
- **E3** (`Valuation.php:28`) : `Valuation::compute()` ne part que de
  `Stock::free_map()` + `Supply::free_map()` — le stock LIBRE seul. Une
  boutique qui a tout pointé (toutes commandes en "À empaqueter") affiche
  0,00 € sous "Valeur d'achat du stock", entrepôt plein. Aucune mention à
  l'écran ne le signale (contrairement à l'avertissement "Coût manquant sur N
  référence(s)" qui, lui, existe).

**Correctif proposé** : renommer la colonne Inventaire en "Commandé non
affecté" + reprendre la phrase d'aide de `ProductFields::supply_hint()`, ou
ajouter une colonne lecture seule "dont réservé". Ajouter à
`Valuation::compute()` un troisième périmètre alimenté par Σ
`_mh_prep_from_stock` sur `holder_order_ids()`, et afficher "dont affecté à
des commandes : X €" — ou a minima renommer le KPI en "Valeur du stock
libre". Unifier "Stock réel" / "Stock libre" / "Stock physique libre", qui
désignent tous `_mh_stock_reel`.

### 10. [moyen/moyen] Réparation des négatifs hérités : la rendre conservative

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

### 11. [moyen/moyen] Références supprimées : purge à la suppression et filtrage des orphelins

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

### 12. [moyen/fort] Atomicité des compteurs et idempotence des formulaires

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

### 13. [moyen/moyen] Rendre l'écart mesurable : diagnostic et réglages

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
  d'observabilité, traité par le chantier 8 (E1) qui porte sur autre chose de
  plus concret (le périmètre `holder_order_ids`).
- **"L'action groupée 'Marquer À empaqueter' gèle définitivement une commande
  non pointée"** (`OrderStatus.php:152`) : réfuté — la métabox reste active
  sans condition de statut, un clic sur "+"/"Tout remettre à zéro" appelle
  `StatusSync::sync()` inconditionnellement (`Ajax.php:71`) et déclenche le
  repli `$actives[0]` puis la reprise automatique via
  `maybe_auto_allocate`. La commande n'est PAS invisible : `OrdersColumn`
  inclut explicitement `mh-empaqueter` dans les statuts suivis. Reste une
  suggestion d'ergonomie mineure, absorbée par le chantier 2 (repli de
  statut) et le chantier 1 (sync inconditionnel).
- **"`receive()` relit la commande sans vérifier l'instance"**
  (`Allocator.php:316`) : confirmé comme défaut mais traité dans le
  chantier 12 (F5) plutôt qu'en item séparé — fenêtre étroite, correctif
  trivial (aligner sur les 10 autres sites du fichier).
- **"'Déjà attribué' compte le pointé et non le prélevé, contredisant son
  infobulle"** (`inventory-page.php:103`) : réfuté — le mot "prélevé" est
  exact dans le modèle du plugin (pointer sans stock déclaré = entrée en
  stock implicite documentée comme "règle structurante"), et aucun écran ni
  doc ne promet l'arithmétique d'I1 sur cette colonne. Absorbé par le
  chantier 8 (E1) qui porte sur le vrai défaut : le périmètre
  `active_order_ids()` au lieu de `holder_order_ids()`.
- **"Le stock d'un produit passé en 'à variations' devient invisible et non
  corrigeable"** (`Inventory.php:52`) : réfuté sur ses deux jambes — corrigeable
  via "Mouvement à l'unité" (`resolve_product` accepte le SKU/ID du parent),
  et la contradiction "affectable mais inattribuable" ne tient pas
  (`allocatable_count` ne compte que s'il existe une ligne qui réclame
  vraiment la référence, auquel cas `reallocate_all` la sert effectivement).
  Résidu mineur (gêne ergonomique) absorbé par le chantier 11 (G1).
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
