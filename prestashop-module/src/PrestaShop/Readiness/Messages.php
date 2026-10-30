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
 * Textes du diagnostic présentés au marchand (titre, cause, action corrective), traduits
 * par le module (EN, FR, AR). Chaque chaîne est écrite en clair dans un appel l() pour
 * l'extraction des traductions ; les textes rendus sont sans entités HTML (échappés à
 * l'affichage par le gabarit).
 */
final class Messages
{
    public function __construct(private readonly ?\Module $module = null)
    {
    }

    public function get(string $id, string ...$args): string
    {
        $text = $this->module !== null ? $this->translated($id) : $id;

        return html_entity_decode($args === [] ? $text : vsprintf($text, $args), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function translated(string $id): string
    {
        $m = $this->module;

        return match ($id) {
            'title_A1' => $m->l('HTTPS'),
            'title_A2' => $m->l('Friendly URLs'),
            'title_A3' => $m->l('No file hiding the manifest route'),
            'title_A4' => $m->l('Module dependencies'),
            'title_A5' => $m->l('Trusted proxies'),
            'title_B1' => $m->l('UCP manifest reachable and valid'),
            'title_B2' => $m->l('REST catalog answers'),
            'title_B3' => $m->l('MCP server answers'),
            'title_B4' => $m->l('llms.txt and robots.txt'),
            'title_C3-F' => $m->l('No reserved price exposed'),
            'title_C6-F' => $m->l('No reserved product exposed'),
            'title_C9-F' => $m->l('No hidden price exposed'),
            'title_D1-F' => $m->l('Data useful to agents'),
            'storefront_unreachable' => $m->l('The store could not be reached from the server (%s): this check was not run.'),
            'storefront_unreachable_action' => $m->l('Check that the store answers from the server itself (maintenance mode, HTTP authentication, firewall), then run the diagnostic again.'),
            'budget_exceeded' => $m->l('Time budget exceeded: this check was not run.'),
            'check_error' => $m->l('This check could not be completed.'),
            'a1_cause' => $m->l('The store is not served over HTTPS: UCP requires HTTPS, agents will not use it.'),
            'a1_action' => $m->l('Enable SSL on the whole store (Shop Parameters > General).'),
            'a2_cause' => $m->l('Friendly URLs are disabled: agent routes return 404.'),
            'a2_action' => $m->l('Shop Parameters > Traffic & SEO: enable Friendly URL, then regenerate the .htaccess file.'),
            'a3_file_cause' => $m->l('A physical file /.well-known/ucp hides the manifest served by the module.'),
            'a3_file_action' => $m->l('Delete or rename the file .well-known/ucp at the root of the store.'),
            'a3_dir_cause' => $m->l('A .well-known folder exists (often for SSL certificates). It is harmless with the standard .htaccess; check B1 confirms whether the manifest really answers.'),
            'a3_dir_action' => $m->l('Nothing to do if B1 passes.'),
            'a4_cause' => $m->l('Module dependencies are missing: the agent endpoints would fail.'),
            'a4_action' => $m->l('Reinstall the module from its official ZIP.'),
            'a5_cause' => $m->l('Agent requests reached the store through a proxy (connection from %s with an X-Forwarded-For header), but no trusted proxy is declared: all agents behind it share a single rate limit.'),
            'a5_action' => $m->l('In the module screen, declare the addresses of the proxies you control (reverse proxy, load balancer, CDN), then run the diagnostic again.'),
            'a5_none' => $m->l('No trusted proxy declared and no proxy detected. Detection is not exhaustive: if your store is behind a reverse proxy or a CDN, declare it in the module screen.'),
            'b1_unreadable' => $m->l('The manifest /.well-known/ucp does not answer with valid JSON.'),
            'b1_unreadable_action' => $m->l('Check checks A2 and A3, then clear the cache (Advanced Parameters > Performance).'),
            'b1_invalid' => $m->l('The manifest does not comply with UCP: %s.'),
            'b1_invalid_action' => $m->l('Update the module; if the problem persists, report it with this report.'),
            'b1_cache' => $m->l('The manifest is not cacheable by agents (public Cache-Control, max-age of 60 s or more expected).'),
            'b1_cache_action' => $m->l('Check that no server rule overrides the cache headers of /.well-known/ucp.'),
            'b2_search' => $m->l('The REST catalog search does not answer correctly.'),
            'b2_action' => $m->l('Clear the cache, check checks B1 and A2, then run the diagnostic again.'),
            'b2_prices_hidden' => $m->l('Your store hides prices from visitors: no product is served to agents, and they are told why. This is the expected behavior.'),
            'b2_prices_hidden_fail' => $m->l('Your store hides prices, but the catalog does not answer as expected (no product, prices_hidden message).'),
            'b2_empty' => $m->l('The catalog serves no product to agents, while %s products are visible in the store.'),
            'b2_no_product' => $m->l('No product is visible to anonymous visitors: agents have nothing to read.'),
            'b2_no_product_action' => $m->l('Check that products are active, visible and in categories open to visitors.'),
            'b2_lookup' => $m->l('Looking up a product by its identifier does not return it.'),
            'b2_not_found' => $m->l('An unknown identifier does not return the expected not_found error.'),
            'b3_tools' => $m->l('The MCP server does not list the three expected tools.'),
            'b3_call' => $m->l('A catalog search through MCP failed.'),
            'b3_mismatch' => $m->l('MCP and REST do not return the same products for the same search.'),
            'b4_llms' => $m->l('llms.txt is not served or does not point to the manifest.'),
            'b4_robots_all' => $m->l('robots.txt forbids the agent routes to every robot.'),
            'b4_robots_all_action' => $m->l('Remove the Disallow rules covering /.well-known/ucp and /fondouk/ucp/ from robots.txt.'),
            'b4_robots_families' => $m->l('robots.txt blocks these agent families: %s.'),
            'b4_robots_families_action' => $m->l('Keep the rule if the block is intended; otherwise remove it from robots.txt.'),
            'c3_prices_hidden' => $m->l('Prices are hidden from visitors: no price is served to agents.'),
            'c3_none' => $m->l('No reserved price (customer group, specific customer) in this store.'),
            'c3_leak' => $m->l('%s reserved price(s) are served to agents as if they were public.'),
            'c3_leak_action' => $m->l('Update the module immediately and report this report: a price reserved to a group or a customer must never be public.'),
            'c6_none' => $m->l('No reserved product (inactive, hidden or restricted) in this store.'),
            'c6_exposed' => $m->l('%s reserved product(s) are served to agents.'),
            'c6_action' => $m->l('Update the module immediately and report this report: a product hidden from visitors must never be exposed.'),
            'c6_distinguishable' => $m->l('Agents can tell a reserved product from an identifier that does not exist.'),
            'c9_exposed_shop' => $m->l('Your store hides prices from visitors, but products with prices are served to agents.'),
            'c9_action' => $m->l('Update the module immediately: a price hidden from visitors must never be exposed.'),
            'c9_shop_hidden' => $m->l('Your store hides prices from visitors: no product is served to agents.'),
            'c9_exposed_products' => $m->l('%s product(s) with a hidden price are served to agents.'),
            'c9_geolocation' => $m->l('Geolocation switches some countries to catalog mode: agents cannot be located reliably, they see prices like the default country.'),
            'c9_geolocation_action' => $m->l('If prices must stay hidden in some countries, hide them for all visitors.'),
            'd1_no_product' => $m->l('No product is served to agents: nothing to measure.'),
            'd1_warn' => $m->l('Products served to agents: %1$s with a zero price, %2$s without an image.'),
            'd1_action' => $m->l('Complete these products: agents skip products without a price or an image.'),
            default => $id,
        };
    }
}
