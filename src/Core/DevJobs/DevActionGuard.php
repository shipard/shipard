<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\DataClass;
use Shipard\Core\Server\DataSourceInfo;

/**
 * Pojistka spouštěcích akcí dashboardu (tasks/testing-phase6b.md § 3, D60):
 * server v režimu `development`, cílový zdroj dat čitelný a s třídou **free**
 * (`realCopy`, chybějící nebo rozbitá třída = odmítnout — nikdy reseed
 * reálné kopie) a sada ze seznamu sad. Ochrana je tady, ne v UI; žádný
 * parametr požadavku ji neobchází. Seznam sad dodává volající (controller
 * z `EvalCatalog`), aby `Core\DevJobs` nezáviselo na modulech.
 */
final class DevActionGuard
{
    public const string CODE_FORBIDDEN = 'FORBIDDEN';
    public const string CODE_DS_NOT_FOUND = 'DS_NOT_FOUND';
    public const string CODE_DS_NOT_FREE = 'DS_NOT_FREE';
    public const string CODE_UNKNOWN_DATASET = 'UNKNOWN_DATASET';

    private function __construct()
    {
    }

    /**
     * @param list<string> $knownDatasets
     * @return array{code: string, status: int, message: string}|null null = akce smí proběhnout
     */
    public static function check(
        string $serverMode,
        ?DataSourceInfo $dataSource,
        bool $requireDataSource,
        ?string $dataset,
        array $knownDatasets,
    ): ?array {
        if ($serverMode !== ServerAiConnection::MODE_DEVELOPMENT) {
            return [
                'code' => self::CODE_FORBIDDEN,
                'status' => 403,
                'message' => "Launch actions run only on a development server (mode is '{$serverMode}')",
            ];
        }
        if ($requireDataSource) {
            if ($dataSource === null) {
                return ['code' => self::CODE_DS_NOT_FOUND, 'status' => 404, 'message' => 'Data source not found'];
            }
            if (!$dataSource->readable || $dataSource->dataClass !== DataClass::Free) {
                return [
                    'code' => self::CODE_DS_NOT_FREE,
                    'status' => 403,
                    'message' => "Data source {$dataSource->id} is not a free data source (data class "
                        . ($dataSource->readable ? $dataSource->dataClass->value . ($dataSource->dataClassExplicit ? '' : ', not set in main.json') : 'unknown — main.json unreadable')
                        . ') — reseed, verify and eval run only on free data sources',
                ];
            }
        }
        if ($dataset !== null && !in_array($dataset, $knownDatasets, true)) {
            return ['code' => self::CODE_UNKNOWN_DATASET, 'status' => 400, 'message' => "Unknown dataset '{$dataset}'"];
        }
        return null;
    }
}
