<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Ai\AiCallOverride;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Module\Core\Exchange\Dataset\DatasetPreflight;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

/**
 * Preflight `ai-eval` (D39) — nic se nemění, při nálezu příkaz končí exit 2:
 * server jen v režimu `development`; země sady = země zdroje dat a schémata
 * (`DatasetPreflight`); zprávy zdroje dat jsou přesně zprávy sady
 * (`VerifyPreflight::membershipProblems`); **žádný doklad** ze sady není
 * použitý a **žádné pravidlo IČO → štítek** — historie řádků a pravidla by
 * pokryly štítky dřív než AI a běh by měřil něco jiného (zdroj dat po
 * `dataset-verify` se přeseeduje); profil varianty existuje a je aktivní;
 * volání funkce `mail.extraction` s přepsáním varianty jde sestavit.
 */
final class EvalPreflight
{
    public const string AI_FUNCTION = 'mail.extraction';

    public function __construct(
        private readonly string $serverMode,
        private readonly DatasetReader $reader,
        private readonly DataSourceConfig $dsConfig,
        private readonly DatasetPreflight $datasetPreflight,
        private readonly EvalDataSource $dataSource,
        private readonly EvalVariant $variant,
        private readonly AiFunctionResolver $resolver,
        private readonly AiCallOverride $override,
    ) {}

    /** @param list<string> $setMessageIds @return list<string> */
    public function problems(array $setMessageIds): array
    {
        $problems = [];
        if ($this->serverMode !== ServerAiConnection::MODE_DEVELOPMENT) {
            $problems[] = "server je v režimu {$this->serverMode} — ai-eval běží jen na vývojovém serveru (development)";
        }

        $check = $this->datasetPreflight->check($this->reader, $this->dsConfig->getCountry());
        foreach ($check['errors'] as $error) {
            $problems[] = $error;
        }
        if ($setMessageIds === []) {
            $problems[] = 'sada nemá žádnou zprávu v mail/';
        }

        // Čistota zdroje dat — totéž čte panel Zdroje dat dashboardu (D59).
        $status = EvalDataSourceStatus::inspect($this->dataSource, $setMessageIds);
        if ($status['membership'] !== []) {
            $problems[] = 'zdroj dat obsahuje data mimo sadu — ai-eval běží jen nad čistě naseedovaným zdrojem dat:';
            foreach ($status['membership'] as $p) {
                $problems[] = '  ' . $p;
            }
        }
        if ($status['documents'] > 0) {
            $problems[] = sprintf(
                'zdroj dat má %d doklad(ů) — použité doklady by přes historii řádků pokryly štítky dřív než AI; přeseeduj (shpd-ds dataset-seed <sada> -y)',
                $status['documents'],
            );
        }
        if ($status['tagRules'] > 0) {
            $problems[] = "zdroj dat má {$status['tagRules']} pravidel IČO → štítek — pravidlo přeskočí LLM klasifikaci štítků; přeseeduj";
        }
        foreach ($status['missing'] as $id) {
            $problems[] = "zpráva {$id} ze sady ve zdroji dat není — přeseeduj";
        }

        if ($this->dataSource->profile($this->variant->profile) === null) {
            $problems[] = "profil pošty '{$this->variant->profile}' z varianty ve zdroji dat není nebo není aktivní";
        }
        $preview = $this->resolver->preview(self::AI_FUNCTION, $this->override);
        if ($preview->error !== null) {
            $problems[] = 'volání funkce ' . self::AI_FUNCTION . ' nejde sestavit: ' . $preview->error->getMessage();
        }
        return $problems;
    }
}
