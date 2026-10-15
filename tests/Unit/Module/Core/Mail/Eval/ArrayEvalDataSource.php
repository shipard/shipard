<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Eval\EvalDataSource;

/** Zdroj dat z polí (bez DB) pro testy evaluace. */
final class ArrayEvalDataSource extends EvalDataSource
{
    /**
     * @param array<string, int>   $ndxByCode
     * @param array<int, int|null> $docs
     * @param array<string, mixed>|null $profile
     */
    public function __construct(
        DataSourceConnection $db,
        private readonly array $ndxByCode,
        private readonly array $docs = [],
        private readonly int $tagRules = 0,
        private readonly ?array $profile = ['id' => 1, 'profile_id' => 'czech_general', 'prompt_version' => 'v4.7.1', 'prompt_template' => 'T', 'output_schema' => '{"type":"object"}'],
    ) {
        parent::__construct($db);
    }

    public function messageNdxByCode(): array
    {
        return $this->ndxByCode;
    }

    public function documentSources(): array
    {
        return $this->docs;
    }

    public function tagRuleCount(): int
    {
        return $this->tagRules;
    }

    public function messageRow(int $ndx): array
    {
        return ['subject' => "msg {$ndx}", 'sender_email' => 'a@example.com', 'sender_name' => null, 'body_plain' => '', 'body_html' => null, 'received_at' => '2026-08-26 08:00:00', 'raw_source_attachment' => 0];
    }

    public function profile(string $code): ?array
    {
        return $this->profile !== null && $this->profile['profile_id'] === $code ? $this->profile : null;
    }
}
