<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Contrat du rapport du Commerce Diagnostic (readiness-report.v1.json, tâche T-01) :
 * un rapport complet est valide ; un statut inconnu, un NOT_TESTED sans cause et plus de
 * 5 éléments de preuve sont refusés.
 */
final class ReadinessReportSchemaTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../schema/readiness-report.v1.json';

    /** @return array<string,mixed> */
    private function report(): array
    {
        return [
            'report_version' => '1',
            'run' => [
                'run_id' => 'r-20261021-0001',
                'started_at' => '2026-10-21T08:00:00+00:00',
                'finished_at' => '2026-10-21T08:00:04+00:00',
                'module' => ['name' => 'fondouk', 'version' => '2.0.0'],
                'prestashop_version' => '9.2.0',
                'protocols' => ['ucp' => '2026-04-08', 'mcp' => '2025-06-18'],
                'budget_s' => 20,
                'sample_size' => 20,
                'totals' => ['PASS' => 1, 'WARN' => 0, 'FAIL' => 0, 'NOT_TESTED' => 1],
            ],
            'context' => [
                'id_shop' => 1, 'base_url' => 'https://boutique.example', 'language' => 'fr', 'currency' => 'EUR',
                'country' => 'FR', 'visitor_group' => 1, 'tax_display' => 'tax_incl', 'catalog_mode' => false,
                'prices_visible' => true, 'theme' => 'hummingbird', 'storefront_path' => 'public',
            ],
            'results' => [
                [
                    'check_id' => 'A1', 'check_version' => '1', 'category' => 'environment', 'tier' => 'free',
                    'status' => 'PASS', 'severity' => 'high', 'evidence' => [['base_url' => 'https://boutique.example']],
                    'cause' => '', 'action' => '', 'duration_ms' => 0,
                ],
                [
                    'check_id' => 'B1', 'check_version' => '1', 'category' => 'protocol', 'tier' => 'free',
                    'status' => 'NOT_TESTED', 'severity' => 'high', 'evidence' => [],
                    'cause' => 'Storefront unreachable.', 'action' => 'Check the server.', 'not_tested_reason' => 'network', 'duration_ms' => 5003,
                ],
            ],
        ];
    }

    private function isValid(array $data): bool
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA), false, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode((string) json_encode($data), false, 512, JSON_THROW_ON_ERROR);

        return (new Validator())->validate($payload, $schema)->isValid();
    }

    public function testCompleteReportIsValid(): void
    {
        self::assertTrue($this->isValid($this->report()));
    }

    public function testUnknownStatusIsRejected(): void
    {
        $r = $this->report();
        $r['results'][0]['status'] = 'OK';
        self::assertFalse($this->isValid($r));
    }

    public function testNotTestedWithoutReasonIsRejected(): void
    {
        $r = $this->report();
        unset($r['results'][1]['not_tested_reason']);
        self::assertFalse($this->isValid($r));
    }

    public function testMoreThanFiveEvidenceItemsIsRejected(): void
    {
        $r = $this->report();
        $r['results'][0]['evidence'] = array_fill(0, 6, ['id' => '1']);
        self::assertFalse($this->isValid($r));
    }
}
