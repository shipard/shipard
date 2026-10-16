<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalVariant;
use Shipard\Module\Core\Mail\Eval\EvalVariantExistsException;
use Shipard\Module\Core\Mail\Eval\EvalVariantStore;

/** Soubory variant (editor D62): výpis vč. rozbitých, výběr jen z výpisu, uložení přes validaci, přepsání jen s potvrzením. */
final class EvalVariantStoreTest extends TestCase
{
    private string $dir;
    private EvalVariantStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_variants_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/prompts', 0750, true);
        file_put_contents($this->dir . '/valid.jsonc', (string) json_encode([
            'format' => EvalVariant::FORMAT, 'name' => 'valid-declared', 'description' => 'Platná', 'profile' => 'czech_general',
            'promptTemplateFile' => null, 'call' => ['model' => 'claude-sonnet-5-5'], 'contentTags' => [],
        ]));
        file_put_contents($this->dir . '/broken.jsonc', '{not json');
        file_put_contents($this->dir . '/prompt.txt', "Subject: {{ message.subject }}\n");
        file_put_contents($this->dir . '/prompts/alt.md', "alt\n");
        $this->store = new EvalVariantStore($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/prompts/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/prompts');
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testListIncludesBrokenFilesAndSelectsOnlyFromTheListing(): void
    {
        $list = $this->store->list();

        $this->assertSame(['broken', 'valid'], array_column($list, 'name'));
        $this->assertFalse($list[0]['valid']);
        $this->assertNotSame('', (string) $list[0]['error']);
        $this->assertTrue($list[1]['valid']);
        $this->assertSame('czech_general', $list[1]['profile']);
        $this->assertSame('valid-declared', $list[1]['declaredName']);
        $this->assertSame('claude-sonnet-5-5', $list[1]['call']['model']);

        $this->assertSame($this->dir . '/valid.jsonc', $this->store->pathFor('valid'));
        $this->assertNull($this->store->pathFor('../valid'));
        $this->assertNull($this->store->pathFor('missing'));
        $this->assertSame('valid-declared', $this->store->find('valid')?->name);
        $this->assertNull($this->store->find('missing'));
        $this->assertSame(['prompt.txt', 'prompts/alt.md'], $this->store->promptTemplates());
    }

    public function testSaveWritesJsoncThatLoadsBack(): void
    {
        $variant = $this->store->save('new-one', [
            'description' => 'Nová',
            'profile' => 'czech_general',
            'promptTemplateFile' => 'prompt.txt',
            'call' => ['connection' => '', 'model' => 'claude-haiku-5-5', 'effort' => 'high'],
            'contentTags' => ['model' => ''],
        ], false);

        $path = $this->dir . '/new-one.jsonc';
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($path . '.tmp');
        $this->assertStringStartsWith('// Varianta evaluace', (string) file_get_contents($path));
        $this->assertSame('new-one', $variant->name);
        $this->assertSame("Subject: {{ message.subject }}\n", $variant->promptTemplate);
        $this->assertSame(['connection' => null, 'model' => 'claude-haiku-5-5', 'thinking' => null, 'effort' => 'high', 'maxTokens' => null], $variant->toArray()['call']);
        $this->assertSame(['model' => null, 'thinking' => null, 'effort' => null], $variant->toArray()['contentTags']);
        $this->assertSame('new-one', EvalVariant::load($path)->name, 'soubor načte stávající načítač');
    }

    public function testOverwriteNeedsConfirmation(): void
    {
        try {
            $this->store->save('valid', ['profile' => 'czech_general'], false);
            $this->fail('expected EvalVariantExistsException');
        } catch (EvalVariantExistsException) {
        }
        $this->assertSame('Platná', $this->store->find('valid')?->description, 'původní soubor beze změny');

        $variant = $this->store->save('valid', ['description' => 'Přepsaná', 'profile' => 'czech_general'], true);
        $this->assertSame('Přepsaná', $variant->description);
        $this->assertSame('valid', $variant->name, 'name v souboru = název souboru');
    }

    public function testInvalidInputIsRefusedWithoutWriting(): void
    {
        foreach ([
            ['Bad Name', ['profile' => 'czech_general']],
            ['ok-name', ['profile' => 'czech_general', 'unknown' => 1]],
            ['ok-name', ['description' => 'bez profilu']],
            ['ok-name', ['profile' => 'czech_general', 'promptTemplateFile' => 'nope.txt']],
            ['ok-name', ['profile' => 'czech_general', 'call' => ['maxTokens' => -1]]],
        ] as [$name, $declared]) {
            try {
                $this->store->save($name, $declared, false);
                $this->fail('expected EvalException for ' . json_encode($declared));
            } catch (EvalException $e) {
                $this->assertNotInstanceOf(EvalVariantExistsException::class, $e);
            }
        }
        $this->assertFileDoesNotExist($this->dir . '/ok-name.jsonc');
    }
}
