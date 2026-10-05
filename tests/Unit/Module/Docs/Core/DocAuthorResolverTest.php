<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\CurrentUser;
use Shipard\Core\Settings\KeyValueStore;
use Shipard\Module\Docs\Core\DocAuthorResolver;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Výchozí autor nového dokladu (#93 D1, D11): explicitní hodnota → člověk
 * u klávesnice → autor automaticky vystavených dokladů (řada → nastavení).
 */
class DocAuthorResolverTest extends TestCase
{
    /** Jana a Karel jsou lidé, 7 je deaktivovaný účet, 9 systémový (API klíč). */
    private const USERS = [
        3 => ['is_system' => 0, 'is_active' => 1],
        4 => ['is_system' => 0, 'is_active' => 1],
        7 => ['is_system' => 0, 'is_active' => 0],
        9 => ['is_system' => 1, 'is_active' => 1],
    ];

    protected function tearDown(): void
    {
        CurrentUser::reset();
    }

    /** @param array<int, ?int> $series id řady => auto_author */
    private function db(array $series = []): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($series): ?Row {
                $id = (int) ($params[0] ?? 0);
                if (str_contains($sql, 'docs_core_number_series')) {
                    return array_key_exists($id, $series) ? new Row(['auto_author' => $series[$id]]) : null;
                }
                $user = self::USERS[$id] ?? null;
                if ($user === null) {
                    return null;
                }
                if (str_contains($sql, '[is_active] = 1')) {
                    return $user['is_active'] === 1 ? new Row(['id' => $id]) : null;
                }
                return new Row(['is_system' => $user['is_system']]);
            },
        );
        return $db;
    }

    private function settings(mixed $autoAuthor): KeyValueStore
    {
        $settings = $this->createStub(KeyValueStore::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key): mixed => $key === DocAuthorResolver::SETTING ? $autoAuthor : null,
        );
        return $settings;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, ?int> $series
     * @return array<string, mixed>
     */
    private function apply(array $data, array $series = [], mixed $setting = null): array
    {
        (new DocAuthorResolver($this->db($series), $this->settings($setting)))->apply($data);
        return $data;
    }

    public function testExplicitAuthorIsNeverOverwritten(): void
    {
        CurrentUser::set(3);

        $this->assertSame(4, $this->apply(['author' => 4])['author']);
    }

    public function testExplicitNullKeepsDocumentWithoutAuthor(): void
    {
        CurrentUser::set(3);

        $data = $this->apply(['author' => null, 'number_series' => 1], [1 => 4], 4);

        $this->assertArrayHasKey('author', $data);
        $this->assertNull($data['author']);
    }

    public function testSignedInUserBecomesAuthor(): void
    {
        CurrentUser::set(3);

        // Řada i nastavení mají svého autora — člověk má přednost (D11).
        $this->assertSame(3, $this->apply(['number_series' => 1], [1 => 4], 4)['author']);
    }

    public function testMachineContextUsesSeriesAuthorFirst(): void
    {
        $this->assertSame(4, $this->apply(['number_series' => 1], [1 => 4], 3)['author']);
    }

    public function testSeriesWithoutAuthorFallsBackToSetting(): void
    {
        $this->assertSame(3, $this->apply(['number_series' => 1], [1 => null], '3')['author']);
        $this->assertSame(3, $this->apply([], [], 3)['author']);
    }

    public function testMachineContextWithoutConfiguredAuthorLeavesNoAuthor(): void
    {
        $this->assertArrayNotHasKey('author', $this->apply(['number_series' => 1], [1 => null]));
        $this->assertArrayNotHasKey('author', $this->apply(['number_series' => 99]));
    }

    public function testInactiveUserFromSettingIsNotUsed(): void
    {
        $this->assertArrayNotHasKey('author', $this->apply(['number_series' => 1], [1 => null], 7));
    }

    public function testInactiveSeriesAuthorFallsThroughToSetting(): void
    {
        $this->assertSame(3, $this->apply(['number_series' => 1], [1 => 7], 3)['author']);
    }

    public function testMissingUserFromSettingIsNotUsed(): void
    {
        $this->assertArrayNotHasKey('author', $this->apply([], [], 555));
    }

    public function testSystemUserCountsAsMachineContext(): void
    {
        // API klíč integrace: „Vystavil“ není jméno integrace, ale autor
        // automaticky vystavených dokladů — a bez něj nikdo.
        CurrentUser::set(9);

        $this->assertSame(4, $this->apply(['number_series' => 1], [1 => 4])['author']);
        $this->assertArrayNotHasKey('author', $this->apply(['number_series' => 1], [1 => null]));
    }

    public function testInteractiveUserIsNullWithoutUserRow(): void
    {
        CurrentUser::set(555);
        $resolver = new DocAuthorResolver($this->db());

        $this->assertNull($resolver->interactiveUser());
    }

    // ── DocDocument: jen při vzniku dokladu ─────────────────────────────────

    public function testNewDocumentGetsSignedInUser(): void
    {
        CurrentUser::set(3);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->db());

        $data = ['doc_type' => 'invno'];
        $doc->applyAuthorDefaultPub($data, null);

        $this->assertSame(3, $data['author']);
    }

    public function testUpdateNeverChangesAuthor(): void
    {
        CurrentUser::set(3);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->db());

        // Částečné uložení bez klíče (hlavička z jiného tabu) i celý řádek
        // s původním autorem — obojí zůstává, jak přišlo.
        $partial = ['id' => 5, 'doc_text' => 'Oprava'];
        $doc->applyAuthorDefaultPub($partial, ['id' => 5, 'author' => 4]);
        $full = ['id' => 5, 'author' => 4];
        $doc->applyAuthorDefaultPub($full, ['id' => 5, 'author' => 4]);

        $this->assertArrayNotHasKey('author', $partial);
        $this->assertSame(4, $full['author']);
    }

    public function testNewDocumentWithoutDatabaseIsLeftAlone(): void
    {
        CurrentUser::set(3);
        $data = ['doc_type' => 'invno'];

        (new TestableDocsHeadsDocument())->applyAuthorDefaultPub($data, null);

        $this->assertArrayNotHasKey('author', $data);
    }
}
