<?php

/*
 * Config php-cs-fixer du module Fondouk — reprend les règles du validateur Addons
 * PrestaShop via prestashop/php-dev-tools (require-dev).
 *
 * Neutralisation ciblée : `blank_line_after_opening_tag` est désactivée. Sur les stubs
 * index.php, cette règle (@Symfony) veut une ligne vide après <?php, ce qui entre en
 * CONFLIT direct avec le sniff Licenses de PrestaShop (PHP_CodeSniffer) qui EXIGE le
 * docbloc de licence COLLÉ à <?php (« no blank lines before the file comment »). Les
 * deux outils du même validateur se contredisent ; on tranche pour le sniff Licenses
 * (docbloc collé), qui est aussi le format des stubs index.php du core PrestaShop.
 *
 * Usage : composer install && vendor/bin/php-cs-fixer fix
 * (exclu du zip de soumission par build/package.sh — outillage de dev uniquement.)
 */

$rules = (new PrestaShop\CodingStandards\CsFixer\Config())->getRules();
$rules['blank_line_after_opening_tag'] = false;

$config = new PhpCsFixer\Config('Fondouk');
$config->setRiskyAllowed(true)->setRules($rules);
$config->getFinder()->in(__DIR__)->exclude('vendor');

return $config;
