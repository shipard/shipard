<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\AttachmentController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Attachments\AttachmentGuard;

/** Guard, který zamyká každou přílohu — odmítnutí má skončit jako 409. */
final class RefuseAllAttachmentGuard implements AttachmentGuard
{
    public function __construct(DataSourceConnection $db) {}

    public function refuse(array $attachment, string $operation): ?string
    {
        return "Zamčeno ({$operation}).";
    }
}

/**
 * Příznak `send_with_record` v REST příloh (#94 D6): seznam ho vrací jako
 * bool, PATCH ho mění samostatně i s přejmenováním, odmítnutí guardem je
 * 409 `ATTACHMENT_LOCKED`.
 */
class AttachmentControllerSendFlagTest extends TestCase
{
    /** @var array<string, mixed> řádek přílohy, jak ho „drží databáze“ */
    private array $row;

    protected function setUp(): void
    {
        $this->row = [
            'id' => 7, 'table_id' => 501, 'record_id' => 12, 'name' => 'vykaz.pdf',
            'file_name' => 'vykaz-abc12.pdf', 'file_path' => '2026/10/03/docs_core_heads',
            'file_size' => 100, 'mime_type' => 'application/pdf', 'checksum' => 'x', 'metadata' => null,
            'att_order' => 1, 'is_deleted' => 0, 'send_with_record' => 0, 'created_by' => null,
        ];
    }

    /** @param array<string, list<class-string>> $guards */
    private function controller(array $guards = []): AttachmentController
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(fn (): array => $this->row);
        $db->method('fetchAll')->willReturnCallback(fn (): array => [$this->row]);
        $db->method('updateWhere')->willReturnCallback(function (string $table, array $data): void {
            $this->row = array_merge($this->row, $data);
        });

        $tables = ['docs_core_heads' => TableDefinition::fromArray([
            'tableId' => 501,
            'name'    => 'Documents',
            'columns' => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
        ])];

        return new AttachmentController($db, sys_get_temp_dir(), $tables, $guards);
    }

    /** @param array<string, mixed> $body */
    private static function patch(array $body): Request
    {
        return Request::fromArray('PATCH', '/_attachments/7', [], (string) json_encode($body), []);
    }

    /** @return array<string, mixed> */
    private static function payload(Response $response): array
    {
        return $response->getPayload();
    }

    private static function statusOf(Response $response): int
    {
        return (new \ReflectionProperty(Response::class, 'status'))->getValue($response);
    }

    public function testListReturnsSendFlagAsBool(): void
    {
        $this->row['send_with_record'] = 1;
        $request = Request::fromArray('GET', '/_attachments', ['table_id' => '501', 'record_id' => '12'], '', []);

        $payload = self::payload($this->controller()->list($request));

        $this->assertTrue($payload['data'][0]['send_with_record']);
    }

    public function testPatchSetsAndClearsTheFlag(): void
    {
        $controller = $this->controller();

        $response = $controller->patch(7, self::patch(['send_with_record' => true]));
        $this->assertSame(200, self::statusOf($response));
        $this->assertTrue(self::payload($response)['data']['send_with_record']);
        $this->assertSame('vykaz.pdf', $this->row['name'], 'samotný příznak název nemění');

        $response = $controller->patch(7, self::patch(['send_with_record' => false]));
        $this->assertFalse(self::payload($response)['data']['send_with_record']);
    }

    public function testPatchCombinesFlagWithRename(): void
    {
        $response = $this->controller()->patch(7, self::patch(['name' => 'priloha.pdf', 'send_with_record' => true]));

        $data = self::payload($response)['data'];
        $this->assertSame('priloha.pdf', $data['name']);
        $this->assertTrue($data['send_with_record']);
    }

    public function testPatchRejectsNonBooleanFlagWithoutAnyChange(): void
    {
        $response = $this->controller()->patch(7, self::patch(['name' => 'priloha.pdf', 'send_with_record' => 'yes']));

        $this->assertSame(422, self::statusOf($response));
        $this->assertSame('vykaz.pdf', $this->row['name'], 'neplatné tělo nesmí nechat změnu napůl');
        $this->assertSame(0, $this->row['send_with_record']);
    }

    public function testGuardRefusalIsConflictNotServerError(): void
    {
        $controller = $this->controller(['docs_core_heads' => [RefuseAllAttachmentGuard::class]]);

        foreach ([['send_with_record' => true], ['name' => 'jiny.pdf']] as $body) {
            $response = $controller->patch(7, self::patch($body));

            $this->assertSame(409, self::statusOf($response));
            $this->assertSame('ATTACHMENT_LOCKED', self::payload($response)['error']['code']);
        }
        $this->assertSame(0, $this->row['send_with_record']);
    }
}
