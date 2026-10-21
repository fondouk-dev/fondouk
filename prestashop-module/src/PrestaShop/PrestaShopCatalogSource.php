<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShop\Module\Fondouk\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Fondouk\CapabilityCore\Contract\CatalogSource;
use Fondouk\CapabilityCore\Graph\Availability;
use Fondouk\CapabilityCore\Graph\CapabilityGraph;
use Fondouk\CapabilityCore\Graph\Money;
use Fondouk\CapabilityCore\Graph\OptionAxis;
use Fondouk\CapabilityCore\Graph\Product as GraphProduct;
use Fondouk\CapabilityCore\Graph\SelectedOptionValue;
use Fondouk\CapabilityCore\Graph\Subset;
use Fondouk\CapabilityCore\Graph\Variant as GraphVariant;
use Fondouk\CapabilityCore\Graph\VariantPrice;
use Fondouk\CapabilityCore\Query\GetProductQuery;
use Fondouk\CapabilityCore\Query\LookupQuery;
use Fondouk\CapabilityCore\Query\SearchQuery;

/**
 * Implémentation PrestaShop du port CatalogSource : introspection catalogue par les
 * classes internes (Product / Combination / StockAvailable / SpecificPrice), prix
 * calculés en CONTEXTE VISITEUR ANONYME.
 *
 * FILTRE DE SÉCURITÉ (spec §5) appliqué à TOUTES les méthodes : seuls les produits
 * qu'un visiteur anonyme verrait sont exposés — actifs, visibles, et accessibles au
 * groupe visiteur (PS_UNIDENTIFIED_GROUP). Les prix spécifiques réservés à un groupe
 * (B2B) ne s'appliquent jamais. Aucune quantité brute, aucune donnée client.
 *
 * PRIX MASQUÉS (TECHNICAL-SPEC, décision Q2) : si la vitrine ne montre pas le prix à un
 * visiteur anonyme (groupe visiteur sans affichage des prix, mode catalogue sans prix,
 * produit à show_price = 0), le produit reste public dans le graphe mais ses variants
 * n'ont aucun prix ; l'adapter UCP l'exclut, car UCP exige un prix. Jamais de prix nul
 * ou fictif à la place d'un prix masqué.
 */
final class PrestaShopCatalogSource implements CatalogSource
{
    private int $idShop;
    private int $idLang;
    private int $idCurrency;
    private int $idCountry;
    private int $visitorGroup;
    private string $langIso;
    private string $currencyIso;
    private string $countryIso;
    private int $precision;
    private string $gidBase;
    private string $baseUrl;
    private bool $pricesVisible;

    public function __construct(private readonly \Context $context, SearchLocale $locale)
    {
        // Jamais le client connecté (cookie) : groupe, panier et adresse sont ceux d'un visiteur anonyme.
        AnonymousContext::apply($this->context);

        $shop = $this->context->shop;
        $this->idShop = (int) $shop->id;
        $this->baseUrl = rtrim((string) $shop->getBaseURL(true, true), '/');
        $this->gidBase = (string) parse_url($this->baseUrl, PHP_URL_HOST);

        $this->idLang = $locale->idLang;
        $this->idCurrency = $locale->idCurrency;
        $this->idCountry = $locale->idCountry;
        $this->langIso = strtolower((string) \Language::getIsoById($this->idLang));
        $currency = new \Currency($this->idCurrency);
        $this->currencyIso = strtoupper((string) $currency->iso_code);
        $this->precision = (int) $currency->precision;
        $this->countryIso = strtoupper((string) \Country::getIsoById($this->idCountry));
        $this->visitorGroup = (int) \Configuration::get('PS_UNIDENTIFIED_GROUP');
        $this->pricesVisible = self::pricesVisibleToVisitors();

        // Contexte de présentation (devise/langue/pays fiscal) pour un calcul de prix cohérent.
        $this->context->currency = $currency;
        $this->context->language = new \Language($this->idLang);
        $this->context->country = new \Country($this->idCountry);
    }

    public function search(SearchQuery $query): CapabilityGraph
    {
        $limit = $query->pagination->clampedLimit();
        $offset = $this->cursorToOffset($query->pagination->cursor);

        $where = $this->publicWhere();
        if ($query->query !== null && trim($query->query) !== '') {
            $q = pSQL(trim($query->query));
            $where .= ' AND pl.name LIKE "%' . $q . '%"';
        }
        $categories = $query->filters['categories'] ?? null;
        if (is_array($categories) && $categories !== []) {
            $ids = implode(',', array_map('intval', $categories));
            $where .= ' AND EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cpf
                WHERE cpf.id_product = p.id_product AND cpf.id_category IN (' . $ids . '))';
        }

        $sql = 'SELECT DISTINCT p.id_product
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . '
            WHERE ' . $where . '
            ORDER BY p.id_product ASC
            LIMIT ' . (int) $offset . ', ' . ((int) $limit + 1);

        $rows = \Db::getInstance()->executeS($sql) ?: [];
        $hasNext = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $products = [];
        foreach ($rows as $row) {
            $p = $this->buildProduct((int) $row['id_product']);
            if ($p !== null) {
                $products[] = $p;
            }
        }

        $total = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT p.id_product)
             FROM ' . _DB_PREFIX_ . 'product p
             INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
             INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . '
             WHERE ' . $where
        );

        return $this->graph(
            $products,
            new Subset(Subset::SEARCH, $hasNext ? $this->offsetToCursor($offset + $limit) : null, $hasNext, $total)
        );
    }

    public function lookup(LookupQuery $query): CapabilityGraph
    {
        $products = [];
        foreach ($query->ids as $rawId) {
            [$idProduct] = $this->parseId($rawId);
            if ($idProduct === null || !$this->isPublic($idProduct)) {
                continue;
            }
            $p = $this->buildProduct($idProduct);
            if ($p !== null) {
                $products[] = $p;
            }
        }

        return $this->graph($products, new Subset(Subset::LOOKUP));
    }

    public function getProduct(GetProductQuery $query): ?CapabilityGraph
    {
        [$idProduct] = $this->parseId($query->id);
        if ($idProduct === null || !$this->isPublic($idProduct)) {
            return null;
        }
        $p = $this->buildProduct($idProduct);
        if ($p === null) {
            return null;
        }

        return $this->graph([$p], new Subset(Subset::PRODUCT));
    }

    // ------------------------------------------------------------------ filtre public

    /**
     * Clause SQL « visible par un visiteur anonyme » : actif, non caché, et rattaché à
     * au moins une catégorie accessible au groupe visiteur (exclut les produits B2B-only).
     */
    private function publicWhere(): string
    {
        return 'ps.active = 1
            AND ps.visibility != "none"
            AND EXISTS (
                SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cp
                INNER JOIN ' . _DB_PREFIX_ . 'category_group cg ON cg.id_category = cp.id_category
                WHERE cp.id_product = p.id_product AND cg.id_group = ' . $this->visitorGroup . '
            )';
    }

    /**
     * La vitrine montre-t-elle les prix à un visiteur anonyme de la boutique courante ?
     * Même règle que PrestaShop (ProductPresentationSettings::shouldShowPrice) : groupe
     * visiteur autorisé à voir les prix, et mode catalogue inactif ou « avec prix ».
     * La part propre à chaque produit (product_shop.show_price) est dans buildVariant().
     */
    public static function pricesVisibleToVisitors(): bool
    {
        if (\Group::isFeatureActive()) {
            $visitor = new \Group((int) \Configuration::get('PS_UNIDENTIFIED_GROUP'));
            if (!(bool) $visitor->show_prices) {
                return false;
            }
        }

        return !(bool) \Configuration::get('PS_CATALOG_MODE')
            || (bool) \Configuration::get('PS_CATALOG_MODE_WITH_PRICES');
    }

    /** Vrai si la boutique masque tous ses prix : l'adapter UCP ne servira aucun produit. */
    public function pricesHidden(): bool
    {
        return !$this->pricesVisible;
    }

    private function isPublic(int $idProduct): bool
    {
        $sql = 'SELECT COUNT(*)
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
            WHERE p.id_product = ' . (int) $idProduct . ' AND ' . $this->publicWhere();

        return (int) \Db::getInstance()->getValue($sql) > 0;
    }

    // ------------------------------------------------------------------ construction

    private function buildProduct(int $idProduct): ?GraphProduct
    {
        $product = new \Product($idProduct, false, $this->idLang, $this->idShop, $this->context);
        if (!\Validate::isLoadedObject($product)) {
            return null;
        }

        $handle = (string) $product->link_rewrite;
        $title = [$this->langIso => (string) $product->name];
        $descShort = trim(strip_tags((string) $product->description_short));
        $desc = $descShort !== '' ? $descShort : trim(strip_tags((string) $product->description));
        $url = (string) $this->context->link->getProductLink($product, null, null, null, $this->idLang, $this->idShop);

        $categoryIds = array_map('strval', $product->getCategories());

        $combinations = $product->getAttributeCombinations($this->idLang);

        $variants = [];
        $axes = [];
        if ($combinations) {
            $byIpa = [];
            foreach ($combinations as $c) {
                $byIpa[(int) $c['id_product_attribute']][] = $c;
            }
            foreach ($byIpa as $ipa => $rows) {
                $variants[] = $this->buildVariant($product, $ipa, $rows);
                foreach ($rows as $r) {
                    $group = (string) $r['group_name'];
                    $val = (string) $r['attribute_name'];
                    $axes[$group][$val] = true;
                }
            }
        } else {
            $variants[] = $this->buildVariant($product, 0, []);
        }

        $optionAxes = [];
        foreach ($axes as $group => $values) {
            $optionAxes[] = new OptionAxis(
                [$this->langIso => $group],
                array_map(fn (string $v) => [$this->langIso => $v], array_keys($values))
            );
        }

        return new GraphProduct(
            id: $this->gidProduct($idProduct),
            title: $title,
            public: true,
            variants: $variants,
            handle: $handle !== '' ? $handle : null,
            reference: (string) $product->reference !== '' ? (string) $product->reference : null,
            description: $desc !== '' ? [$this->langIso => $desc] : [],
            url: $url !== '' ? [$this->langIso => $url] : [],
            categoryIds: $categoryIds,
            optionAxes: $optionAxes,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows lignes de getAttributeCombinations pour cet ipa
     */
    private function buildVariant(\Product $product, int $ipa, array $rows): GraphVariant
    {
        $idProduct = (int) $product->id;

        // Prix masqué au visiteur (boutique ou produit) : variant sans prix (TECHNICAL-SPEC Q2).
        $shown = $this->pricesVisible && (bool) $product->show_price;
        $price = $shown ? $this->anonymousPrice($idProduct, $ipa, true) : null;
        $regular = $shown ? $this->anonymousPrice($idProduct, $ipa, false) : null;
        $prices = [];
        if ($price !== null) {
            $list = ($regular !== null && $regular->amountMinor > $price->amountMinor) ? $regular : null;
            $prices[] = new VariantPrice($price, $list, true, $this->countryIso);
        }

        $qty = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $ipa, $this->idShop);
        $availability = $this->availability($idProduct, $qty);

        $sku = null;
        $optionValues = [];
        $title = [];
        if ($rows) {
            $sku = (string) ($rows[0]['reference'] ?? '') ?: null;
            $labels = [];
            foreach ($rows as $r) {
                $optionValues[] = new SelectedOptionValue(
                    [$this->langIso => (string) $r['group_name']],
                    [$this->langIso => (string) $r['attribute_name']]
                );
                $labels[] = (string) $r['attribute_name'];
            }
            $title = [$this->langIso => implode(' / ', $labels)];
        } else {
            $sku = (string) $product->reference !== '' ? (string) $product->reference : null;
        }

        return new GraphVariant(
            id: $this->gidVariant($idProduct, $ipa),
            prices: $prices,
            availability: $availability,
            sku: $sku,
            title: $title,
            optionValues: $optionValues,
        );
    }

    /** Prix PUBLIC ANONYME en unités mineures. $withReduction=false → prix de référence (barré). */
    private function anonymousPrice(int $idProduct, int $ipa, bool $withReduction): ?Money
    {
        $specificOutput = null;
        $value = \Product::getPriceStatic(
            $idProduct,
            true,                 // usetax (TTC)
            $ipa ?: null,         // id_product_attribute
            $this->precision,     // decimals
            null,                 // divisor
            false,                // only_reduc
            $withReduction,       // usereduc
            1,                    // quantity
            false,                // force_associated_tax
            0,                    // id_customer = 0 (anonyme)
            null,                 // id_cart
            null,                 // id_address (le pays fiscal vient du contexte)
            $specificOutput,      // specific_price_output (par référence)
            true,                 // with_ecotax
            true,                 // use_group_reduction
            $this->context,
            false                 // use_customer_price = false (jamais de prix client)
        );

        if (!is_numeric($value)) {
            return null;
        }

        $minor = (int) round(((float) $value) * (10 ** $this->precision));

        return new Money($minor, $this->currencyIso);
    }

    private function availability(int $idProduct, int $qty): Availability
    {
        $oos = (int) \StockAvailable::outOfStock($idProduct);
        $allowOos = ($oos === 1) || ($oos === 2 && (bool) \Configuration::get('PS_ORDER_OUT_OF_STOCK'));
        $available = $qty > 0 || $allowOos;
        if ($qty > 0) {
            $status = Availability::IN_STOCK;
        } elseif ($allowOos) {
            $status = Availability::BACKORDER;
        } else {
            $status = Availability::OUT_OF_STOCK;
        }

        return new Availability($available, $status, $qty);
    }

    // ------------------------------------------------------------------ helpers

    private function graph(array $products, Subset $subset): CapabilityGraph
    {
        $shop = (new PrestaShopShopProfileSource($this->context))->shop();

        return new CapabilityGraph(
            shop: $shop,
            generatedAt: date('c'),
            products: $products,
            subset: $subset,
            capabilities: ['catalog.search', 'catalog.lookup'],
        );
    }

    private function gidProduct(int $id): string
    {
        return 'gid://' . $this->gidBase . '/' . $this->idShop . '/product/' . $id;
    }

    private function gidVariant(int $idProduct, int $ipa): string
    {
        return 'gid://' . $this->gidBase . '/' . $this->idShop . '/variant/' . $idProduct . '-' . $ipa;
    }

    /** @return array{0:?int,1:?int} [id_product, id_product_attribute] */
    private function parseId(string $raw): array
    {
        if (preg_match('#/product/(\d+)$#', $raw, $m)) {
            return [(int) $m[1], null];
        }
        if (preg_match('#/variant/(\d+)-(\d+)$#', $raw, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (ctype_digit($raw)) {
            return [(int) $raw, null];
        }

        return [null, null];
    }

    private function cursorToOffset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = json_decode((string) base64_decode($cursor, true), true);

        return is_array($decoded) && isset($decoded['o']) ? max(0, (int) $decoded['o']) : 0;
    }

    private function offsetToCursor(int $offset): string
    {
        return base64_encode((string) json_encode(['o' => $offset]));
    }
}
