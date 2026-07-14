{*
 * Fondouk — diagnostic panel (back-office).
 * Read-only, zero configuration: this screen only reflects the module's real state
 * (agent endpoints operational) and checks environment prerequisites.
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel">
  <h3><i class="icon-rss"></i> {l s="Fondouk — diagnostics" mod='fondouk'}</h3>

  {if $fondouk_pro_active}
    <div class="alert alert-info">
      <i class="icon-pause"></i>
      {l s="[1]Fondouk Pro is active[/1] — it serves the agent surface (manifest, catalog, MCP, llms.txt). This free module is on standby: it registers no routes to avoid a conflict. Uninstall or disable Fondouk Pro to hand the service back to this module." tags=['<strong>'] mod='fondouk'}
    </div>
  {else}
    <p class="help-block">
      {l s="Module [1]operational[/1]: the UCP manifest, the REST catalog API and the MCP server are being served. Read-only, zero configuration — nothing to set here." tags=['<strong>'] mod='fondouk'}
    </p>
  {/if}

  <h4>{l s="Agent endpoints (operational)" mod='fondouk'}</h4>
  <ul class="list-unstyled">
    <li>✅ <strong>{l s="UCP Manifest" mod='fondouk'}</strong>: <a href="{$fondouk_manifest_url|escape:'html':'UTF-8'}" target="_blank" rel="noreferrer">{$fondouk_manifest_url|escape:'html':'UTF-8'}</a></li>
    <li>✅ <strong>{l s="REST catalog" mod='fondouk'}</strong> (search / lookup / product): <code>{$fondouk_search_url|escape:'html':'UTF-8'}</code></li>
    <li>✅ <strong>MCP</strong> (JSON-RPC): <code>{$fondouk_mcp_url|escape:'html':'UTF-8'}</code></li>
    <li><i class="icon-file-text-o"></i> <a href="{$fondouk_llms_url|escape:'html':'UTF-8'}" target="_blank" rel="noreferrer">llms.txt</a></li>
  </ul>

  <h4>{l s="Environment checks" mod='fondouk'}</h4>
  <ul class="list-unstyled">
    <li>{if $fondouk_https}✅{else}⚠️{/if} <strong>HTTPS</strong>:
      {if $fondouk_https}{l s="yes." mod='fondouk'}{else}{l s="[1]NO[/1] — the UCP spec [2]requires[/2] HTTPS for the manifest. In production, serve the store over HTTPS: without it, no real agent can discover it." tags=['<strong>','<em>'] mod='fondouk'}{/if}
    </li>
    <li>{if $fondouk_rewriting}✅{else}⚠️{/if} <strong>{l s="URL rewriting (friendly URLs)" mod='fondouk'}</strong>:
      {if $fondouk_rewriting}{l s="enabled." mod='fondouk'}{else}{l s="[1]DISABLED[/1] — environment prerequisite: without it, the manifest [2]/.well-known/ucp[/2] is not routed. Enable \"Friendly URL\" in SEO & URLs settings." tags=['<strong>','<code>'] mod='fondouk'}{/if}
    </li>
    <li>{if !$fondouk_conflict}✅{else}⚠️{/if} <strong>{l s="Physical [1].well-known[/1] conflict" tags=['<code>'] mod='fondouk'}</strong>:
      {if $fondouk_conflict}{l s="a [1].well-known[/1] folder/file exists and may shadow the route (Apache serves the physical file). Static-file fallback is an explicit opt-in." tags=['<code>'] mod='fondouk'}{else}{l s="none." mod='fondouk'}{/if}
    </li>
    <li>{if $fondouk_autoload}✅{else}⚠️{/if} <strong>{l s="Dependencies ([1]vendor/autoload[/1])" tags=['<code>'] mod='fondouk'}</strong>:
      {if $fondouk_autoload}{l s="present." mod='fondouk'}{else}{l s="[1]MISSING[/1] — the Composer package is not installed; the endpoints would return a 500 error. Repackage the module ([2]composer install --no-dev[/2])." tags=['<strong>','<code>'] mod='fondouk'}{/if}
    </li>
  </ul>
</div>
