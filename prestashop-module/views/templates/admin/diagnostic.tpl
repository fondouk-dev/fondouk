{*
 * Fondouk — panneau de diagnostic (back-office).
 * Lecture seule, zéro configuration : cet écran ne fait que refléter l'état réel du
 * module (endpoints agent opérationnels) et vérifier les prérequis d'environnement.
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel">
  <h3><i class="icon-rss"></i> Fondouk — diagnostic</h3>

  <p class="help-block">
    Module <strong>opérationnel</strong> : le manifest UCP, l'API catalogue REST et le serveur MCP sont servis.
    Lecture seule, zéro configuration — aucun réglage à saisir ici.
  </p>

  <h4>Endpoints agent (opérationnels)</h4>
  <ul class="list-unstyled">
    <li>✅ <strong>Manifest UCP</strong> : <a href="{$fondouk_manifest_url|escape:'html':'UTF-8'}" target="_blank" rel="noreferrer">{$fondouk_manifest_url|escape:'html':'UTF-8'}</a></li>
    <li>✅ <strong>Catalogue REST</strong> (search / lookup / product) : <code>{$fondouk_search_url|escape:'html':'UTF-8'}</code></li>
    <li>✅ <strong>MCP</strong> (JSON-RPC) : <code>{$fondouk_mcp_url|escape:'html':'UTF-8'}</code></li>
    <li><i class="icon-file-text-o"></i> <a href="{$fondouk_llms_url|escape:'html':'UTF-8'}" target="_blank" rel="noreferrer">llms.txt</a></li>
  </ul>

  <h4>Vérifications d'environnement</h4>
  <ul class="list-unstyled">
    <li>{if $fondouk_https}✅{else}⚠️{/if} <strong>HTTPS</strong> :
      {if $fondouk_https}oui.{else}<strong>NON</strong> — le spec UCP <em>exige</em> HTTPS pour le manifest. En production, servez la boutique en HTTPS : sans cela, aucun agent réel ne pourra la découvrir.{/if}
    </li>
    <li>{if $fondouk_rewriting}✅{else}⚠️{/if} <strong>Réécriture d'URL (friendly URLs)</strong> :
      {if $fondouk_rewriting}activée.{else}<strong>DÉSACTIVÉE</strong> — prérequis d'environnement : sans elle, le manifest <code>/.well-known/ucp</code> n'est pas routé. Activez « URL simplifiées » dans Paramètres SEO &amp; URLs.{/if}
    </li>
    <li>{if !$fondouk_conflict}✅{else}⚠️{/if} <strong>Conflit <code>.well-known</code> physique</strong> :
      {if $fondouk_conflict}un dossier/fichier <code>.well-known</code> existe et peut masquer la route (Apache sert le fichier physique). Repli fichier statique = opt-in explicite (§4.2).{else}aucun.{/if}
    </li>
    <li>{if $fondouk_autoload}✅{else}⚠️{/if} <strong>Dépendances (<code>vendor/autoload</code>)</strong> :
      {if $fondouk_autoload}présentes.{else}<strong>ABSENTES</strong> — le paquet Composer n'est pas installé ; les endpoints renverraient une erreur 500. Re-packagez le module (<code>composer install --no-dev</code>).{/if}
    </li>
  </ul>
</div>
