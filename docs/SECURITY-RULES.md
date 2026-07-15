# Règles de sécurité — modules Fondouk (exigences PrestaShop Addons)

La sécurité est éliminatoire sur la marketplace PrestaShop : chaque module est scanné.
Ces règles sont **obligatoires** et **appliquées automatiquement** par `build/check-security.sh`,
exécuté à chaque packaging (le zip n'est pas produit si une règle déterministe est violée).

## 1. Injection SQL — toute variable dans une requête doit être assainie

```php
// ✅ Chaîne
$sql = 'SELECT * FROM `'._DB_PREFIX_.'orders` WHERE `reference` = "'.pSQL($ref).'"';
// ✅ Entier
$sql = 'SELECT * FROM `'._DB_PREFIX_.'orders` WHERE `id_order` = '.(int) $id;
// ✅ Tableau d'entiers
$ids = array_map('intval', $ids);
$sql = '... WHERE `id_product` IN ('.implode(',', $ids).')';
// ✅ Nom de table / colonne
$sql = 'SELECT * FROM `'._DB_PREFIX_.bqSQL($table).'`';
// ✅ Float
$val = sprintf('%.6f', $float);
// ❌ INTERDIT — variable non assainie
$sql = '... WHERE `reference` = "'.$ref.'"';
```

Préférer `Db::getInstance()->insert()/update()` (échappement automatique) + `pSQL` sur les valeurs.

## 2. XSS — toute variable Smarty en sortie doit être échappée

```smarty
{* ✅ HTML *}      {$content|escape:'htmlall':'UTF-8'}
{* ✅ nombre *}    {$n|intval}   {$prix|floatval}
{* ✅ URL/href *}  {$url|escape:'html':'UTF-8'}
{* ✅ JS *}        var d = '{$data|escape:'javascript':'UTF-8'}';
{* ❌ INTERDIT *}  {$content}                {* variable nue *}
{* ❌ INTERDIT *}  {$content nofilter}       {* contourne l'échappement *}
```

Modificateurs autorisés en sortie : `escape`, `intval`, `floatval`. Tout le reste est refusé par le garde-fou.

## 3. Protection des fichiers

- Un `index.php` de redirection dans **chaque** dossier (généré par le packaging).
- Un `.htaccess` (deny listing) à la **racine** du module.

## 4. Fonctions interdites (RCE)

`eval`, `create_function`, `serialize`, `unserialize`, `system`, `exec`, `passthru`,
`shell_exec`, `proc_open`, `popen`. Utiliser **JSON** (`json_encode`/`json_decode`) à la place de serialize.

## 5. Superglobales & entrées

- Jamais `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE` bruts → toujours **`Tools::getValue()`**.
- `$_SERVER` (en-têtes) toléré, mais **casté/tronqué** avant tout usage, et `pSQL()` avant insertion en base.
- Aucune opération fichier (`include`, `file_get_contents`, `fopen`…) sur une entrée utilisateur.

## 6. Jetons / AJAX

- Actions **back-office** : protégées par le jeton du framework (`getAdminLink(..., true, ...)`).
- Contrôleurs **front** : endpoints agents **lecture seule, données publiques** — publics par design,
  aucune action modifiant l'état, donc pas de jeton (c'est un protocole ouvert). Ne jamais y ajouter
  d'écriture sans jeton + contrôle.

---

### Vérifier localement

```bash
./build/check-security.sh <dossier-module>
```

Sortie `✓ sécurité OK` = conforme. Une croix `✗` = violation bloquante (le packaging échoue).
L'avertissement `⚠ [revue manuelle]` liste les interpolations SQL à confirmer (non bloquant).
