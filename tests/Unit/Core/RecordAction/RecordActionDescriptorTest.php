<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\RecordAction;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionDialog;
use Shipard\Core\RecordAction\RecordActionField;
use Shipard\Core\RecordAction\RecordActionFile;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;

/**
 * Datové třídy akce záznamu (#172 D12–D14): popis akce, dialog, výsledek,
 * chyba vstupu a vstup. Tvar hlídá konstruktor — popis mimo kontrakt je
 * výjimka z `describe()`.
 */
class RecordActionDescriptorTest extends TestCase
{
    public function testMinimalDescriptor(): void
    {
        $descriptor = new RecordActionDescriptor('Verify connection');

        $this->assertSame(
            ['label' => 'Verify connection', 'variant' => 'secondary', 'confirm' => null, 'dialog' => null],
            $descriptor->toArray(),
        );
        $this->assertSame([], $descriptor->fields());
    }

    public function testDescriptorWithIconAndConfirm(): void
    {
        $descriptor = new RecordActionDescriptor('Delete', RecordActionDescriptor::DANGER, 'trash', 'Really?');

        $this->assertSame(
            ['label' => 'Delete', 'variant' => 'danger', 'icon' => 'trash', 'confirm' => 'Really?', 'dialog' => null],
            $descriptor->toArray(),
        );
    }

    public function testDescriptorWithDialog(): void
    {
        $password = RecordActionField::password('password', 'Password', required: true);
        $descriptor = new RecordActionDescriptor(
            'Upload',
            RecordActionDescriptor::PRIMARY,
            dialog: new RecordActionDialog('Upload certificate', [$password], 'Intro', 'Send'),
        );

        $this->assertSame([$password], $descriptor->fields());
        $this->assertSame([
            'label' => 'Upload',
            'variant' => 'primary',
            'confirm' => null,
            'dialog' => [
                'title' => 'Upload certificate',
                'intro' => 'Intro',
                'submitLabel' => 'Send',
                'fields' => [['id' => 'password', 'type' => 'password', 'label' => 'Password', 'required' => true]],
            ],
        ], $descriptor->toArray());
    }

    public function testDialogWithoutFieldsIsAllowed(): void
    {
        $this->assertSame(
            ['title' => 'Sure?', 'intro' => null, 'submitLabel' => null, 'fields' => []],
            (new RecordActionDialog('Sure?'))->toArray(),
        );
    }

    /** @param callable(): mixed $build */
    #[DataProvider('invalid')]
    public function testShapeOutsideContractThrows(callable $build, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }

    /** @return array<string, array{callable(): mixed, string}> */
    public static function invalid(): array
    {
        $field = static fn (): RecordActionField => RecordActionField::text('name', 'Name');

        return [
            'prázdný popisek' => [static fn () => new RecordActionDescriptor(' '), 'needs a label'],
            'neznámá varianta' => [static fn () => new RecordActionDescriptor('A', 'success'), "variant 'success'"],
            'prázdná ikona' => [static fn () => new RecordActionDescriptor('A', icon: ''), 'icon must not be empty'],
            'prázdné potvrzení' => [static fn () => new RecordActionDescriptor('A', confirm: ''), 'confirm must not be empty'],
            'potvrzení i dialog' => [
                static fn () => new RecordActionDescriptor('A', confirm: 'Sure?', dialog: new RecordActionDialog('T')),
                'cannot also have a confirm text',
            ],
            'dialog bez titulku' => [static fn () => new RecordActionDialog(''), 'needs a title'],
            'dialog s prázdným úvodem' => [static fn () => new RecordActionDialog('T', [], ''), 'intro must not be empty'],
            'dialog s prázdným tlačítkem' => [static fn () => new RecordActionDialog('T', [], null, ' '), 'submitLabel must not be empty'],
            'pole není pole dialogu' => [
                static fn () => new RecordActionDialog('T', [['id' => 'name']]),
                'fields[0] must be a RecordActionField',
            ],
            'pole není seznam' => [
                static fn () => new RecordActionDialog('T', ['name' => $field()]),
                'fields must be a list',
            ],
            'id pole dvakrát' => [
                static fn () => new RecordActionDialog('T', [$field(), $field()]),
                "field id 'name' is used twice",
            ],
            'výsledek bez zprávy' => [static fn () => new RecordActionResult(''), 'needs a message'],
            'výsledek s neznámou úrovní' => [static fn () => new RecordActionResult('Done', 'error'), "level 'error'"],
            'chyba vstupu bez chyb' => [static fn () => new RecordActionValidationException([]), 'at least one error'],
            'chyba vstupu s prázdným textem' => [
                static fn () => new RecordActionValidationException(['password' => '']),
                'must be {field: message}',
            ],
        ];
    }

    public function testResultLevels(): void
    {
        $this->assertSame(['message' => 'Done', 'level' => 'success'], RecordActionResult::success('Done')->toArray());
        $this->assertSame(['message' => 'Note', 'level' => 'info'], RecordActionResult::info('Note')->toArray());
        $this->assertSame(['message' => 'Hm', 'level' => 'warning'], RecordActionResult::warning('Hm')->toArray());
        $this->assertSame('success', (new RecordActionResult('Done'))->level);
    }

    public function testValidationExceptionCarriesFieldErrors(): void
    {
        $this->assertSame(
            ['password' => 'Wrong password'],
            RecordActionValidationException::field('password', 'Wrong password')->fieldErrors(),
        );
        $this->assertSame(['_form' => 'Cannot store'], RecordActionValidationException::form('Cannot store')->fieldErrors());
        $this->assertSame(
            ['a' => 'A', 'b' => 'B'],
            (new RecordActionValidationException(['a' => 'A', 'b' => 'B']))->fieldErrors(),
        );
    }

    public function testInputGivesTypedValuesAndFiles(): void
    {
        $paidAt = new \DateTimeImmutable('2026-11-25 14:30');
        $file = new RecordActionFile('/tmp/php123', 'cert.p12', 2048, 'application/x-pkcs12');
        $input = new RecordActionInput(
            ['title' => 'Main', 'note' => null, 'notify' => true, 'copies' => 2, 'paidAt' => $paidAt],
            ['certificate' => $file],
        );

        $this->assertSame('Main', $input->string('title'));
        $this->assertNull($input->string('note'));
        $this->assertNull($input->string('copies'), 'číslo není text');
        $this->assertSame(2, $input->value('copies'));
        $this->assertNull($input->value('unknown'));
        $this->assertTrue($input->bool('notify'));
        $this->assertFalse($input->bool('unknown'));
        $this->assertSame($paidAt, $input->dateTime('paidAt'));
        $this->assertNull($input->dateTime('title'));
        $this->assertSame($file, $input->file('certificate'));
        $this->assertNull($input->file('title'));
        $this->assertSame(['title', 'note', 'notify', 'copies', 'paidAt'], array_keys($input->values()));
    }

    public function testInputDumpDoesNotShowValues(): void
    {
        $input = new RecordActionInput(
            ['password' => 'hunter2-secret'],
            ['certificate' => new RecordActionFile('/tmp/php123', 'private-name.p12', 1, 'application/octet-stream')],
        );

        $dump = print_r($input, true);

        $this->assertStringNotContainsString('hunter2-secret', $dump);
        $this->assertStringNotContainsString('private-name', $dump);
        $this->assertStringContainsString('password', $dump);
        $this->assertStringContainsString('certificate', $dump);
    }
}
