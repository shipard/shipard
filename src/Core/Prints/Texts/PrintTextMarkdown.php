<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Texts;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\RegexHelper;
use League\CommonMark\Util\Xml;

/**
 * Markdown uživatelského textu → HTML do stránky tisku (#90 D50).
 *
 * Výstup se do šablony vkládá bez escapování (`|raw`), proto musí být
 * bezpečný sám o sobě:
 *  - HTML ve vstupu se escapuje, nikdy nepropouští (`html_input: escape`);
 *  - odkaz se tiskne jako text s adresou v závorce, bez `<a>` — papír
 *    odkaz neotevře a nebezpečná adresa (`javascript:`) se nevypíše vůbec;
 *  - obrázek se nahradí svým popiskem — render služba nesmí na síť
 *    a `data:` adresu by jinak propustila i volba `allow_unsafe_links`.
 *
 * Rozsah: CommonMark + přeškrtnutí a automatické odkazy z GFM; tabulky
 * a seznamy úkolů ne.
 */
final class PrintTextMarkdown
{
    private ?MarkdownConverter $converter = null;

    public function toHtml(string $markdown): string
    {
        return trim($this->converter()->convert($markdown)->getContent());
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter === null) {
            $environment = new Environment([
                'html_input'         => 'escape',
                'allow_unsafe_links' => false,
                'max_nesting_level'  => 20,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new AutolinkExtension());
            $environment->addExtension(new StrikethroughExtension());
            $environment->addRenderer(Link::class, self::linkAsText(), 10);
            $environment->addRenderer(Image::class, self::imageAsText(), 10);

            $this->converter = new MarkdownConverter($environment);
        }
        return $this->converter;
    }

    /** „text (adresa)“; adresa shodná s textem nebo nebezpečná se neopakuje. */
    private static function linkAsText(): NodeRendererInterface
    {
        return new class implements NodeRendererInterface {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                Link::assertInstanceOf($node);

                $text = $childRenderer->renderNodes($node->children());
                $url  = $node->getUrl();
                $shown = preg_replace('/^mailto:/i', '', $url);
                if ($url === '' || RegexHelper::isLinkPotentiallyUnsafe($url)
                    || Xml::escape((string) $shown) === $text || Xml::escape($url) === $text
                ) {
                    return $text;
                }
                return $text . ' (' . Xml::escape((string) $shown) . ')';
            }
        };
    }

    private static function imageAsText(): NodeRendererInterface
    {
        return new class implements NodeRendererInterface {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                Image::assertInstanceOf($node);

                return $childRenderer->renderNodes($node->children());
            }
        };
    }
}
