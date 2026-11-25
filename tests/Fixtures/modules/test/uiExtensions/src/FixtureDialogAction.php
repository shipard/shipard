<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionDialog;
use Shipard\Core\RecordAction\RecordActionField;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;

/**
 * Fixture (#172 D13): akce s dialogem se všemi typy polí. Heslo `wrong`
 * odmítne chybou pole, text `fail` chybou bez vazby na pole; jinak si
 * zapamatuje vstup.
 */
class FixtureDialogAction implements RecordAction
{
    public const int MAX_BYTES = 2048;

    public static ?RecordActionInput $lastInput = null;

    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return true;
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        return new RecordActionDescriptor(
            'Fixture upload',
            RecordActionDescriptor::PRIMARY,
            dialog: new RecordActionDialog(
                'Upload certificate',
                [
                    RecordActionField::notice('info', 'The certificate replaces the current one.'),
                    RecordActionField::text('title', 'Title', required: true, default: 'Main', hint: 'Shown in the list'),
                    RecordActionField::textarea('note', 'Note'),
                    RecordActionField::password('password', 'Password', required: true),
                    RecordActionField::number('copies', 'Copies', default: 1, min: 1, max: 5, step: 1),
                    RecordActionField::date('validFrom', 'Valid from', default: RecordActionField::NOW),
                    RecordActionField::datetime('paidAt', 'Paid at', required: true, default: RecordActionField::NOW),
                    RecordActionField::checkbox('notify', 'Notify', default: true),
                    RecordActionField::select('mode', 'Mode', [
                        ['value' => 'test', 'label' => 'Test'],
                        ['value' => 2, 'label' => 'Production'],
                    ], required: true, default: 'test'),
                    RecordActionField::file('certificate', 'Certificate', self::MAX_BYTES, accept: '.p12,.pfx', required: true),
                ],
                intro: 'Choose the file and enter its password.',
                submitLabel: 'Upload',
            ),
        );
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        if ($input->string('password') === 'wrong') {
            throw RecordActionValidationException::field('password', 'The password does not match the certificate.');
        }
        if ($input->string('title') === 'fail') {
            throw RecordActionValidationException::form('The certificate cannot be stored.');
        }
        self::$lastInput = $input;
        return RecordActionResult::success('Certificate uploaded');
    }
}
