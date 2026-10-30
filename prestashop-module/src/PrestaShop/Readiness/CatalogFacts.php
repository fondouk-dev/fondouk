<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShop\Module\Fondouk\PrestaShop\Readiness;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * S4 — la vérité de la boutique, en LECTURE SEULE et par requêtes bornées.
 *
 * Volontairement ÉCRIT À PART du filtre de PrestaShopCatalogSource : si ce filtre régresse,
 * le diagnostic doit le voir (recomparer une sortie aux fonctions qui l'ont produite serait
 * tautologique).
 */
final class CatalogFacts
{
    public function __construct(private readonly int $idShop, private readonly int $visitorGroup)
    {
    }

    /** Condition « un visiteur anonyme voit ce produit » (alias ps = product_shop, p = product). */
    public function visibleCondition(): string
    {
        return 'ps.active = 1 AND ps.visibility <> "none" AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp
            INNER JOIN `' . _DB_PREFIX_ . 'category_group` cg ON cg.id_category = cp.id_category AND cg.id_group = ' . $this->visitorGroup . '
            WHERE cp.id_product = p.id_product)';
    }

    /** Nombre de produits visibles dont le prix est affiché (donc servis aux agents). */
    public function servedCount(): int
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
            WHERE ' . $this->visibleCondition() . ' AND ps.show_price = 1');
    }

    /**
     * Produits qu'un visiteur anonyme ne voit PAS : inactifs, visibility = none, sans catégorie
     * accessible au groupe visiteur, ou absents de cette boutique (multiboutique).
     *
     * @return array<int,int>
     */
    public function nonPublicProductIds(int $limit): array
    {
        $rows = \Db::getInstance()->executeS('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
            WHERE ps.id_product IS NULL OR NOT (' . $this->visibleCondition() . ')
            ORDER BY p.id_product ASC LIMIT ' . (int) $limit) ?: [];

        return array_map(static fn (array $r) => (int) $r['id_product'], $rows);
    }

    /** @return array<int,int> produits visibles dont le prix est masqué au niveau produit */
    public function hiddenPriceProductIds(int $limit): array
    {
        $rows = \Db::getInstance()->executeS('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
            WHERE ' . $this->visibleCondition() . ' AND ps.show_price = 0
            ORDER BY p.id_product ASC LIMIT ' . (int) $limit) ?: [];

        return array_map(static fn (array $r) => (int) $r['id_product'], $rows);
    }

    /**
     * Produits visibles portant un prix RÉSERVÉ actif : prix spécifique d'un groupe autre que
     * le visiteur, ou d'un client précis.
     *
     * @return array<int,array{id_product:int,groups:array<int,int>,customers:array<int,int>}>
     */
    public function restrictedPriceProducts(int $limit): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = \Db::getInstance()->executeS('SELECT sp.id_product, sp.id_group, sp.id_customer FROM `' . _DB_PREFIX_ . 'specific_price` sp
            INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = sp.id_product
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
            WHERE ' . $this->visibleCondition() . ' AND ps.show_price = 1
              AND sp.id_shop IN (0, ' . $this->idShop . ')
              AND ((sp.id_group NOT IN (0, ' . $this->visitorGroup . ')) OR sp.id_customer > 0)
              AND (sp.`from` = "0000-00-00 00:00:00" OR sp.`from` <= "' . pSQL($now) . '")
              AND (sp.`to` = "0000-00-00 00:00:00" OR sp.`to` >= "' . pSQL($now) . '")
            ORDER BY sp.id_product ASC LIMIT ' . (int) ($limit * 5)) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id_product'];
            if (!isset($out[$id])) {
                if (count($out) >= $limit) {
                    break;
                }
                $out[$id] = ['id_product' => $id, 'groups' => [], 'customers' => []];
            }
            if ((int) $r['id_customer'] > 0) {
                $out[$id]['customers'][] = (int) $r['id_customer'];
            } elseif ((int) $r['id_group'] > 0) {
                $out[$id]['groups'][] = (int) $r['id_group'];
            }
        }
        foreach ($out as $id => $row) {
            $out[$id]['groups'] = array_values(array_unique(array_merge($row['groups'], $this->discountGroups())));
            $out[$id]['customers'] = array_values(array_unique($row['customers']));
        }

        return array_values($out);
    }

    /** @return array<int,int> groupes (hors visiteur) ayant une réduction de groupe */
    public function discountGroups(): array
    {
        static $cache = [];
        $key = $this->idShop . '-' . $this->visitorGroup;
        if (!isset($cache[$key])) {
            $rows = \Db::getInstance()->executeS('SELECT id_group FROM `' . _DB_PREFIX_ . 'group` WHERE reduction > 0 AND id_group <> ' . $this->visitorGroup . ' LIMIT 10') ?: [];
            $cache[$key] = array_map(static fn (array $r) => (int) $r['id_group'], $rows);
        }

        return $cache[$key];
    }

    /**
     * Agrégats de qualité des données sur les produits servis (une requête par indicateur).
     *
     * @return array<string,int>
     */
    public function qualityCounts(int $idLang): array
    {
        $db = \Db::getInstance();
        $from = ' FROM `' . _DB_PREFIX_ . 'product` p INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop
            . ' WHERE ' . $this->visibleCondition() . ' AND ps.show_price = 1';
        $home = (int) \Configuration::get('PS_HOME_CATEGORY');

        return [
            'served' => (int) $db->getValue('SELECT COUNT(*)' . $from),
            'no_image' => (int) $db->getValue('SELECT COUNT(*)' . $from . ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'image_shop` i WHERE i.id_product = p.id_product AND i.id_shop = ' . $this->idShop . ')'),
            'no_description' => (int) $db->getValue('SELECT COUNT(*)' . $from . ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_lang` pl WHERE pl.id_product = p.id_product AND pl.id_shop = ' . $this->idShop . ' AND pl.id_lang = ' . $idLang . ' AND (TRIM(pl.description_short) <> "" OR TRIM(pl.description) <> ""))'),
            'no_brand' => (int) $db->getValue('SELECT COUNT(*)' . $from . ' AND p.id_manufacturer = 0'),
            'no_gtin' => (int) $db->getValue('SELECT COUNT(*)' . $from . ' AND TRIM(p.ean13) = "" AND TRIM(p.upc) = "" AND TRIM(p.isbn) = ""'),
            'zero_price' => (int) $db->getValue('SELECT COUNT(*)' . $from . ' AND ps.price <= 0'),
            'home_category_only' => (int) $db->getValue('SELECT COUNT(*)' . $from . ' AND (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category_product` c2 WHERE c2.id_product = p.id_product AND c2.id_category <> ' . $home . ') = 0'),
        ];
    }
}
