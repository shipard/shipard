<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;

/**
 * Vykreslení promptu z `prompt_template` AI profilu (tasks/mail-analysis-
 * inprocess.md D17): Twig v sandboxu
 * ({@see AnalysisPromptPolicy}), `strict_variables`, bez autoescape.
 *
 * Kontext: `message` (`subject`, `sender_email`,
 * `sender_name`, `received_at`, `body_plain`, `body_html` — null jako
 * prázdný řetězec), `attachments[]` (`ndx`, `filename`, `mime_type`,
 * `kind`, `size_human`) a `output_schema`.
 *
 * Šablony vznikly pro Jinja2 s `trim_blocks` + `lstrip_blocks`. První
 * konec řádku za blokovým tagem stříhá Twig sám (jako `trim_blocks`),
 * `lstrip_blocks` nemá — doplní ho {@see jinjaCompatible()} nad zdrojem
 * před kompilací, aby byl výstup bajtově shodný s původním Jinja2
 * rendererem (šablony profilů vznikly pro něj; porovnatelnost reanalýzy
 * se staršími běhy; ověřeno proti jinja2 3.1) — profil se kvůli tomu nemění.
 */
final class PromptRenderer
{
    private readonly Environment $twig;

    public function __construct()
    {
        $this->twig = new Environment(new ArrayLoader(), [
            'cache' => false,
            'autoescape' => false,
            'strict_variables' => true,
        ]);
        $this->twig->addExtension(new SandboxExtension(AnalysisPromptPolicy::create(), true));
    }

    /**
     * @param array<string, mixed> $message Řádek zprávy (klíče jako
     *        `GET /payload`: subject, sender_email, sender_name,
     *        received_at, body_plain, body_html).
     * @param list<PreparedAttachment> $attachments
     * @param array<string, mixed>|\stdClass $outputSchema
     * @throws PromptRenderException
     */
    public function render(string $template, array $message, array $attachments, array|\stdClass $outputSchema): string
    {
        $context = [
            'message' => [
                'subject' => (string) ($message['subject'] ?? ''),
                'sender_email' => (string) ($message['sender_email'] ?? ''),
                'sender_name' => (string) ($message['sender_name'] ?? ''),
                'received_at' => (string) ($message['received_at'] ?? ''),
                'body_plain' => (string) ($message['body_plain'] ?? ''),
                'body_html' => (string) ($message['body_html'] ?? ''),
            ],
            'attachments' => array_map(
                static fn(PreparedAttachment $att): array => [
                    'ndx' => $att->ndx,
                    'filename' => $att->filename,
                    'mime_type' => $att->mimeType,
                    'kind' => $att->kind,
                    'size_human' => self::humanSize($att->sizeBytes()),
                ],
                $attachments,
            ),
            'output_schema' => $outputSchema instanceof \stdClass
                ? json_decode((string) json_encode($outputSchema), true)
                : $outputSchema,
        ];

        try {
            return $this->twig->createTemplate(self::jinjaCompatible($template))->render($context);
        } catch (TwigError $e) {
            throw new PromptRenderException($e->getMessage(), $e);
        }
    }

    /**
     * Jinja2 `lstrip_blocks`: mezery a tabulátory od začátku řádku před
     * blokovým tagem se zahodí. `trim_blocks` (první konec řádku za blokovým
     * tagem) dělá lexer Twigu sám — zdroj se kvůli němu nemění, jinak by se
     * stříhalo dvakrát. Výrazy `{{ }}` se nemění.
     */
    public static function jinjaCompatible(string $source): string
    {
        return (string) preg_replace('/^[ \t]+(?=\{%)/m', '', $source);
    }

    /** Velikost přílohy pro prompt: celočíselné dělení, jedno desetinné místo (tvar shodný s původním Python rendererem). */
    public static function humanSize(int $bytes): string
    {
        $n = $bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($n < 1024 || $unit === 'GB') {
                return $unit === 'B' ? "{$n} B" : sprintf('%.1f %s', $n, $unit);
            }
            $n = intdiv($n, 1024);
        }
        return "{$n} B";
    }
}
