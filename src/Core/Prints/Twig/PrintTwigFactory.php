<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Twig;

use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\PrintTranslator;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Staví Twig prostředí tisků: šablony z adresářů `prints/` modulů (Twig
 * namespace = id modulu, `@docs.core/_layout/doc-base.html.twig`), sandbox
 * zapnutý globálně, autoescape HTML a striktní proměnné — překlep v šabloně
 * je chyba, ne prázdné místo na faktuře.
 *
 * Prostředí je per běh tisku: filtry a `t()` jsou vázané na jazyk tisku.
 */
final class PrintTwigFactory
{
    /**
     * @param ?string $cacheDir Adresář kompilovaných šablon (`<ds>/cache/twig`);
     *        null = bez cache.
     */
    public function __construct(
        private readonly PrintTemplatePaths $paths,
        private readonly ?string $cacheDir = null,
    ) {}

    public function create(PrintTranslator $translator): Environment
    {
        $loader = new FilesystemLoader();
        foreach ($this->paths->moduleRoots() as $moduleId => $root) {
            $loader->addPath($root, $moduleId);
        }

        $twig = new Environment($loader, [
            'cache'            => $this->cacheDir ?? false,
            // Šablony se mění s nasazením — bez kontroly mtime by cache
            // po upgradu servírovala starou verzi.
            'auto_reload'      => true,
            'autoescape'       => 'html',
            'strict_variables' => true,
        ]);
        $twig->addExtension(new SandboxExtension(PrintSecurityPolicy::templates(), true));
        $twig->addExtension(new PrintTwigExtension($translator));

        return $twig;
    }
}
