<?php
/**
 * Valide un document JSON contre un schéma UCP publié (release vendorée dans
 * tests/resources/ucp-2026-04-08). Résout les $refs par préfixe.
 *
 * Usage : php tools/validate-ucp.php <fichier.json> <schemaUri>
 * Ex.   : php tools/validate-ucp.php out.json 'https://ucp.dev/schemas/shopping/catalog_search.json#/$defs/search_response'
 */

require __DIR__ . '/../vendor/autoload.php';

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

$file = $argv[1] ?? null;
$schemaUri = $argv[2] ?? null;
if (!$file || !$schemaUri) {
    fwrite(STDERR, "usage: validate-ucp.php <file.json> <schemaUri>\n");
    exit(2);
}

$schemasDir = __DIR__ . '/../tests/resources/ucp-2026-04-08/schemas';
$validator = new Validator();
$validator->resolver()->registerPrefix('https://ucp.dev/schemas/', $schemasDir);

$data = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
$result = $validator->validate($data, $schemaUri);

if ($result->isValid()) {
    echo "VALID   $file\n        vs $schemaUri\n";
    exit(0);
}

echo "INVALID $file vs $schemaUri\n";
echo json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(1);
