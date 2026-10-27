<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

/**
 * Zadání úlohy (`job.json`, D58) — zapisuje ho jednou API před spuštěním
 * obalu a dál se nemění: typ, zdroj dat, sada, varianta, parametry, kroky,
 * čas a IP klienta, grace pro zrušení. Průběh a výsledek obalu žijí zvlášť
 * ve `state.json` ({@see JobState}), takže si API a obal nikdy nepřepisují
 * soubor navzájem.
 *
 * `cancelGraceSeconds` dodává volající podle typu (evaluace čeká na dobíhající
 * volání modelu až 14 minut — `AnalysisRequest::CALL_TIMEOUT_SECONDS`; tato
 * třída konstanty modulů neimportuje).
 */
final readonly class JobRecord
{
    public const string TYPE_EVAL = 'eval';
    public const string TYPE_VERIFY = 'verify';
    public const string TYPE_RESEED = 'reseed';
    public const string TYPE_CREATE = 'create';
    /** Smazání volného zdroje dat (`ds-delete <id> --yes`, tasks/ds-delete.md D70). */
    public const string TYPE_DELETE = 'delete';
    /** Aktualizace checkoutu (`dev-update --yes --job`, tasks/dev-dashboard-update.md D21). */
    public const string TYPE_UPDATE = 'update';
    /** Proklikání datové sady v prohlížeči (`dataset-e2e`, tasks/testing-phase7a.md D88, D91). */
    public const string TYPE_E2E = 'e2e';
    /** Video nad demo sadou (`video-run --verb check|build`, tasks/testing-phase7b.md D122, D125). */
    public const string TYPE_VIDEO = 'video';
    /**
     * Testovací kolo (`test-round-run <adresář>`, tasks/testing-phase8a.md D130, D134):
     * jeden krok s vlastní orchestrací, dílčí úlohy spouští přes {@see JobLauncher}
     * s odkazem `parent` na sebe.
     */
    public const string TYPE_ROUND = 'round';
    public const array TYPES = [self::TYPE_EVAL, self::TYPE_VERIFY, self::TYPE_RESEED, self::TYPE_CREATE, self::TYPE_DELETE, self::TYPE_UPDATE, self::TYPE_E2E, self::TYPE_VIDEO, self::TYPE_ROUND];
    /** Úlohy celého serveru — bez zdroje dat; souběžně nejvýš jedna téhož typu. */
    public const array SERVER_TYPES = [self::TYPE_UPDATE, self::TYPE_ROUND];
    /** Výlučné úlohy (#129 U1): vylučují jakoukoli jinou úlohu a naopak. Kolo výlučné není (D134). */
    public const array EXCLUSIVE_TYPES = [self::TYPE_UPDATE];
    /** Úlohy, které z dashboardu nejde zrušit ani vynutit (U2): přerušení by nechalo checkout v půli. */
    public const array UNCANCELLABLE_TYPES = [self::TYPE_UPDATE];

    /** `Ymd-His-<6 znaků a-z0-9>` — řaditelné podle času, bezpečné jako název adresáře. */
    public const string ID_REGEX = '/^\d{8}-\d{6}-[a-z0-9]{6}$/';
    private const string ID_CHARSET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @param array<string, mixed> $params
     * @param list<JobStep>        $steps
     * @param string|null          $parentJobId id kola, které úlohu spustilo jako dílčí (D134)
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $dataSourceId,
        public string $dataSourceName,
        public ?string $dataset,
        public ?string $variant,
        public array $params,
        public array $steps,
        public string $createdAt,
        public ?string $clientIp,
        public int $cancelGraceSeconds,
        public ?string $parentJobId = null,
    ) {
        if (preg_match(self::ID_REGEX, $id) !== 1) {
            throw new \InvalidArgumentException("Invalid job id '{$id}'");
        }
        if ($parentJobId !== null && preg_match(self::ID_REGEX, $parentJobId) !== 1) {
            throw new \InvalidArgumentException("Invalid parent job id '{$parentJobId}'");
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown job type '{$type}'");
        }
        if ($steps === []) {
            throw new \InvalidArgumentException('Job must have at least one step');
        }
        foreach ($steps as $step) {
            if (!$step instanceof JobStep) {
                throw new \InvalidArgumentException('Job steps must be JobStep instances');
            }
        }
        if ($dataSourceId === '' && !in_array($type, self::SERVER_TYPES, true)) {
            throw new \InvalidArgumentException("Job data source id must not be empty for type '{$type}'");
        }
        if ($cancelGraceSeconds < 0) {
            throw new \InvalidArgumentException('cancelGraceSeconds must not be negative');
        }
    }

    public static function newId(\DateTimeImmutable $now): string
    {
        $suffix = '';
        $max = strlen(self::ID_CHARSET) - 1;
        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::ID_CHARSET[random_int(0, $max)];
        }
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His') . '-' . $suffix;
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_REGEX, $id) === 1;
    }

    /** Úloha celého serveru (bez zdroje dat, {@see SERVER_TYPES}). */
    public function isServerWide(): bool
    {
        return in_array($this->type, self::SERVER_TYPES, true);
    }

    /** Výlučná úloha ({@see EXCLUSIVE_TYPES}): nesmí běžet vedle žádné jiné. */
    public function isExclusive(): bool
    {
        return in_array($this->type, self::EXCLUSIVE_TYPES, true);
    }

    /** Smí ji API zrušit / vynutit ({@see UNCANCELLABLE_TYPES}). */
    public function isCancellable(): bool
    {
        return !in_array($this->type, self::UNCANCELLABLE_TYPES, true);
    }

    /**
     * @param array<string, mixed> $raw
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $raw): self
    {
        $steps = [];
        foreach ((array) ($raw['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                throw new \InvalidArgumentException('Job step must be an object');
            }
            $steps[] = JobStep::fromArray($step);
        }
        $ds = is_array($raw['dataSource'] ?? null) ? $raw['dataSource'] : [];
        return new self(
            (string) ($raw['id'] ?? ''),
            (string) ($raw['type'] ?? ''),
            (string) ($ds['id'] ?? ''),
            (string) ($ds['name'] ?? ''),
            isset($raw['dataset']) ? (string) $raw['dataset'] : null,
            isset($raw['variant']) ? (string) $raw['variant'] : null,
            is_array($raw['params'] ?? null) ? $raw['params'] : [],
            $steps,
            (string) ($raw['createdAt'] ?? ''),
            isset($raw['clientIp']) ? (string) $raw['clientIp'] : null,
            (int) ($raw['cancelGraceSeconds'] ?? 0),
            isset($raw['parent']) ? (string) $raw['parent'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'dataSource' => $this->dataSourceId === '' ? null : ['id' => $this->dataSourceId, 'name' => $this->dataSourceName],
            'dataset' => $this->dataset,
            'variant' => $this->variant,
            'params' => $this->params,
            'steps' => array_map(static fn (JobStep $s): array => $s->toArray(), $this->steps),
            'createdAt' => $this->createdAt,
            'clientIp' => $this->clientIp,
            'cancelGraceSeconds' => $this->cancelGraceSeconds,
            'parent' => $this->parentJobId,
        ];
    }
}
