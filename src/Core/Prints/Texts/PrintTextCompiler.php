<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Texts;

use Shipard\Core\Prints\PrintTranslator;
use Shipard\Core\Prints\Twig\PrintSecurityPolicy;
use Shipard\Core\Prints\Twig\PrintTwigExtension;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\TemplateWrapper;

/**
 * Twig pro uživatelské texty na tiscích (#90 D50) — text píše uživatel
 * v Nastavení, proto má vlastní prostředí s úzkou politikou sandboxu
 * `PrintSecurityPolicy::userTexts()`: výpis proměnné, `if` a formátovací
 * filtry tisku. K šablonám modulů se nedostane (žádný loader souborů),
 * nekešuje se na disk a neznámá proměnná je chyba.
 *
 * `compile()` sandbox rovnou ověří — zakázaný tag, filtr nebo funkce vyhodí
 * chybu dřív, než se text poprvé vykreslí. Na tom stojí validace formuláře
 * textu. Twig sám kontroluje politiku až při vykreslení, proto si kontrolu
 * vynucujeme (`ensureSecurityChecked()` je v Twigu označená jako interní;
 * že funguje, hlídá `PrintTextCompilerTest`).
 */
final class PrintTextCompiler
{
    private ?Environment $twig = null;

    /** @param PrintTranslator $translator Jazyk tisku — řídí podobu čísel a dat ve filtrech. */
    public function __construct(
        private readonly PrintTranslator $translator,
    ) {}

    /**
     * @throws Error Syntaktická chyba nebo prvek mimo politiku sandboxu.
     */
    public function compile(string $text): TemplateWrapper
    {
        $template = $this->environment()->createTemplate($text);
        $template->unwrap()->ensureSecurityChecked();
        return $template;
    }

    /**
     * Jde text použít? Vrací popis chyby pro uživatele, nebo null. Na jazyce
     * nezáleží — ověřuje se zápis, nic se nevykresluje.
     */
    public static function check(string $text): ?string
    {
        try {
            (new self(new PrintTranslator([], 'cs')))->compile($text);
        } catch (Error $e) {
            return self::describe($e);
        }
        return null;
    }

    /** Chyba Twigu bez interního názvu šablony: „<zpráva> (řádek N)“. */
    public static function describe(Error $error): string
    {
        $message = rtrim($error->getRawMessage(), '.');
        $line    = $error->getTemplateLine();
        return $line > 0 ? "{$message} (řádek {$line})" : $message;
    }

    private function environment(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(new ArrayLoader(), [
                'cache'            => false,
                'autoescape'       => false,
                'strict_variables' => true,
            ]);
            $this->twig->addExtension(new SandboxExtension(PrintSecurityPolicy::userTexts(), true));
            $this->twig->addExtension(new PrintTwigExtension($this->translator));
        }
        return $this->twig;
    }
}
