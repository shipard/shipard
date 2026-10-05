<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\Texts;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Prints\Texts\PrintTextSlot;
use Shipard\Core\Utils\JsoncParser;

/**
 * Sloty textů na tiscích (#90 D48): výčet je zdroj pravdy pro id a druh,
 * cfgItem `core.prints.textSlots` nese názvy — obojí musí sedět.
 */
class PrintTextSlotTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function cfgItem(): array
    {
        return JsoncParser::parseFile(dirname(__DIR__, 5) . '/modules/core/prints/config/textSlots.jsonc');
    }

    public function testCfgItemDescribesExactlyTheEnumCases(): void
    {
        $this->assertSame(PrintTextSlot::ids(), array_keys(self::cfgItem()));
    }

    public function testCfgItemKindMatchesSlotKind(): void
    {
        foreach (self::cfgItem() as $id => $item) {
            $this->assertSame(
                PrintTextSlot::from($id)->isEmail() ? 'text' : 'html',
                $item['kind'],
                "slot {$id}",
            );
        }
    }

    public function testEverySlotHasNameAndDescriptionInUiLanguages(): void
    {
        foreach (self::cfgItem() as $id => $item) {
            foreach (['name', 'name:cs', 'name:en', 'description', 'description:cs', 'description:en'] as $key) {
                $this->assertNotSame('', trim((string) ($item[$key] ?? '')), "slot {$id}: {$key}");
            }
        }
    }

    public function testOnlyEmailSlotsAreEmail(): void
    {
        $email = array_values(array_filter(
            PrintTextSlot::cases(),
            static fn (PrintTextSlot $slot): bool => $slot->isEmail(),
        ));

        $this->assertSame([PrintTextSlot::EmailSubject, PrintTextSlot::EmailBody], $email);
        // Čtyři jazyky tisku existují nezávisle na slotech — jen pojistka importu.
        $this->assertContains('cs', PrintLanguageResolver::LANGUAGES);
    }
}
