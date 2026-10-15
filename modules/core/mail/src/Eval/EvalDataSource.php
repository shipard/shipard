<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Čtení zdroje dat pro evaluaci (`ai-eval`): zprávy, doklady a pravidla
 * pro preflight (D39), řádek zprávy a profil pošty pro výpočet. Jen SELECTy;
 * testy třídu nahrazují podtřídou s poli.
 */
class EvalDataSource
{
    /** @var list<string>|null */
    private ?array $tables = null;

    public function __construct(private readonly DataSourceConnection $db) {}

    public function tableExists(string $table): bool
    {
        $this->tables ??= $this->db->getAllTableNames();
        return in_array($table, $this->tables, true);
    }

    /** @return array<string, int> `message_id` → id, všechny zprávy zdroje dat (i Koš) */
    public function messageNdxByCode(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, message_id FROM core_mail_incoming_messages') as $row) {
            $out[(string) $row['message_id']] = (int) $row['id'];
        }
        return $out;
    }

    /** @return array<int, int|null> id dokladu → `source_message`, všechny stavy vč. Koše; bez tabulky [] */
    public function documentSources(): array
    {
        if (!$this->tableExists('docs_core_heads')) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, source_message FROM docs_core_heads') as $row) {
            $out[(int) $row['id']] = $row['source_message'] !== null ? (int) $row['source_message'] : null;
        }
        return $out;
    }

    /** Pravidla IČO → štítek (`core_exchange_tag_rules`); bez tabulky 0. */
    public function tagRuleCount(): int
    {
        if (!$this->tableExists('core_exchange_tag_rules')) {
            return 0;
        }
        return (int) $this->db->fetchSingle('SELECT COUNT(*) FROM core_exchange_tag_rules');
    }

    /**
     * Řádek zprávy pro výpočet (tytéž sloupce jako ostrý runner).
     *
     * @return array<string, mixed>
     * @throws EvalException
     */
    public function messageRow(int $ndx): array
    {
        $row = $this->db->fetchRow(
            'SELECT subject, sender_email, sender_name, body_plain, body_html, received_at, raw_source_attachment
               FROM core_mail_incoming_messages WHERE id = %i',
            $ndx,
        );
        if ($row === null) {
            throw new EvalException("zpráva #{$ndx} ve zdroji dat není");
        }
        return $row;
    }

    /**
     * Aktivní profil pošty podle kódu (`profile_id`).
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $code): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM core_mail_ai_profiles WHERE profile_id = %s AND is_active = %i LIMIT 1',
            $code,
            1,
        );
    }
}
