<?php
/**
 * Fondouk — seed de catalogue de test (rejouable / idempotent).
 *
 * À exécuter DANS un conteneur PrestaShop :
 *   docker compose exec ps8 php -d memory_limit=-1 /seed/seed.php
 *   docker compose exec ps9 php -d memory_limit=-1 /seed/seed.php
 *
 * Couvre les cas de la spec (§7 TECHNICAL-SPEC) :
 *   - ~30+ produits, catégorie de démo dédiée
 *   - produits à déclinaisons (Taille × Couleur) + produits sans déclinaison
 *   - produit en rupture (stock 0, commande refusée)
 *   - specific_price PUBLIC (tous groupes)
 *   - specific_price restreint au groupe B2B (doit être ignoré pour l'anonyme)
 *   - produit visible UNIQUEMENT B2B (catégorie à accès restreint) → filtre produit
 *   - 2 langues actives (fr, en), 2 devises (EUR, USD)
 *
 * Idempotent : tous les objets créés portent une référence préfixée « AGT- » ;
 * ils sont supprimés puis recréés à chaque exécution. Les objets partagés
 * (langue EN, devise USD, groupe B2B, groupes d'attributs) sont find-or-create.
 */

define('_PS_ROOT_DIR_', '/var/www/html');
require_once _PS_ROOT_DIR_ . '/config/config.inc.php';

@set_time_limit(0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

$report = [];
function say($m) { echo '[seed] ' . $m . PHP_EOL; }

$idShop = (int) Context::getContext()->shop->id ?: 1;
Shop::setContext(Shop::CONTEXT_ALL);

// ---------------------------------------------------------------------------
// 1. Langues : garantir fr + en actives
// ---------------------------------------------------------------------------
$idFr = (int) Language::getIdByIso('fr');
if (!$idFr) {
    $lng = new Language();
    $lng->name = 'Français (French)';
    $lng->iso_code = 'fr';
    $lng->locale = 'fr-FR';
    $lng->language_code = 'fr';
    $lng->active = true;
    $lng->add(true, true);
    $idFr = (int) $lng->id;
}
$idEn = (int) Language::getIdByIso('en');
if (!$idEn) {
    $lng = new Language();
    $lng->name = 'English (English)';
    $lng->iso_code = 'en';
    $lng->locale = 'en-US';
    $lng->language_code = 'en-us';
    $lng->active = true;
    $lng->add(true, true); // only_add = true : pas de téléchargement de pack réseau
    $idEn = (int) $lng->id;
}
Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'lang` SET active = 1 WHERE id_lang IN (' . (int) $idFr . ',' . (int) $idEn . ')');
$report['langues'] = "fr=#$idFr, en=#$idEn";

// ---------------------------------------------------------------------------
// 2. Devises : garantir EUR + USD actives
// ---------------------------------------------------------------------------
$idEur = (int) Currency::getIdByIsoCode('EUR', 0);
if (!$idEur) {
    $idEur = (int) Configuration::get('PS_CURRENCY_DEFAULT');
}
$idUsd = (int) Currency::getIdByIsoCode('USD', 0);
if (!$idUsd) {
    try {
        $c = new Currency();
        $c->name = 'US Dollar';
        if (property_exists($c, 'names')) { $c->names = [$idFr => 'Dollar US', $idEn => 'US Dollar']; }
        $c->iso_code = 'USD';
        $c->numeric_iso_code = '840';
        $c->symbol = '$';
        $c->conversion_rate = 1.08;
        $c->precision = 2;
        $c->active = true;
        $c->add();
        $idUsd = (int) $c->id;
        Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'currency_shop` (id_currency, id_shop, conversion_rate) VALUES (' . (int) $idUsd . ',' . (int) $idShop . ',1.08)');
    } catch (Throwable $e) {
        say('USD non créé (' . $e->getMessage() . ') — multi-devise dégradé.');
        $idUsd = 0;
    }
}
$report['devises'] = "EUR=#$idEur, USD=#$idUsd";

// ---------------------------------------------------------------------------
// 3. Groupe client B2B (find-or-create)
// ---------------------------------------------------------------------------
$b2bId = 0;
foreach (Group::getGroups($idFr) as $g) {
    if (isset($g['name']) && $g['name'] === 'AGT B2B') { $b2bId = (int) $g['id_group']; break; }
}
if (!$b2bId) {
    $g = new Group();
    $g->name = [$idFr => 'AGT B2B', $idEn => 'AGT B2B'];
    $g->price_display_method = 1; // HT
    $g->show_prices = true;
    $g->add();
    $b2bId = (int) $g->id;
}
$visitorGroup = (int) Configuration::get('PS_UNIDENTIFIED_GROUP');
$report['groupe_b2b'] = "#$b2bId (visiteur anonyme=#$visitorGroup)";

// ---------------------------------------------------------------------------
// 4. Groupe de taxes par défaut (pour un TTC réaliste)
// ---------------------------------------------------------------------------
$idTaxGroup = 0;
$tg = Db::getInstance()->getRow('SELECT id_tax_rules_group FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE active = 1 ORDER BY id_tax_rules_group ASC');
if ($tg) { $idTaxGroup = (int) $tg['id_tax_rules_group']; }
$report['tax_group'] = "#$idTaxGroup";

// ---------------------------------------------------------------------------
// 5. Nettoyage idempotent : supprimer tout ce qui est préfixé AGT-
// ---------------------------------------------------------------------------
$old = Db::getInstance()->executeS('SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE reference LIKE "AGT-%"');
foreach ((array) $old as $row) {
    try { (new Product((int) $row['id_product']))->delete(); } catch (Throwable $e) {}
}
foreach (['AGT Démo', 'AGT B2B Réservé'] as $catName) {
    $rows = Db::getInstance()->executeS('SELECT DISTINCT cl.id_category FROM `' . _DB_PREFIX_ . 'category_lang` cl WHERE cl.name = "' . pSQL($catName) . '"');
    foreach ((array) $rows as $r) {
        try { (new Category((int) $r['id_category']))->delete(); } catch (Throwable $e) {}
    }
}
say('Nettoyage AGT- effectué (' . count((array) $old) . ' produits supprimés).');

// ---------------------------------------------------------------------------
// 6. Catégories
// ---------------------------------------------------------------------------
$rootCat = (int) Configuration::get('PS_HOME_CATEGORY');

function make_category($name, $parent, $idFr, $idEn, $groupsAccess = null) {
    $c = new Category();
    $c->name = [$idFr => $name, $idEn => $name];
    $c->link_rewrite = [$idFr => Tools::str2url($name), $idEn => Tools::str2url($name)];
    $c->id_parent = $parent;
    $c->active = true;
    if ($groupsAccess !== null) { $c->groupBox = $groupsAccess; }
    $c->add();
    return (int) $c->id;
}
$demoCat = make_category('AGT Démo', $rootCat, $idFr, $idEn);
$b2bCat = make_category('AGT B2B Réservé', $rootCat, $idFr, $idEn, [$b2bId]);
$report['categories'] = "demo=#$demoCat, b2b=#$b2bCat";

// ---------------------------------------------------------------------------
// 7. Attributs (Taille × Couleur), find-or-create
// ---------------------------------------------------------------------------
function find_or_create_attr_group($publicName, $idFr, $idEn) {
    $existing = Db::getInstance()->getRow('SELECT agl.id_attribute_group FROM `' . _DB_PREFIX_ . 'attribute_group_lang` agl WHERE agl.public_name = "' . pSQL($publicName) . '"');
    if ($existing) { return (int) $existing['id_attribute_group']; }
    $ag = new AttributeGroup();
    $ag->group_type = 'select';
    $ag->name = [$idFr => $publicName, $idEn => $publicName];
    $ag->public_name = [$idFr => $publicName, $idEn => $publicName];
    $ag->add();
    return (int) $ag->id;
}
function find_or_create_attr($idGroup, $nameFr, $nameEn, $idFr, $idEn) {
    $existing = Db::getInstance()->getRow('SELECT al.id_attribute FROM `' . _DB_PREFIX_ . 'attribute_lang` al INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = al.id_attribute WHERE a.id_attribute_group = ' . (int) $idGroup . ' AND al.name = "' . pSQL($nameFr) . '"');
    if ($existing) { return (int) $existing['id_attribute']; }
    $a = new Attribute();
    $a->id_attribute_group = $idGroup;
    $a->name = [$idFr => $nameFr, $idEn => $nameEn];
    $a->add();
    return (int) $a->id;
}
$agSize = find_or_create_attr_group('Taille', $idFr, $idEn);
$agColor = find_or_create_attr_group('Couleur', $idFr, $idEn);
$sizes = [
    'S' => find_or_create_attr($agSize, 'S', 'S', $idFr, $idEn),
    'M' => find_or_create_attr($agSize, 'M', 'M', $idFr, $idEn),
    'L' => find_or_create_attr($agSize, 'L', 'L', $idFr, $idEn),
];
$colors = [
    'Rouge' => find_or_create_attr($agColor, 'Rouge', 'Red', $idFr, $idEn),
    'Bleu' => find_or_create_attr($agColor, 'Bleu', 'Blue', $idFr, $idEn),
    'Vert' => find_or_create_attr($agColor, 'Vert', 'Green', $idFr, $idEn),
];

// ---------------------------------------------------------------------------
// 8. Fabrique de produits
// ---------------------------------------------------------------------------
function make_product(array $o, $idFr, $idEn, $idTaxGroup) {
    $p = new Product();
    $p->reference = $o['ref'];
    $p->price = $o['price'];
    $p->id_tax_rules_group = $idTaxGroup;
    $p->active = $o['active'] ?? true;
    $p->visibility = $o['visibility'] ?? 'both';
    $p->available_for_order = $o['available_for_order'] ?? true;
    $p->show_price = $o['show_price'] ?? true;
    $p->id_category_default = $o['category'];
    $slugFr = Tools::str2url($o['ref'] . '-' . $o['nameFr']);
    $slugEn = Tools::str2url($o['ref'] . '-' . $o['nameEn']);
    $p->name = [$idFr => $o['nameFr'], $idEn => $o['nameEn']];
    $p->description = [$idFr => $o['descFr'], $idEn => $o['descEn']];
    $p->description_short = [$idFr => $o['shortFr'], $idEn => $o['shortEn']];
    $p->link_rewrite = [$idFr => $slugFr, $idEn => $slugEn];
    $p->add();
    $p->addToCategories(array_values(array_unique([$o['category']])));
    return $p;
}

$created = 0;
$withCombos = 0;

// 8a. 20 produits simples publics
for ($i = 1; $i <= 20; $i++) {
    $ref = sprintf('AGT-%04d', $i);
    $p = make_product([
        'ref' => $ref, 'price' => 9.90 + $i, 'category' => $demoCat,
        'nameFr' => "Produit démo $i", 'nameEn' => "Demo product $i",
        'descFr' => "Description longue du produit démo $i.", 'descEn' => "Long description of demo product $i.",
        'shortFr' => "Résumé produit $i.", 'shortEn' => "Product $i summary.",
    ], $idFr, $idEn, $idTaxGroup);
    StockAvailable::setQuantity($p->id, 0, 50, $idShop);
    $created++;
}

// 8b. 6 produits à déclinaisons Taille × Couleur
for ($i = 21; $i <= 26; $i++) {
    $ref = sprintf('AGT-%04d', $i);
    $p = make_product([
        'ref' => $ref, 'price' => 24.90 + $i, 'category' => $demoCat,
        'nameFr' => "T-shirt démo $i", 'nameEn' => "Demo t-shirt $i",
        'descFr' => "T-shirt à déclinaisons taille et couleur, produit $i.", 'descEn' => "T-shirt with size and color variants, product $i.",
        'shortFr' => "T-shirt $i.", 'shortEn' => "T-shirt $i.",
    ], $idFr, $idEn, $idTaxGroup);
    $first = true;
    foreach (['S', 'M', 'L'] as $s) {
        foreach (['Rouge', 'Bleu', 'Vert'] as $col) {
            $comb = new Combination();
            $comb->id_product = (int) $p->id;
            $comb->reference = $ref . '-' . $s . '-' . substr($col, 0, 1);
            $comb->add();
            $comb->setAttributes([$sizes[$s], $colors[$col]]);
            if ($first) {
                Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute` SET default_on = 1 WHERE id_product_attribute = ' . (int) $comb->id);
                Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute_shop` SET default_on = 1 WHERE id_product_attribute = ' . (int) $comb->id);
                $first = false;
            }
            $qty = ($s === 'L' && $col === 'Vert') ? 0 : 20; // une combinaison en rupture
            StockAvailable::setQuantity($p->id, (int) $comb->id, $qty, $idShop);
        }
    }
    $created++;
    $withCombos++;
}

// 8c. produit SANS déclinaison, explicite
$p = make_product([
    'ref' => 'AGT-0027', 'price' => 49.00, 'category' => $demoCat,
    'nameFr' => 'Mug simple', 'nameEn' => 'Simple mug',
    'descFr' => 'Produit sans déclinaison.', 'descEn' => 'Product without variants.',
    'shortFr' => 'Mug.', 'shortEn' => 'Mug.',
], $idFr, $idEn, $idTaxGroup);
StockAvailable::setQuantity($p->id, 0, 100, $idShop);
$created++;

// 8d. produit EN RUPTURE, commande refusée
$p = make_product([
    'ref' => 'AGT-0028', 'price' => 15.50, 'category' => $demoCat,
    'nameFr' => 'Article en rupture', 'nameEn' => 'Out of stock item',
    'descFr' => 'Stock nul, commande en rupture refusée.', 'descEn' => 'Zero stock, backorder denied.',
    'shortFr' => 'Rupture.', 'shortEn' => 'Out of stock.',
], $idFr, $idEn, $idTaxGroup);
StockAvailable::setQuantity($p->id, 0, 0, $idShop);
StockAvailable::setProductOutOfStock((int) $p->id, 0); // 0 = refuser la vente en rupture
$created++;

// 8e. produit avec SPECIFIC PRICE PUBLIC -15%
$p = make_product([
    'ref' => 'AGT-0029', 'price' => 80.00, 'category' => $demoCat,
    'nameFr' => 'Promo publique', 'nameEn' => 'Public promo',
    'descFr' => 'Réduction catalogue visible par tous.', 'descEn' => 'Catalog discount visible to everyone.',
    'shortFr' => 'Promo.', 'shortEn' => 'Promo.',
], $idFr, $idEn, $idTaxGroup);
StockAvailable::setQuantity($p->id, 0, 30, $idShop);
$sp = new SpecificPrice();
$sp->id_product = (int) $p->id;
$sp->id_shop = 0; $sp->id_currency = 0; $sp->id_country = 0; $sp->id_group = 0; $sp->id_customer = 0;
$sp->id_product_attribute = 0; $sp->from_quantity = 1; $sp->price = -1;
$sp->reduction = 0.15; $sp->reduction_type = 'percentage'; $sp->reduction_tax = 1;
$sp->from = '0000-00-00 00:00:00'; $sp->to = '0000-00-00 00:00:00';
$sp->add();
$created++;

// 8f. produit avec SPECIFIC PRICE restreint B2B -30% (public = prix de base)
$p = make_product([
    'ref' => 'AGT-0030', 'price' => 120.00, 'category' => $demoCat,
    'nameFr' => 'Tarif B2B', 'nameEn' => 'B2B pricing',
    'descFr' => "Réduction réservée au groupe B2B (invisible pour l'anonyme).", 'descEn' => 'Discount restricted to B2B group (invisible to anonymous).',
    'shortFr' => 'B2B.', 'shortEn' => 'B2B.',
], $idFr, $idEn, $idTaxGroup);
StockAvailable::setQuantity($p->id, 0, 40, $idShop);
$sp = new SpecificPrice();
$sp->id_product = (int) $p->id;
$sp->id_shop = 0; $sp->id_currency = 0; $sp->id_country = 0; $sp->id_group = (int) $b2bId; $sp->id_customer = 0;
$sp->id_product_attribute = 0; $sp->from_quantity = 1; $sp->price = -1;
$sp->reduction = 0.30; $sp->reduction_type = 'percentage'; $sp->reduction_tax = 1;
$sp->from = '0000-00-00 00:00:00'; $sp->to = '0000-00-00 00:00:00';
$sp->add();
$created++;

// 8g. BONUS : produit visible UNIQUEMENT B2B (catégorie restreinte) → filtre produit
$p = make_product([
    'ref' => 'AGT-0031', 'price' => 300.00, 'category' => $b2bCat,
    'nameFr' => 'Produit réservé B2B', 'nameEn' => 'B2B only product',
    'descFr' => "Rattaché à une catégorie réservée B2B : l'anonyme ne doit pas le voir.", 'descEn' => 'Attached to a B2B-only category: anonymous must not see it.',
    'shortFr' => 'Réservé.', 'shortEn' => 'Restricted.',
], $idFr, $idEn, $idTaxGroup);
StockAvailable::setQuantity($p->id, 0, 10, $idShop);
$created++;

// ---------------------------------------------------------------------------
// 8h. Prérequis routing : activer les URL simplifiées + régénérer .htaccess
//     (le manifest /.well-known/ucp n'est routé que si la réécriture est active — §4.2)
// ---------------------------------------------------------------------------
Configuration::updateValue('PS_REWRITING_SETTINGS', 1);
if (method_exists('Tools', 'generateHtaccess')) {
    Tools::generateHtaccess();
}
$report['friendly_urls'] = 'activées + .htaccess régénéré';

// ---------------------------------------------------------------------------
// 9. Rapport
// ---------------------------------------------------------------------------
say('======== SEED TERMINÉ ========');
foreach ($report as $k => $v) { say(str_pad($k, 14) . ' : ' . $v); }
say('produits créés  : ' . $created . ' (dont ' . $withCombos . ' à déclinaisons)');
say('shop            : #' . $idShop);
