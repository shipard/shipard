<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\ExtensionDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Utils\JsoncParser;

class TableLoader
{
	/**
	 * Load all table definitions for a data source, localized to the requested language.
	 *
	 * @return array<string, TableDefinition>  Indexed by table name
	 */
	public static function load(DataSourceConfig $config, ModulePathResolver $resolver, string $language = 'en'): array
	{
		$allModules      = ModuleLoader::loadAllModules($resolver);
		$errors          = [];
		$resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

		$tableDefs = [];

		foreach ($resolvedModules as $module) {
			$modulePath = $resolver->getPath($module->id);
			if ($modulePath === null) continue;

			foreach ($module->tables as $tableFile) {
				$filePath  = $modulePath . '/tables/' . $tableFile . '.jsonc';
				$raw       = JsoncParser::parseFile($filePath);
				$localized = ConfigLocalizer::localize($raw, $language);
				$tableDefs[$tableFile] = TableDefinition::fromArray($localized);
			}
		}

		foreach ($resolvedModules as $module) {
			$modulePath = $resolver->getPath($module->id);
			if ($modulePath === null) continue;

			foreach ($module->extensions as $extFile) {
				$filePath = $modulePath . '/extensions/' . $extFile . '.jsonc';
				$extData  = JsoncParser::parseFile($filePath);
				// Sloupce z extension nesou popisky stejně jako sloupce tabulky —
				// bez lokalizace by zůstaly v holém (anglickém) `name`.
				$ext      = ExtensionDefinition::fromArray(ConfigLocalizer::localize($extData, $language));

				if (isset($tableDefs[$ext->table])) {
					$tableDefs[$ext->table] = TableMerger::merge($tableDefs[$ext->table], $ext);
				}
			}
		}

		return $tableDefs;
	}
}
