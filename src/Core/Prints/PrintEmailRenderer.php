<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Prints\Texts\PrintTextSlot;
use Shipard\Core\Prints\Twig\PrintTwigFactory;

/**
 * Předmět a tělo e-mailu k tisku (#90 D37) — Twig šablony
 * `email-subject.txt.twig` a `email-body.txt.twig` nad stejným `PrintData`
 * a ve stejném sandboxu jako stránka tisku, texty z katalogů tisku
 * (`email.subject.*`, `email.body.*`) v jazyce dokumentu.
 *
 * Šablony se hledají jako záhlaví a zápatí: v adresáři šablony tisku, jinak
 * ve sdílených adresářích deklarace (`catalogs`) — tisk si může sdílené
 * texty přebít vlastními soubory.
 *
 * Výstup je prostý text: šablony `*.txt.twig` se neescapují. Předmět jde do
 * hlavičky zprávy, proto z něj mizí konce řádků.
 *
 * Uživatelský text ve slotu `emailSubject` / `emailBody` (#90 D49) výchozí
 * šablonu **přepisuje** — obálka ho nese už vykreslený v `texts`, takže platí
 * pro návrh v dialogu Odeslat i pro odeslání.
 */
final class PrintEmailRenderer
{
    public const SUBJECT_TEMPLATE = 'email-subject.txt.twig';
    public const BODY_TEMPLATE    = 'email-body.txt.twig';

    public function __construct(
        private readonly PrintTemplatePaths $paths,
        private readonly PrintTwigFactory $twigFactory,
    ) {}

    /** Má tisk dosažitelné obě e-mailové šablony? */
    public function hasTemplates(PrintDefinition $definition): bool
    {
        return $this->templatePath($definition, self::SUBJECT_TEMPLATE) !== null
            && $this->templatePath($definition, self::BODY_TEMPLATE) !== null;
    }

    /** @throws \RuntimeException Tisk nemá e-mailové šablony (chyba deklarace). */
    public function render(PrintDefinition $definition, PrintData $data, PrintTranslator $translator): PrintEmail
    {
        $subjectTemplate = $this->templatePath($definition, self::SUBJECT_TEMPLATE);
        $bodyTemplate    = $this->templatePath($definition, self::BODY_TEMPLATE);
        if ($subjectTemplate === null || $bodyTemplate === null) {
            throw new \RuntimeException(
                "Print '{$definition->id}' has no e-mail templates (" . self::SUBJECT_TEMPLATE
                . ', ' . self::BODY_TEMPLATE . ')',
            );
        }

        $twig    = $this->twigFactory->create($translator);
        $context = $data->toArray();

        $subject = self::singleLine($data->texts[PrintTextSlot::EmailSubject->value] ?? '');
        $body    = trim($data->texts[PrintTextSlot::EmailBody->value] ?? '');

        return new PrintEmail(
            $subject !== '' ? $subject : self::singleLine($twig->render($subjectTemplate, $context)),
            self::text($body !== '' ? $body : $twig->render($bodyTemplate, $context)),
        );
    }

    /** Twig cesta šablony: adresář tisku má přednost před sdílenými. */
    private function templatePath(PrintDefinition $definition, string $fileName): ?string
    {
        foreach ([$definition->template, ...array_reverse($definition->catalogs)] as $path) {
            $dir = $this->paths->directory($path);
            if ($dir !== null && is_file($dir . '/' . $fileName)) {
                return $path . '/' . $fileName;
            }
        }
        return null;
    }

    /** Hlavička zprávy nesmí nést konec řádku — podstrčil by další hlavičky. */
    private static function singleLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Sjednocené konce řádků, bez mezer na koncích a bez hromad prázdných řádků. */
    private static function text(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text) . "\n";
    }
}
