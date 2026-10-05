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
use Twig\Runtime\EscaperRuntime;
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
    /** @var array<int, Environment> prostředí podle escapování (0 = žádné, 1 = Markdown) */
    private array $environments = [];

    /** @param PrintTranslator $translator Jazyk tisku — řídí podobu čísel a dat ve filtrech. */
    public function __construct(
        private readonly PrintTranslator $translator,
    ) {}

    /**
     * @param bool $markdown Výstup se bude zpracovávat jako Markdown (slot
     *        stránky tisku): vypsané hodnoty se pro něj escapují
     *        (`MarkdownEscaper`). Bez něj (e-mailový slot, kontrola zápisu)
     *        jdou hodnoty do výstupu tak, jak jsou.
     * @throws Error Syntaktická chyba nebo prvek mimo politiku sandboxu.
     */
    public function compile(string $text, bool $markdown = false): TemplateWrapper
    {
        // Název nese režim: Twig pojmenuje třídu šablony podle názvu a v rámci
        // procesu ji sdílí mezi prostředími — stejný text přeložený pro
        // Markdown by jinak vracel escapovaný výstup i e-mailovému slotu.
        $template = $this->environment($markdown)->createTemplate(
            $text,
            $markdown ? 'print text (markdown)' : 'print text',
        );
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

    private function environment(bool $markdown): Environment
    {
        if (!isset($this->environments[(int) $markdown])) {
            $twig = new Environment(new ArrayLoader(), [
                'cache'            => false,
                'autoescape'       => $markdown ? MarkdownEscaper::STRATEGY : false,
                'strict_variables' => true,
            ]);
            $twig->addExtension(new SandboxExtension(PrintSecurityPolicy::userTexts(), true));
            $twig->addExtension(new PrintTwigExtension($this->translator));
            // Twig předává escaperu hodnotu tak, jak je v datech — i null
            // (nevyplněný údaj) nebo číslo. Pole a objekt text nejsou.
            $twig->getRuntime(EscaperRuntime::class)->setEscaper(
                MarkdownEscaper::STRATEGY,
                static fn (mixed $value): string => $value === null || is_scalar($value) || $value instanceof \Stringable
                    ? MarkdownEscaper::escape((string) $value)
                    : throw new \InvalidArgumentException('Value of type ' . get_debug_type($value) . ' cannot be printed as text'),
            );
            $this->environments[(int) $markdown] = $twig;
        }
        return $this->environments[(int) $markdown];
    }
}
