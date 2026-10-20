<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\EInvoice\CountryNames;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceFormats;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceInputException;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceOptions;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceUuid;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceWriter;
use Shipard\Module\Core\Exchange\Isdoc\IsdocSchemaValidator;
use Shipard\Module\Core\Exchange\Isdoc\IsdocWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-server einvoice-render` — strukturovaná e-faktura (ISDOC) z canonicalu
 * v JSON souboru (#119 D9). Vstupní bod pro build korpusu testovacích
 * dokladů (#103): nepotřebuje zdroj dat ani `server.json`; názvy zemí bere
 * přímo z modulového číselníku, UUID je deterministické z `--uuid-name`.
 *
 * Exit 0 = zapsáno; 1 = vstup (chybějící volby, nečitelný JSON, neznámý
 * formát, cesty z EInvoiceInputException na stderr — nic se nezapíše);
 * 2 = výstup neprošel XSD (chyba generátoru, nic se nezapíše).
 */
class EInvoiceRenderCommand extends Command
{
    protected function configure(): void
    {
        $help = <<<HELP
Renders a structured e-invoice from a canonical shpd.docs.document.v1 JSON
file (paper projection + rows[].computed) and writes it atomically to
--output. Needs no data source and no server.json; country names come
from modules/world/base/config/countries.jsonc.

  shpd-server einvoice-render --format=isdoc --input=doc.json \
      --output=doc.isdoc --uuid-name=corpus/cz/vat-payer/0102-domestic-goods

The UUID is UUIDv5 of --uuid-name in Shipard's namespace, so the same name
always yields the same file. The output is validated against every ISDOC
schema in the repository (6.0.1 and 6.0.2) before it is written.

Exit codes: 0 written; 1 input problem (unknown format, unreadable JSON,
missing or unsupported values — paths on stderr, nothing written);
2 output failed schema validation (generator bug, nothing written).
HELP;

        $this->setName('einvoice-render')
             ->setDescription('Render a structured e-invoice (ISDOC) from a canonical JSON document; no data source needed')
             ->setHelp($help)
             ->addOption('format', null, InputOption::VALUE_REQUIRED, 'E-invoice format (' . implode(', ', EInvoiceFormats::ids()) . ')')
             ->addOption('input', null, InputOption::VALUE_REQUIRED, 'Canonical shpd.docs.document.v1 JSON with rows[].computed')
             ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Target file (written atomically via a temp file next to it)')
             ->addOption('uuid-name', null, InputOption::VALUE_REQUIRED, 'Name for the deterministic UUIDv5 (e.g. corpus/cz/vat-payer/0102-domestic-goods)')
             ->addOption('issuing-system', null, InputOption::VALUE_REQUIRED, 'IssuingSystem text (no version, no commit)', 'Shipard');
    }

    // ── Seamy pro testy ─────────────────────────────────────────────────────

    protected function writerFor(string $format): ?EInvoiceWriter
    {
        return EInvoiceFormats::forFormat($format);
    }

    protected function countryNames(): CountryNames
    {
        $resolver = $this->buildResolver();
        $path = $resolver->getPath('world.base') ?? (dirname(__DIR__, 3) . '/modules/world/base');
        return CountryNames::fromModuleFile($path . '/config/countries.jsonc');
    }

    /**
     * @return array<string, list<string>> verze schématu → hlášky; prázdné = validní
     */
    protected function validateOutput(string $format, string $content): array
    {
        return $format === IsdocWriter::FORMAT ? (new IsdocSchemaValidator())->validateAll($content) : [];
    }

    private function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }

    // ── Běh ─────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $values = [];
        foreach (['format', 'input', 'output', 'uuid-name'] as $name) {
            $value = trim((string) ($input->getOption($name) ?? ''));
            if ($value === '') {
                $err->writeln("<error>Missing required option --{$name}</error>");
                return Command::FAILURE;
            }
            $values[$name] = $value;
        }
        $issuingSystem = trim((string) ($input->getOption('issuing-system') ?? 'Shipard'));

        $writer = $this->writerFor($values['format']);
        if ($writer === null) {
            $err->writeln("<error>Unknown format '{$values['format']}' (supported: " . implode(', ', EInvoiceFormats::ids()) . ')</error>');
            return Command::FAILURE;
        }

        $json = @file_get_contents($values['input']);
        if ($json === false) {
            $err->writeln("<error>Cannot read input file {$values['input']}</error>");
            return Command::FAILURE;
        }
        $canonical = json_decode($json, true);
        if (!is_array($canonical) || array_is_list($canonical)) {
            $err->writeln("<error>Input {$values['input']} is not a JSON object" . (json_last_error() !== JSON_ERROR_NONE ? ': ' . json_last_error_msg() : '') . '</error>');
            return Command::FAILURE;
        }

        try {
            $options = new EInvoiceOptions(EInvoiceUuid::fromName($values['uuid-name']), $this->countryNames(), $issuingSystem);
            $content = $writer->write($canonical, $options);
        } catch (EInvoiceInputException $e) {
            $err->writeln('<error>Input is incomplete or unsupported — nothing written:</error>');
            foreach ($e->lines() as $line) {
                $err->writeln("  {$line}");
            }
            return Command::FAILURE;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $schemaErrors = $this->validateOutput($values['format'], $content);
        if ($schemaErrors !== []) {
            $err->writeln('<error>Output failed schema validation (generator bug) — nothing written:</error>');
            foreach ($schemaErrors as $version => $messages) {
                foreach ($messages as $message) {
                    $err->writeln("  [{$version}] {$message}");
                }
            }
            return Command::INVALID;
        }

        $target = $values['output'];
        $tmp = $target . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content) === false) {
            $err->writeln("<error>Cannot write {$tmp}</error>");
            return Command::FAILURE;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            $err->writeln("<error>Cannot move {$tmp} to {$target}</error>");
            return Command::FAILURE;
        }

        $output->writeln(sprintf('%s: %s (%d B)', strtoupper($values['format']), $target, strlen($content)));
        return Command::SUCCESS;
    }
}
