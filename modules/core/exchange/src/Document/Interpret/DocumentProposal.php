<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\PayloadResolve;
use Shipard\Module\Core\Exchange\Document\SourceRefLookup;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * **Vstup návrhu** (#166 D53, U1): doklad z papírové podoby — pošta, ISDOC,
 * REST s `applyOptions.interpret: true`. Cesta je
 *
 *   interpretace → založení chybějících záznamů → pokyn → učení,
 *
 * tedy {@see DocumentInterpreter} udělá z předlohy a voleb uživatele hotový
 * doklad a {@see DocumentApplier} ho uloží jako každý jiný pokyn. Pravidla
 * odvozování tu nejsou — vstup návrhu jen skládá kroky a odpověď.
 *
 * Odpověď má tvar, na který je zvyklý review modal pošty: papírová podoba
 * (po normalizaci hodnot) + `_resolve` se zprávou interpretace + po uložení
 * `savedDocId`. Cesty nálezů a indexy řádků míří do **původního** payloadu
 * — nálezy pokynu nad hotovým dokladem se převádějí přes `rowMap`.
 */
class DocumentProposal
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';

    private const NOTHING_CREATED = ['supplier' => null, 'customer' => null, 'balanceParty' => null, 'supplierBank' => null, 'rowItems' => []];

    /** @var \Closure(mixed ...): void */
    private readonly \Closure $executeSql;

    private readonly SupplierCodeLearning $learning;

    /**
     * @param (\Closure(mixed ...): void)|null $executeSql zápis do databáze
     *        (učení kódů, vazba na zprávu) — `Connection::query()` je final,
     *        testy ho podstrkují
     */
    public function __construct(
        private readonly Connection $db,
        private readonly SchemaValidator $schemaValidator,
        private readonly DocumentInterpreter $interpreter,
        private readonly DocumentApplier $applier,
        private readonly PendingRecords $pendingRecords,
        ?\Closure $executeSql = null,
    ) {
        $this->executeSql = $executeSql ?? function (mixed ...$args): void {
            $this->db->query(...$args);
        };
        $this->learning = new SupplierCodeLearning($this->executeSql);
    }

    /**
     * Vstup návrhu nad zdrojem dat.
     *
     * @param array<string, TableDefinition> $tables aktivní tabulky zdroje dat
     * @param DocumentApplier|null $applier pokyn, kterým se hotový doklad
     *        uloží; null = vlastní (`DocumentApplier::create()`)
     */
    public static function create(
        Connection $db,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
        ?DocumentEventDispatcher $eventDispatcher = null,
        ?DocumentApplier $applier = null,
    ): self {
        return new self(
            $db,
            new SchemaValidator(SchemaLoader::default()),
            DocumentInterpreter::create($db, $config, $dsConfig, $tables),
            $applier ?? DocumentApplier::create($db, $config, $dsConfig, $registry, $tables, $eventDispatcher),
            PendingRecords::forDataSource($db, $config, $dsConfig, $registry, $tables),
        );
    }

    /**
     * Statická kontrola návrhu: schéma a kontroly předlohy, bez hledání
     * záznamů. Chyba v nálezech = 422 `validation_failed`.
     *
     * @param array<string, mixed> $canonical papírová podoba
     */
    public function validate(array $canonical): ApplyResult
    {
        $invalid = $this->schemaError($canonical);
        if ($invalid !== null) {
            return $invalid;
        }
        $checked = $this->interpreter->check($canonical);
        $enriched = self::withIssues($checked['paper'], $checked['issues']);

        return self::hasErrors($checked['issues'])
            ? ApplyResult::error('validation_failed', 'Validace dokumentu selhala.', $enriched, statusCode: 422)
            : ApplyResult::ok($enriched);
    }

    /**
     * Náhled návrhu: zpráva interpretace a částky, **které skončí na
     * dokladu** — ty počítá pokyn nad hotovým dokladem (`computed`), ne
     * interpretace. Nic nezapisuje; projde i s chybami v nálezech, o dalším
     * kroku rozhoduje klient. Validaci dokladu pro cílový stav náhled návrhu
     * nenese (review pracuje s konceptem).
     *
     * @param array<string, mixed> $canonical papírová podoba s volbami v `_resolve`
     */
    public function preview(array $canonical): ApplyResult
    {
        $invalid = $this->schemaError($canonical);
        if ($invalid !== null) {
            return $invalid;
        }

        $interpreted = $this->interpreter->interpret($canonical);
        $instruction = $this->applier->preview($interpreted->document);
        if ($instruction->success) {
            $resolve = is_array($instruction->canonical['_resolve'] ?? null) ? $instruction->canonical['_resolve'] : [];
        } else {
            // Hotový doklad má být platný pokyn — když není, náhled ukáže
            // údaje z předlohy místo výpočtu, jako při selhání výpočtu.
            ErrorLogger::warn('DocumentProposal::preview: instruction preview of the finished document failed', [
                'errorCode' => $instruction->errorCode,
            ]);
            $resolve = ['issues' => [DocumentApplier::COMPUTED_UNAVAILABLE_ISSUE]];
        }

        return ApplyResult::ok(self::response($interpreted, ProposalReport::withComputed($interpreted, $resolve)));
    }

    /**
     * Uložení návrhu. V **jedné transakci** (vlastní, nebo SAVEPOINT uvnitř
     * transakce volajícího): založení osob, účtu dodavatele a položek
     * z `pending` → pokyn nad hotovým dokladem s jejich referencemi → učení
     * dodavatelských kódů → vazba zprávy pošty na doklad. Chyba kteréhokoli
     * kroku vrátí vše, i založené záznamy.
     *
     * Před transakcí: doklad, na který zpráva pošty už ukazuje, a doklad se
     * stejným `source.ref` se vracejí beze změny (obsah se neposuzuje);
     * rozhodnutí, které chybí (`unresolved_required`) nebo neplatí
     * (`conflict`), a chyby v nálezech interpretace uložení zastaví.
     *
     * @param array<string, mixed> $canonical papírová podoba s volbami v `_resolve`
     */
    public function apply(array $canonical): ApplyResult
    {
        $options = is_array($canonical['applyOptions'] ?? null) ? $canonical['applyOptions'] : [];

        // Idempotence podle zprávy pošty: opakované použití návrhu (dvojklik,
        // opakování po výpadku) vrátí doklad, na který zpráva už ukazuje.
        // Výslovné přegenerování konceptu se jí neřídí.
        $applied = SourceRefLookup::replaceConceptId($options) === null ? $this->appliedFromMessage($canonical) : null;
        if ($applied !== null) {
            return $applied;
        }
        $existing = $this->applier->existingBySource($canonical);
        if ($existing !== null) {
            return $existing;
        }

        $invalid = $this->schemaError($canonical);
        if ($invalid !== null) {
            return $invalid;
        }

        $interpreted = $this->interpreter->interpret($canonical, saving: true);
        $issues = [...($interpreted->report['issues'] ?? []), ...$interpreted->decisionIssues];
        $enriched = self::response($interpreted, ProposalReport::forSave($interpreted, $issues));

        if ($interpreted->decisionError !== null) {
            return ApplyResult::error(
                $interpreted->decisionError['code'],
                $interpreted->decisionError['message'],
                $enriched,
                statusCode: $interpreted->decisionError['code'] === 'conflict' ? 409 : 422,
            );
        }
        if (self::hasErrors($issues)) {
            return ApplyResult::error('validation_failed', 'Validace dokumentu selhala.', $enriched);
        }

        // Souběh návrhů se stejným `source.ref`: zámek držený přes commit
        // celé transakce; první dotaz v ní doklad dohledá znovu.
        if (!$this->applier->lockSource($canonical)) {
            return ApplyResult::error(
                'source_ref_busy',
                'Jiný pokyn se stejným identifikátorem ve zdroji (source.ref) se právě ukládá — zkuste to znovu.',
                $enriched,
                statusCode: 409,
            );
        }
        try {
            try {
                [$saved, $created] = NestedTransaction::run($this->db, function () use ($canonical, $interpreted): array {
                    $existing = $this->applier->existingBySource($canonical);
                    if ($existing !== null) {
                        throw new ProposalStoppedException($existing, existing: true);
                    }
                    $created = $interpreted->pending !== []
                        ? $this->pendingRecords->createFrom($interpreted->pending)
                        : self::NOTHING_CREATED;

                    $saved = $this->applier->apply($interpreted->withCreated($created));
                    if (!$saved->success) {
                        throw new ProposalStoppedException($saved);
                    }

                    $this->learning->learnFrom($interpreted->learn, $created);
                    $this->linkMessage($canonical, (int) $saved->savedId);

                    return [$saved, $created];
                });
            } catch (ProposalStoppedException $e) {
                if ($e->existing) {
                    return $e->result;
                }
                return ApplyResult::error(
                    $e->result->errorCode ?? 'internal_error',
                    $e->result->errorMessage ?? 'Uložení dokladu selhalo.',
                    self::response($interpreted, ProposalReport::forSave($interpreted, self::withInstructionIssues($interpreted, $issues, $e->result))),
                    statusCode: $e->result->statusCode,
                );
            } catch (\Throwable $e) {
                return ApplyResult::error('internal_error', $e->getMessage(), $enriched, statusCode: 500);
            }
        } finally {
            $this->applier->unlockSource($canonical);
        }

        // Varování uloženého dokladu odpověď použití návrhu nenese (#166 U6)
        // — ukazuje je review; z pokynu jdou jen nálezy k cílům uložení.
        $final = self::response(
            $interpreted,
            ProposalReport::forSave(
                $interpreted,
                self::withInstructionIssues($interpreted, $issues, $saved, $this->applier->savedDocumentWarnings()),
                $created,
            ),
        );
        $final['savedDocId'] = $saved->savedId;

        return ApplyResult::ok($final, $saved->savedId);
    }

    /**
     * Nálezy odpovědi po běhu pokynu: nálezy interpretace a rozhodnutí,
     * doplnění pohybu řádků a to, co k hotovému dokladu řekl pokyn navíc
     * (cíle uložení, validace dokladu), s cestami v původním payloadu.
     *
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $without nálezy pokynu, které se nepřebírají
     * @return list<array<string, mixed>>
     */
    private static function withInstructionIssues(
        InterpretedDocument $interpreted,
        array $issues,
        ApplyResult $instruction,
        array $without = [],
    ): array {
        $issues = [...$issues, ...$interpreted->operationIssues];
        $reported = $instruction->canonical['_resolve']['issues'] ?? [];
        $reported = array_values(array_filter(
            is_array($reported) ? $reported : [],
            static fn (mixed $issue): bool => !in_array($issue, $without, true),
        ));
        return [...$issues, ...ProposalReport::instructionIssues($interpreted, $issues, $reported)];
    }

    /**
     * Odpověď návrhu: papírová podoba po normalizaci hodnot s blokem
     * `_resolve` — hotový doklad v ní není.
     *
     * @param array<string, mixed> $resolve
     * @return array<string, mixed>
     */
    private static function response(InterpretedDocument $interpreted, array $resolve): array
    {
        $paper = $interpreted->paper;
        $paper['_resolve'] = $resolve;
        return $paper;
    }

    /**
     * Payload, který neodpovídá schématu: 400 `schema_invalid` s nálezy
     * schématu přidanými k payloadu, jak přišel.
     *
     * @param array<string, mixed> $canonical
     */
    private function schemaError(array $canonical): ?ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, DocumentApplier::FORMAT_ID, DocumentApplier::FORMAT_VERSION);
        if ($schemaIssues === []) {
            return null;
        }
        return ApplyResult::error(
            'schema_invalid',
            'Struktura dokumentu neodpovídá schématu.',
            self::withIssues($canonical, $schemaIssues),
            statusCode: 400,
        );
    }

    /**
     * Payload s nálezy přidanými do `_resolve.issues` (bez bloků hledání).
     *
     * @param array<string, mixed> $canonical
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private static function withIssues(array $canonical, array $issues): array
    {
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $resolve['issues'] = array_merge(is_array($resolve['issues'] ?? null) ? $resolve['issues'] : [], $issues);
        $resolve['summary'] = PayloadResolve::summary([], $resolve['issues']);
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }

    /**
     * Zpráva pošty z `source.message` už ukazuje na doklad
     * (`target_table_id` + `target_row`) → existující doklad beze změny.
     *
     * @param array<string, mixed> $canonical
     */
    private function appliedFromMessage(array $canonical): ?ApplyResult
    {
        $messageNdx = self::messageNdx($canonical);
        if ($messageNdx === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [target_table_id], [target_row]
             FROM [' . self::MESSAGES_TABLE . ']
             WHERE [id] = %i',
            $messageNdx,
        );
        if ($row === null
            || empty($row['target_row'])
            || (string) $row['target_table_id'] !== 'docs_core_heads'
        ) {
            return null;
        }
        return DocumentApplier::alreadyAppliedResult($canonical, (int) $row['target_row']);
    }

    /**
     * Vazba zprávy na doklad — protějšek `docs_core_heads.source_message`,
     * zapsaný v transakci uložení. Verdikt nad analýzou a stav zprávy
     * zapisuje `MessageProposalApplier`.
     *
     * @param array<string, mixed> $canonical
     */
    private function linkMessage(array $canonical, int $savedDocId): void
    {
        $messageNdx = self::messageNdx($canonical);
        if ($messageNdx === null) {
            return;
        }
        ($this->executeSql)(
            'UPDATE [' . self::MESSAGES_TABLE . ']
             SET [target_table_id] = %s,
                 [target_row] = %i
             WHERE [id] = %i',
            'docs_core_heads', $savedDocId, $messageNdx,
        );
    }

    /** @param array<string, mixed> $canonical */
    private static function messageNdx(array $canonical): ?int
    {
        $messageNdx = $canonical['source']['message'] ?? null;
        return is_int($messageNdx) && $messageNdx > 0 ? $messageNdx : null;
    }

    /** @param list<array<string, mixed>> $issues */
    private static function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? null) === 'error') {
                return true;
            }
        }
        return false;
    }
}
