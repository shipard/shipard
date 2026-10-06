<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\MultipartFiles;

/** Soubory z `multipart/form-data` pole `name[]` — pořadí a jen bezchybné. */
class MultipartFilesTest extends TestCase
{
    public function testCollectsFilesInRequestOrderSkippingFailedOnes(): void
    {
        $files = ['attachments' => [
            'name'     => ['faktura.pdf', 'chybi.pdf', 'faktura.isdoc'],
            'tmp_name' => ['/tmp/php1', '', '/tmp/php3'],
            'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_OK],
            'size'     => [10, 0, 20],
            'type'     => ['application/pdf', '', 'application/xml'],
        ]];

        $this->assertSame(
            [['name' => 'faktura.pdf', 'tmp_name' => '/tmp/php1'], ['name' => 'faktura.isdoc', 'tmp_name' => '/tmp/php3']],
            MultipartFiles::collect('attachments', $files),
        );
    }

    public function testMissingOrScalarFieldYieldsNothing(): void
    {
        $this->assertSame([], MultipartFiles::collect('attachments', []));
        // Jediný soubor bez `[]` v názvu pole není seznam.
        $this->assertSame([], MultipartFiles::collect('attachments', ['attachments' => [
            'name' => 'faktura.pdf', 'tmp_name' => '/tmp/php1', 'error' => UPLOAD_ERR_OK,
        ]]));
    }
}
