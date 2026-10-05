<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\CurrentUser;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\Base\Registry\RegistryImportService;

/** Autor importovaného dokumentu Spisovny — `createdBy` (#93 D9). */
class RegistryImportServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    protected function tearDown(): void
    {
        CurrentUser::reset();
    }

    private function service(): RegistryImportService
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            // Uživatel 7 existuje; idempotenční dotaz nic nenajde.
            static fn (string $sql, mixed ...$params): ?array
                => ($params[0] ?? null) === 'core_system_users' && ($params[1] ?? null) === 7 ? ['id' => 7] : null,
        );

        $gateway = $this->createMock(TableGateway::class);
        $gateway->method('saveDocument')->willReturnCallback(function (array $data): DocumentResult {
            $this->saved = $data;
            return DocumentResult::ok(['id' => 31] + $data);
        });

        return new class ($db, new DocumentRegistry(), $gateway) extends RegistryImportService {
            public function __construct(
                DataSourceConnection $db,
                DocumentRegistry $registry,
                private readonly TableGateway $testGateway,
            ) {
                parent::__construct($db, $registry);
            }

            protected function buildGateway(): TableGateway
            {
                return $this->testGateway;
            }
        };
    }

    /** @return array<string, mixed> */
    private function body(array $extra = []): array
    {
        return [
            'docKind' => 'contract',
            'title'   => 'Smlouva o dílo',
            'created' => '2021-03-04T10:00:00+01:00',
            'legacy'  => ['ndx' => 12, 'author' => 'Jana Příkladová'],
        ] + $extra;
    }

    public function testCreatedByIsStoredAsRecordAuthor(): void
    {
        $result = $this->service()->import($this->body(['createdBy' => 7]));

        $this->assertTrue($result['ok']);
        $this->assertSame(7, $this->saved['created_by']);
        // Jméno ze starého systému zůstává v metadatech.
        $this->assertSame('Jana Příkladová', json_decode($this->saved['metadata'], true)['legacyAuthor']);
    }

    public function testMissingOrNullCreatedByKeepsExplicitNull(): void
    {
        // API klíč importu má svého uživatele — autorem záznamu být nesmí:
        // klíč `created_by` proto do gatewaye jde vždy, i jako null.
        CurrentUser::set(2);

        foreach ([[], ['createdBy' => null]] as $extra) {
            $this->saved = null;
            $result = $this->service()->import($this->body($extra));

            $this->assertTrue($result['ok']);
            $this->assertArrayHasKey('created_by', $this->saved);
            $this->assertNull($this->saved['created_by']);
        }
    }

    public function testUnknownOrMalformedCreatedByIsRejected(): void
    {
        foreach ([555, 0, -1, '7', 7.0] as $value) {
            $this->saved = null;
            $result = $this->service()->import($this->body(['createdBy' => $value]));

            $this->assertFalse($result['ok'], var_export($value, true));
            $this->assertSame(422, $result['statusCode']);
            $this->assertSame([['field' => 'createdBy', 'code' => 'user_not_found']], $result['details']);
            $this->assertNull($this->saved, 'nic se nesmí uložit');
        }
    }
}
