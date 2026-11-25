<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\RecordAction;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\RecordAction\RecordActionField;

/**
 * Pole dialogu akce záznamu (#172 D13): tvar pro frontend podle typu,
 * validace deklarace a převod dat.
 */
class RecordActionFieldTest extends TestCase
{
    public function testEveryTypeHasAFactory(): void
    {
        $fields = [
            RecordActionField::text('a', 'A'),
            RecordActionField::textarea('b', 'B'),
            RecordActionField::password('c', 'C'),
            RecordActionField::number('d', 'D'),
            RecordActionField::date('e', 'E'),
            RecordActionField::datetime('f', 'F'),
            RecordActionField::checkbox('g', 'G'),
            RecordActionField::select('h', 'H', [['value' => 'x', 'label' => 'X']]),
            RecordActionField::file('i', 'I', 1024),
            RecordActionField::notice('j', 'J'),
        ];

        $this->assertSame(RecordActionField::TYPES, array_map(static fn (RecordActionField $f): string => $f->type, $fields));
    }

    public function testTextFieldShape(): void
    {
        $this->assertSame(
            ['id' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => false],
            RecordActionField::text('title', 'Title')->toArray(),
        );
        $this->assertSame(
            ['id' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true, 'default' => 'Main', 'hint' => 'Shown'],
            RecordActionField::text('title', 'Title', true, 'Main', 'Shown')->toArray(),
        );
    }

    public function testTypeSpecificKeys(): void
    {
        $this->assertSame(
            ['id' => 'copies', 'type' => 'number', 'label' => 'Copies', 'required' => false, 'default' => 1, 'min' => 0, 'max' => 5.5, 'step' => 0.5],
            RecordActionField::number('copies', 'Copies', default: 1, min: 0, max: 5.5, step: 0.5)->toArray(),
            'nulová mez není „bez meze“',
        );
        $this->assertSame(
            ['id' => 'paidAt', 'type' => 'datetime', 'label' => 'Paid at', 'required' => true, 'default' => 'now'],
            RecordActionField::datetime('paidAt', 'Paid at', true, RecordActionField::NOW)->toArray(),
        );
        $this->assertSame(
            ['id' => 'day', 'type' => 'date', 'label' => 'Day', 'required' => false, 'default' => '2026-11-25'],
            RecordActionField::date('day', 'Day', default: '2026-11-25')->toArray(),
        );
        $this->assertSame(
            ['id' => 'notify', 'type' => 'checkbox', 'label' => 'Notify', 'required' => false, 'default' => false],
            RecordActionField::checkbox('notify', 'Notify')->toArray(),
        );
        $this->assertSame(
            [
                'id' => 'mode', 'type' => 'select', 'label' => 'Mode', 'required' => true, 'default' => 2,
                'options' => [['value' => 'test', 'label' => 'Test'], ['value' => 2, 'label' => 'Production']],
            ],
            RecordActionField::select('mode', 'Mode', [
                ['value' => 'test', 'label' => 'Test', 'extra' => 'dropped'],
                ['value' => 2, 'label' => 'Production'],
            ], true, 2)->toArray(),
        );
        $this->assertSame(
            ['id' => 'cert', 'type' => 'file', 'label' => 'Certificate', 'required' => true, 'accept' => '.p12', 'maxBytes' => 2048],
            RecordActionField::file('cert', 'Certificate', 2048, '.p12', true)->toArray(),
        );
    }

    public function testNoticeHasTextAndNoValue(): void
    {
        $notice = RecordActionField::notice('info', 'Replaces the current certificate.');

        $this->assertSame(['id' => 'info', 'type' => 'notice', 'text' => 'Replaces the current certificate.'], $notice->toArray());
        $this->assertFalse($notice->hasValue());
        $this->assertTrue(RecordActionField::text('a', 'A')->hasValue());
        $this->assertTrue(RecordActionField::file('f', 'F', 1)->hasValue());
    }

    public function testPasswordNeverHasDefault(): void
    {
        $this->assertArrayNotHasKey('default', RecordActionField::password('password', 'Password')->toArray());
    }

    public function testFileSizeCeiling(): void
    {
        $this->assertSame(10 * 1024 * 1024, RecordActionField::MAX_FILE_BYTES);
        $this->assertSame(
            RecordActionField::MAX_FILE_BYTES,
            RecordActionField::file('f', 'F', RecordActionField::MAX_FILE_BYTES)->maxBytes,
        );
    }

    /** @param callable(): RecordActionField $build */
    #[DataProvider('invalid')]
    public function testDeclarationOutsideContractThrows(callable $build, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }

    /** @return array<string, array{callable(): RecordActionField, string}> */
    public static function invalid(): array
    {
        $option = ['value' => 'x', 'label' => 'X'];

        return [
            'id s tečkou' => [static fn () => RecordActionField::text('a.b', 'A'), "id 'a.b' must match"],
            'id velkým písmenem' => [static fn () => RecordActionField::text('Name', 'A'), "id 'Name' must match"],
            'prázdné id' => [static fn () => RecordActionField::text('', 'A'), 'must match'],
            'prázdný popisek' => [static fn () => RecordActionField::text('a', ' '), "'a' needs a label"],
            'prázdná nápověda' => [static fn () => RecordActionField::text('a', 'A', hint: ''), 'hint must not be empty'],
            'prázdný text hlášky' => [static fn () => RecordActionField::notice('a', ''), "'a' needs a label"],
            'min nad max' => [static fn () => RecordActionField::number('a', 'A', min: 5, max: 1), 'min is greater than max'],
            'nulový krok' => [static fn () => RecordActionField::number('a', 'A', step: 0), 'step must be positive'],
            'datum mimo tvar' => [static fn () => RecordActionField::date('a', 'A', default: '25.11.2026'), "must be YYYY-MM-DD or 'now'"],
            'neexistující datum' => [static fn () => RecordActionField::date('a', 'A', default: '2026-02-30'), "must be YYYY-MM-DD or 'now'"],
            'čas mimo tvar' => [static fn () => RecordActionField::datetime('a', 'A', default: '2026-11-25'), "must be YYYY-MM-DDTHH:MM or 'now'"],
            'prázdná nabídka' => [static fn () => RecordActionField::select('a', 'A', []), 'options must be a non-empty list'],
            'nabídka není seznam' => [
                static fn () => RecordActionField::select('a', 'A', ['x' => $option]),
                'options must be a non-empty list',
            ],
            'položka bez popisku' => [
                static fn () => RecordActionField::select('a', 'A', [['value' => 'x']]),
                'options[0] must be {value: string|int, label: string}',
            ],
            'hodnota položky bool' => [
                static fn () => RecordActionField::select('a', 'A', [['value' => true, 'label' => 'Yes']]),
                'options[0] must be',
            ],
            'hodnota dvakrát' => [
                static fn () => RecordActionField::select('a', 'A', [$option, ['value' => 'x', 'label' => 'Again']]),
                "option 'x' is listed twice",
            ],
            'číslo a text téže hodnoty' => [
                static fn () => RecordActionField::select('a', 'A', [['value' => 2, 'label' => 'Two'], ['value' => '2', 'label' => 'Also two']]),
                "option '2' is listed twice",
            ],
            'výchozí mimo nabídku' => [
                static fn () => RecordActionField::select('a', 'A', [$option], default: 'y'),
                'default is not one of the options',
            ],
            'soubor bez limitu' => [static fn () => RecordActionField::file('a', 'A', 0), 'maxBytes must be between 1 and 10485760'],
            'soubor nad strop' => [
                static fn () => RecordActionField::file('a', 'A', RecordActionField::MAX_FILE_BYTES + 1),
                'maxBytes must be between 1 and 10485760',
            ],
            'prázdný accept' => [static fn () => RecordActionField::file('a', 'A', 1, ' '), 'accept must not be empty'],
        ];
    }

    public function testOptionValueKeepsDeclaredType(): void
    {
        $field = RecordActionField::select('mode', 'Mode', [
            ['value' => 'test', 'label' => 'Test'],
            ['value' => 2, 'label' => 'Production'],
        ]);

        $this->assertSame('test', $field->optionValue('test'));
        $this->assertSame(2, $field->optionValue('2'), 'z JSON může přijít text');
        $this->assertSame(2, $field->optionValue(2));
        $this->assertNull($field->optionValue('other'));
        $this->assertNull(RecordActionField::text('a', 'A')->optionValue('x'));
    }

    public function testParseDate(): void
    {
        $this->assertSame('2026-11-25 00:00:00', RecordActionField::parseDate('2026-11-25')?->format('Y-m-d H:i:s'));
        $this->assertNull(RecordActionField::parseDate('2026-02-30'));
        $this->assertNull(RecordActionField::parseDate('25.11.2026'));
        $this->assertNull(RecordActionField::parseDate('2026-11-25T10:00'));
        $this->assertNull(RecordActionField::parseDate(''));
    }

    public function testParseDateTime(): void
    {
        $this->assertSame('2026-11-25 14:30:00', RecordActionField::parseDateTime('2026-11-25T14:30')?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-25 14:30:45', RecordActionField::parseDateTime('2026-11-25T14:30:45')?->format('Y-m-d H:i:s'));
        $this->assertSame(
            date_default_timezone_get(),
            RecordActionField::parseDateTime('2026-11-25T14:30')?->getTimezone()->getName(),
            'místní čas serveru',
        );
        $this->assertNull(RecordActionField::parseDateTime('2026-11-25'));
        $this->assertNull(RecordActionField::parseDateTime('2026-11-25T25:00'));
        $this->assertNull(RecordActionField::parseDateTime('2026-11-25 14:30'));
        $this->assertNull(RecordActionField::parseDateTime('now'));
    }
}
