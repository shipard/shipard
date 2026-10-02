<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderProfile;
use Shipard\Core\Settings\BrandingStorage;
use Twig\Environment;

/**
 * `PrintData` → HTML (Twig) → PDF (`RenderClient`, profil Report).
 *
 * Soubory tisku:
 *  - stránka `page.html.twig` v adresáři šablony deklarace;
 *  - záhlaví a zápatí `header.html.twig` / `footer.html.twig` — z adresáře
 *    šablony, jinak ze sdílených adresářů deklarace (`catalogs`); jsou to
 *    samostatné HTML dokumenty, proto styly inline a logo jako data URI;
 *  - assety (CSS, obrázky, fonty) ze sdílených adresářů a z adresáře
 *    šablony — pushují se s HTML a referencují relativně; šablona má
 *    přednost. Logo z brandingu jde pod názvem z `branding.logo`.
 *
 * Šablona dostává `PrintData::toArray()` — jen pole.
 */
final class PrintRenderer
{
    public const PAGE_TEMPLATE   = 'page.html.twig';
    public const HEADER_TEMPLATE = 'header.html.twig';
    public const FOOTER_TEMPLATE = 'footer.html.twig';

    private const ASSET_EXTENSIONS = ['css', 'png', 'jpg', 'jpeg', 'svg', 'webp', 'gif', 'woff', 'woff2', 'ttf', 'otf'];

    private const LOGO_MIME_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    ];

    public function __construct(
        private readonly PrintTemplatePaths $paths,
        private readonly PrintTwigFactory $twigFactory,
        private readonly RenderClient $renderClient,
        private readonly ?BrandingStorage $branding = null,
    ) {}

    /** @throws PrintRenderException Render služba PDF nevyrobila. */
    public function renderPdf(PrintDefinition $definition, PrintData $data, PrintTranslator $translator): string
    {
        $document = $this->renderDocument($definition, $data, $translator);

        $result = $this->renderClient->renderHtml(
            $document->html,
            $document->assets,
            RenderProfile::Report,
            new PdfOptions(
                paperFormat: $definition->paperFormat,
                orientation: $definition->orientation,
                marginTop: $definition->margins['top'] ?? null,
                marginBottom: $definition->margins['bottom'] ?? null,
                marginLeft: $definition->margins['left'] ?? null,
                marginRight: $definition->margins['right'] ?? null,
                headerTemplate: $document->header,
                footerTemplate: $document->footer,
                printBackground: true,
            ),
        );

        if (!$result->ok || $result->pdfContent === null) {
            throw new PrintRenderException(
                $result->errorKind ?? RenderErrorKind::EngineError,
                (string) $result->note,
            );
        }
        return $result->pdfContent;
    }

    public function renderDocument(PrintDefinition $definition, PrintData $data, PrintTranslator $translator): PrintDocument
    {
        $twig = $this->twigFactory->create($translator);

        $logoContent = $this->logoContent($data);
        $context     = $data->toArray();
        // Záhlaví je samostatný dokument bez přístupu k assetům — logo
        // dostane rovnou v URL.
        $context['branding']['logoDataUri'] = $logoContent === null
            ? null
            : self::dataUri((string) $data->logo, $logoContent);

        $directories = $this->directories($definition);

        $html   = $twig->render($definition->template . '/' . self::PAGE_TEMPLATE, $context);
        $header = $this->renderOptional($twig, $directories, self::HEADER_TEMPLATE, $context);
        $footer = $this->renderOptional($twig, $directories, self::FOOTER_TEMPLATE, $context);

        $assets = $this->collectAssets($directories);
        if ($logoContent !== null) {
            $assets[(string) $data->logo] = $logoContent;
        }

        return new PrintDocument($html, $header, $footer, $assets);
    }

    /**
     * Adresáře tisku od nejobecnějšího: sdílené (`catalogs`) a nakonec
     * šablona — pozdější má přednost.
     *
     * @return list<array{path: string, dir: string}> `path` = Twig cesta, `dir` = adresář na disku
     */
    private function directories(PrintDefinition $definition): array
    {
        $directories = [];
        foreach ([...$definition->catalogs, $definition->template] as $path) {
            $dir = $this->paths->directory($path);
            if ($dir !== null && is_dir($dir)) {
                $directories[] = ['path' => $path, 'dir' => $dir];
            }
        }
        return $directories;
    }

    /**
     * @param list<array{path: string, dir: string}> $directories
     * @param array<string, mixed> $context
     */
    private function renderOptional(Environment $twig, array $directories, string $fileName, array $context): ?string
    {
        foreach (array_reverse($directories) as $directory) {
            if (is_file($directory['dir'] . '/' . $fileName)) {
                return $twig->render($directory['path'] . '/' . $fileName, $context);
            }
        }
        return null;
    }

    /**
     * @param list<array{path: string, dir: string}> $directories
     * @return array<string, string>
     */
    private function collectAssets(array $directories): array
    {
        $assets = [];
        foreach ($directories as $directory) {
            foreach (scandir($directory['dir']) ?: [] as $entry) {
                $file = $directory['dir'] . '/' . $entry;
                if (!is_file($file)
                    || !in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::ASSET_EXTENSIONS, true)
                ) {
                    continue;
                }
                $assets[$entry] = (string) file_get_contents($file);
            }
        }
        return $assets;
    }

    private function logoContent(PrintData $data): ?string
    {
        if ($data->logo === null || $this->branding === null) {
            return null;
        }
        $stored = $this->branding->findSlotFile('companyLogo');
        if ($stored === null) {
            return null;
        }
        $content = @file_get_contents($this->branding->getFilePath($stored));
        return $content === false || $content === '' ? null : $content;
    }

    private static function dataUri(string $fileName, string $content): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mime      = self::LOGO_MIME_TYPES[$extension] ?? 'application/octet-stream';
        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }
}
