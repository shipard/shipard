<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\RecordAction;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\RecordAction\RecordActionField;
use Shipard\Core\RecordAction\RecordActionFile;
use Shipard\Core\RecordAction\RecordActionInputCheck;
use Shipard\Core\RecordAction\RecordActionInputValidator;

/**
 * Obecná validace vstupu akce záznamu (#172 D13, D14): nedeklarované pole,
 * povinnost, typy, soubor a převod hodnot podle typu pole.
 */
class RecordActionInputValidatorTest extends TestCase
{
    /** @return list<RecordActionField> */
    private static function fields(): array
    {
        return [
            RecordActionField::notice('info', 'Just a text.'),
            RecordActionField::text('title', 'Title', required: true),
            RecordActionField::textarea('note', 'Note'),
            RecordActionField::password('password', 'Password'),
            RecordActionField::number('copies', 'Copies', min: 1, max: 5),
            RecordActionField::date('validFrom', 'Valid from'),
            RecordActionField::datetime('paidAt', 'Paid at'),
            RecordActionField::checkbox('notify', 'Notify'),
            RecordActionField::select('mode', 'Mode', [
                ['value' => 'test', 'label' => 'Test'],
                ['value' => 2, 'label' => 'Production'],
            ]),
            RecordActionField::file('certificate', 'Certificate', 100),
        ];
    }

    /**
     * @return array{path: string, name: string, size: int, error: int, mimeType: string}
     */
    private static function upload(int $size = 50, int $error = UPLOAD_ERR_OK, string $path = '/tmp/php123'): array
    {
        return ['path' => $path, 'name' => 'cert.p12', 'size' => $size, 'error' => $error, 'mimeType' => 'application/x-pkcs12'];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array{path: string, name: string, size: int, error: int, mimeType: string}> $files
     */
    private static function check(array $values, array $files = []): RecordActionInputCheck
    {
        return RecordActionInputValidator::validate(self::fields(), $values, $files);
    }

    /** @return array<string, string> pole → kód chyby */
    private static function codes(RecordActionInputCheck $check): array
    {
        return array_column($check->errors, 'code', 'field');
    }

    public function testValidInputIsConvertedByFieldType(): void
    {
        $check = self::check([
            'title' => ' Main ',
            'note' => "line\nline",
            'password' => ' pass word ',
            'copies' => '3',
            'validFrom' => '2026-11-25',
            'paidAt' => '2026-11-25T14:30',
            'notify' => true,
            'mode' => '2',
        ], ['certificate' => self::upload()]);

        $this->assertSame([], $check->errors);
        $this->assertNull($check->unknownField);
        $input = $check->input;
        $this->assertNotNull($input);

        $this->assertSame(' Main ', $input->value('title'), 'text se neořezává, jen se podle ořezu pozná prázdný');
        $this->assertSame("line\nline", $input->value('note'));
        $this->assertSame(' pass word ', $input->value('password'));
        $this->assertSame(3, $input->value('copies'));
        $this->assertSame('2026-11-25 00:00', $input->dateTime('validFrom')?->format('Y-m-d H:i'));
        $this->assertSame('2026-11-25 14:30', $input->dateTime('paidAt')?->format('Y-m-d H:i'));
        $this->assertTrue($input->value('notify'));
        $this->assertSame(2, $input->value('mode'), 'hodnota v typu z nabídky');

        $file = $input->file('certificate');
        $this->assertInstanceOf(RecordActionFile::class, $file);
        $this->assertSame(['/tmp/php123', 'cert.p12', 50, 'application/x-pkcs12'], [$file->path, $file->name, $file->size, $file->mimeType]);
    }

    public function testMissingOptionalFieldsAreNullAndCheckboxIsFalse(): void
    {
        $input = self::check(['title' => 'Main'])->input;

        $this->assertNotNull($input);
        $this->assertSame(
            ['title' => 'Main', 'note' => null, 'password' => null, 'copies' => null, 'validFrom' => null,
             'paidAt' => null, 'notify' => false, 'mode' => null],
            $input->values(),
            'každé pole s hodnotou má klíč, hláška ne',
        );
        $this->assertNull($input->file('certificate'));
    }

    public function testEmptyStringsCountAsNotFilled(): void
    {
        $input = self::check([
            'title' => 'Main', 'note' => '   ', 'password' => '', 'copies' => '', 'validFrom' => '',
            'paidAt' => null, 'mode' => '',
        ])->input;

        $this->assertNotNull($input);
        $this->assertNull($input->value('note'));
        $this->assertNull($input->value('password'));
        $this->assertNull($input->value('copies'));
        $this->assertNull($input->value('validFrom'));
        $this->assertNull($input->value('mode'));
    }

    public function testNumbers(): void
    {
        $this->assertSame(2, self::check(['title' => 'A', 'copies' => 2])->input?->value('copies'));
        $this->assertSame(2.5, self::check(['title' => 'A', 'copies' => '2.5'])->input?->value('copies'));
        $this->assertSame(1.5, self::check(['title' => 'A', 'copies' => 1.5])->input?->value('copies'));
        $this->assertSame(5, self::check(['title' => 'A', 'copies' => ' 5 '])->input?->value('copies'));
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidValues')]
    public function testInvalidValue(array $values, string $field, string $code): void
    {
        $check = self::check($values + ['title' => 'Main']);

        $this->assertNull($check->input);
        $this->assertNull($check->unknownField);
        $this->assertSame([$field => $code], self::codes($check));
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidValues(): array
    {
        return [
            'povinné pole chybí'       => [['title' => null], 'title', 'required'],
            'povinné pole jen mezery'  => [['title' => '  '], 'title', 'required'],
            'text číslem'              => [['note' => 5], 'note', 'invalid_value'],
            'text polem'               => [['note' => ['a']], 'note', 'invalid_value'],
            'heslo číslem'             => [['password' => 1234], 'password', 'invalid_value'],
            'číslo textem'             => [['copies' => 'three'], 'copies', 'invalid_number'],
            'číslo s čárkou'           => [['copies' => '2,5'], 'copies', 'invalid_number'],
            'číslo exponentem'         => [['copies' => '1e3'], 'copies', 'invalid_number'],
            'číslo bool'               => [['copies' => true], 'copies', 'invalid_number'],
            'číslo pod mezí'           => [['copies' => 0], 'copies', 'out_of_range'],
            'číslo nad mezí'           => [['copies' => '6'], 'copies', 'out_of_range'],
            'datum česky'              => [['validFrom' => '25.11.2026'], 'validFrom', 'invalid_date'],
            'neexistující datum'       => [['validFrom' => '2026-02-30'], 'validFrom', 'invalid_date'],
            'datum číslem'             => [['validFrom' => 20261125], 'validFrom', 'invalid_date'],
            'čas bez času'             => [['paidAt' => '2026-11-25'], 'paidAt', 'invalid_date'],
            'čas s mezerou'            => [['paidAt' => '2026-11-25 14:30'], 'paidAt', 'invalid_date'],
            'checkbox textem'          => [['notify' => 'true'], 'notify', 'invalid_value'],
            'checkbox číslem'          => [['notify' => 1], 'notify', 'invalid_value'],
            'výběr mimo nabídku'       => [['mode' => 'live'], 'mode', 'not_in_options'],
            'výběr polem'              => [['mode' => ['test']], 'mode', 'invalid_value'],
        ];
    }

    public function testAllErrorsAreReportedAtOnceWithoutValues(): void
    {
        $check = self::check(['password' => 12345, 'copies' => 'secret-number', 'mode' => 'secret-mode']);

        $this->assertSame(
            ['title' => 'required', 'password' => 'invalid_value', 'copies' => 'invalid_number', 'mode' => 'not_in_options'],
            self::codes($check),
        );
        $json = json_encode($check->errors, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString('12345', $json);
        foreach ($check->errors as $error) {
            $this->assertNotSame('', $error['message']);
        }
    }

    public function testRequiredFile(): void
    {
        $fields = [RecordActionField::file('certificate', 'Certificate', 100, required: true)];

        $this->assertSame(
            ['certificate' => 'required'],
            self::codes(RecordActionInputValidator::validate($fields, [])),
        );
        $this->assertSame(
            ['certificate' => 'required'],
            self::codes(RecordActionInputValidator::validate($fields, [], ['certificate' => self::upload(0, UPLOAD_ERR_NO_FILE, '')])),
            'prázdné pole souboru v multipartu',
        );
        $this->assertNotNull(RecordActionInputValidator::validate($fields, [], ['certificate' => self::upload(100)])->input);
    }

    /** @param array{path: string, name: string, size: int, error: int, mimeType: string} $upload */
    #[DataProvider('invalidFiles')]
    public function testInvalidFile(array $upload, string $code): void
    {
        $check = self::check(['title' => 'Main'], ['certificate' => $upload]);

        $this->assertNull($check->input);
        $this->assertSame(['certificate' => $code], self::codes($check));
        $this->assertStringNotContainsString('cert.p12', json_encode($check->errors, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{array{path: string, name: string, size: int, error: int, mimeType: string}, string}> */
    public static function invalidFiles(): array
    {
        return [
            'nad limit pole'          => [self::upload(101), 'file_too_large'],
            'nad limit PHP'           => [self::upload(0, UPLOAD_ERR_INI_SIZE, ''), 'file_too_large'],
            'nad limit formuláře'     => [self::upload(0, UPLOAD_ERR_FORM_SIZE, ''), 'file_too_large'],
            'částečně nahraný'        => [self::upload(0, UPLOAD_ERR_PARTIAL, ''), 'file_upload_failed'],
            'bez dočasného souboru'   => [self::upload(50, UPLOAD_ERR_OK, ''), 'file_upload_failed'],
        ];
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<array-key, mixed> $files
     */
    #[DataProvider('undeclared')]
    public function testUndeclaredFieldRejectsWholeInput(array $values, array $files, string $field): void
    {
        $check = self::check(['title' => 'Main'] + $values, $files);

        $this->assertSame($field, $check->unknownField);
        $this->assertNull($check->input);
        $this->assertSame([], $check->errors);
    }

    /** @return array<string, array{array<array-key, mixed>, array<array-key, mixed>, string}> */
    public static function undeclared(): array
    {
        return [
            'neznámé pole'             => [['extra' => 1], [], 'extra'],
            'neznámé pole s null'      => [['extra' => null], [], 'extra'],
            'hodnota pro hlášku'       => [['info' => 'x'], [], 'info'],
            'soubor mezi hodnotami'    => [['certificate' => 'base64…'], [], 'certificate'],
            'soubor pro textové pole'  => [[], ['note' => self::upload()], 'note'],
            'neznámý soubor'           => [[], ['other' => self::upload()], 'other'],
            'seznam místo objektu'     => [[0 => 'x'], [], '0'],
        ];
    }

    public function testActionWithoutDialogAcceptsOnlyEmptyInput(): void
    {
        $empty = RecordActionInputValidator::validate([], []);
        $this->assertNotNull($empty->input);
        $this->assertSame([], $empty->input->values());

        $this->assertSame('anything', RecordActionInputValidator::validate([], ['anything' => 1])->unknownField);
        $this->assertSame('file', RecordActionInputValidator::validate([], [], ['file' => self::upload()])->unknownField);
    }
}
