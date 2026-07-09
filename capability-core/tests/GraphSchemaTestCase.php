<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Tests;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/** Base : charge le schéma v0 et fournit une assertion de validité. */
abstract class GraphSchemaTestCase extends TestCase
{
    protected const SCHEMA_PATH = __DIR__ . '/../schema/capability-graph.v0.json';

    protected function schema(): object
    {
        $json = file_get_contents(self::SCHEMA_PATH);
        self::assertNotFalse($json, 'Schéma introuvable : ' . self::SCHEMA_PATH);

        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /** @param mixed $data données déjà décodées (objets, pas tableaux associatifs) */
    protected function assertValidGraph(mixed $data, string $context = ''): void
    {
        $result = (new Validator())->validate($data, $this->schema());

        if (!$result->isValid()) {
            $errors = (new ErrorFormatter())->format($result->error());
            self::fail(
                'Graphe non conforme au schéma v0' . ($context !== '' ? " ($context)" : '') . ' : '
                . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        $this->addToAssertionCount(1);
    }
}
