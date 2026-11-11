<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Corrective;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Docs\Core\Corrective\CorrectiveDocController;
use Shipard\Module\Docs\Core\Corrective\CorrectiveDocException;
use Shipard\Module\Docs\Core\Corrective\CorrectiveDocService;

/**
 * HTTP obal akce Vystavit opravný doklad (#92 task B U8): vstup `{docId}`,
 * mapování CorrectiveDocException na 404 / 422 s kódem a detaily, výstup.
 */
class CorrectiveDocControllerTest extends TestCase
{
    /** @var list<int> */
    private array $calls = [];

    private function controller(?\Throwable $throw = null): CorrectiveDocController
    {
        $calls = &$this->calls;
        $service = new class($calls, $throw) extends CorrectiveDocService {
            public function __construct(private array &$calls, private readonly ?\Throwable $throw)
            {
            }

            public function createFor(int $docId, ?string $today = null): int
            {
                $this->calls[] = $docId;
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                return 99;
            }
        };

        return new class($this->createMock(DataSourceConnection::class), $this->createMock(DataSourceConfig::class), $service) extends CorrectiveDocController {
            public function __construct(DataSourceConnection $db, DataSourceConfig $dsConfig, private readonly CorrectiveDocService $fake)
            {
                parent::__construct($db, null, $dsConfig);
            }

            protected function service(): CorrectiveDocService
            {
                return $this->fake;
            }
        };
    }

    private static function statusOf(Response $response): int
    {
        return (int) (new \ReflectionClass($response))->getProperty('status')->getValue($response);
    }

    private static function request(mixed $body): Request
    {
        $request = new class($body) extends Request {
            public function __construct(private readonly mixed $fakeBody)
            {
            }

            public function getBody(): ?array
            {
                return is_array($this->fakeBody) ? $this->fakeBody : null;
            }
        };
        return $request;
    }

    public function testCreatesDraftAndReturnsItsId(): void
    {
        $response = $this->controller()->create(self::request(['docId' => 12]));

        $this->assertSame(200, self::statusOf($response));
        $this->assertSame(['success' => true, 'data' => ['docId' => 99]], $response->getPayload());
        $this->assertSame([12], $this->calls);
    }

    public function testNumericStringIdIsAccepted(): void
    {
        $this->controller()->create(self::request(['docId' => '12']));
        $this->assertSame([12], $this->calls);
    }

    public function testMissingIdIsBadRequest(): void
    {
        foreach ([null, [], ['docId' => 0], ['docId' => 'abc'], ['doc' => 12]] as $body) {
            $response = $this->controller()->create(self::request($body));
            $this->assertSame(400, self::statusOf($response));
            $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
        }
        $this->assertSame([], $this->calls, 'služba se nevolá');
    }

    public function testNotFoundMapsTo404(): void
    {
        $response = $this->controller(CorrectiveDocException::notFound(12))->create(self::request(['docId' => 12]));

        $this->assertSame(404, self::statusOf($response));
        $this->assertSame('NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testInvalidSourceMapsTo422(): void
    {
        $response = $this->controller(CorrectiveDocException::sourceInvalid('Opravný doklad jde vystavit jen k dokladu ve stavu V pořádku.'))
            ->create(self::request(['docId' => 12]));

        $this->assertSame(422, self::statusOf($response));
        $error = $response->getPayload()['error'];
        $this->assertSame('CORRECTIVE_SOURCE_INVALID', $error['code']);
        $this->assertSame('Opravný doklad jde vystavit jen k dokladu ve stavu V pořádku.', $error['message']);
        $this->assertArrayNotHasKey('details', $error);
    }

    public function testApplyFailureMapsTo422WithIssueDetails(): void
    {
        $result = ApplyResult::error('validation_failed', 'Doklad neprošel validací.', [
            '_resolve' => ['issues' => [
                ['severity' => 'error', 'path' => 'customer', 'code' => 'required', 'message' => 'U vydané faktury je odběratel povinný.'],
                ['severity' => 'warning', 'path' => 'correction', 'code' => 'correction_original_ambiguous', 'message' => 'víc shod'],
            ]],
        ], statusCode: 422);
        $response = $this->controller(CorrectiveDocException::applyFailed($result))->create(self::request(['docId' => 12]));

        $this->assertSame(422, self::statusOf($response));
        $error = $response->getPayload()['error'];
        $this->assertSame('CORRECTIVE_APPLY_FAILED', $error['code']);
        $this->assertSame('Doklad neprošel validací.', $error['message']);
        $this->assertSame(
            [['field' => 'customer', 'code' => 'required', 'message' => 'U vydané faktury je odběratel povinný.', 'severity' => 'error']],
            $error['details'],
            'jen chyby, varování ne',
        );
    }

    public function testRuntimeFailureIsInternalError(): void
    {
        $response = $this->controller(new \RuntimeException('Opravný doklad potřebuje zkompilovanou konfiguraci (ds-upgrade).'))
            ->create(self::request(['docId' => 12]));

        $this->assertSame(500, self::statusOf($response));
        $this->assertSame('INTERNAL_ERROR', $response->getPayload()['error']['code']);
    }
}
