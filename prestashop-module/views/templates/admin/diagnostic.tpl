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

  {if !$fondouk_pro_active}
  <h4>{l s="Trusted proxies (optional)" mod='fondouk'}</h4>
  <p class="help-block">{l s="Only if your store is behind a reverse proxy, a load balancer or a CDN that you control: list their addresses (IP or CIDR range, one per line). The rate limit then uses the visitor address they forward. Leave empty otherwise: forwarded addresses are never trusted by default." mod='fondouk'}</p>
  {if $fondouk_proxy_hint && !$fondouk_proxies}
    <div class="alert alert-warning">{l s="Agent requests reached the store through a proxy (connection from %s with an X-Forwarded-For header), but no trusted proxy is declared: all agents behind it share a single rate limit." sprintf=[$fondouk_proxy_hint|escape:'html':'UTF-8'] mod='fondouk'}</div>
  {/if}
  {if $fondouk_proxies_status == 'saved'}
    <div class="alert alert-success">{l s="Trusted proxies saved." mod='fondouk'}</div>
  {elseif $fondouk_proxies_status == 'invalid'}
    <div class="alert alert-danger">{l s="Nothing was saved: invalid entries (expected an IP address or a CIDR range no wider than /8 for IPv4 or /16 for IPv6):" mod='fondouk'} <code>{$fondouk_proxies_rejected|escape:'html':'UTF-8'}</code></div>
  {elseif $fondouk_proxies_status == 'forbidden'}
    <div class="alert alert-danger">{l s="You are not allowed to change this setting." mod='fondouk'}</div>
  {elseif $fondouk_proxies_status == 'token'}
    <div class="alert alert-danger">{l s="Invalid security token: reload the page and try again." mod='fondouk'}</div>
  {/if}
  <form method="post" action="{$fondouk_readiness_action|escape:'html':'UTF-8'}">
    <input type="hidden" name="fondouk_token" value="{$fondouk_readiness_token|escape:'html':'UTF-8'}">
    <textarea name="fondouk_trusted_proxies" rows="3" class="form-control" style="max-width:32em" placeholder="172.18.0.0/16">{$fondouk_proxies|escape:'html':'UTF-8'}</textarea>
    <button type="submit" name="fondouk_save_proxies" value="1" class="btn btn-default" style="margin-top:6px"><i class="icon-save"></i> {l s="Save" mod='fondouk'}</button>
  </form>
  {/if}

  <h4>{l s="Commerce Diagnostic — what agents read from your store" mod='fondouk'}</h4>
  {if $fondouk_pro_active}
    <p class="help-block">{l s="Fondouk Pro is active: run the diagnostic from the Fondouk Pro screen." mod='fondouk'}</p>
  {else}
    <p class="help-block">{l s="Checks, from inside your store, that agents can read it and that no reserved price, reserved product or hidden price is exposed to them. Read-only; run only when you click; nothing is stored." mod='fondouk'}</p>
    <form method="post" action="{$fondouk_readiness_action|escape:'html':'UTF-8'}">
      <input type="hidden" name="fondouk_token" value="{$fondouk_readiness_token|escape:'html':'UTF-8'}">
      <button type="submit" name="fondouk_run_readiness" value="1" class="btn btn-primary"><i class="icon-search"></i> {l s="Run the diagnostic" mod='fondouk'}</button>
    </form>

    {if $fondouk_readiness_error == 'forbidden'}
      <div class="alert alert-danger">{l s="You are not allowed to run the diagnostic." mod='fondouk'}</div>
    {elseif $fondouk_readiness_error == 'token'}
      <div class="alert alert-danger">{l s="Invalid security token: reload the page and try again." mod='fondouk'}</div>
    {elseif $fondouk_readiness_error == 'context'}
      <div class="alert alert-warning">{l s="Select a single shop (multistore context) to run the diagnostic." mod='fondouk'}</div>
    {elseif $fondouk_readiness_error == 'running'}
      <div class="alert alert-warning">{l s="A diagnostic is already running for this shop. Try again in a moment." mod='fondouk'}</div>
    {/if}

    {if $fondouk_readiness_report}
      <p>
        <strong>{l s="Result" mod='fondouk'}</strong> —
        PASS {$fondouk_readiness_report.run.totals.PASS|intval} ·
        WARN {$fondouk_readiness_report.run.totals.WARN|intval} ·
        FAIL {$fondouk_readiness_report.run.totals.FAIL|intval} ·
        NOT_TESTED {$fondouk_readiness_report.run.totals.NOT_TESTED|intval}
        <span class="help-block">{$fondouk_readiness_report.run.finished_at|escape:'html':'UTF-8'} · {$fondouk_readiness_report.context.base_url|escape:'html':'UTF-8'} · {$fondouk_readiness_report.context.language|escape:'html':'UTF-8'} / {$fondouk_readiness_report.context.currency|escape:'html':'UTF-8'} / {$fondouk_readiness_report.context.country|escape:'html':'UTF-8'}</span>
      </p>
      <table class="table">
        <thead><tr><th>{l s="Check" mod='fondouk'}</th><th>{l s="Status" mod='fondouk'}</th><th>{l s="Details and action" mod='fondouk'}</th></tr></thead>
        <tbody>
        {foreach from=$fondouk_readiness_rows item=row}
          <tr>
            <td><code>{$row.id|escape:'html':'UTF-8'}</code> {$row.title|escape:'html':'UTF-8'}</td>
            <td>
              {if $row.status == 'PASS'}<span class="badge badge-success">PASS</span>
              {elseif $row.status == 'WARN'}<span class="badge badge-warning">WARN</span>
              {elseif $row.status == 'FAIL'}<span class="badge badge-danger">FAIL</span>
              {else}<span class="badge">NOT_TESTED</span>{/if}
            </td>
            <td>
              {if $row.cause}<div>{$row.cause|escape:'html':'UTF-8'}</div>{/if}
              {if $row.action}<div><strong>{l s="Action:" mod='fondouk'}</strong> {$row.action|escape:'html':'UTF-8'}</div>{/if}
              {if $row.evidence}<details><summary>{l s="Evidence" mod='fondouk'}</summary><pre>{$row.evidence|escape:'html':'UTF-8'}</pre></details>{/if}
            </td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    {/if}
  {/if}
</div>
