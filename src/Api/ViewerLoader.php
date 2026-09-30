<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Viewer\ViewerRegistry;

class ViewerLoader
{
	/**
	 * Build a ViewerRegistry from resolved modules for a data source.
	 */
	public static function load(DataSourceConfig $config, ModulePathResolver $resolver, string $language): ViewerRegistry
	{
		$allModules      = ModuleLoader::loadAllModules($resolver);
		$errors          = [];
		$resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

		$registry = new ViewerRegistry();
		$registry->loadFromModules($resolvedModules, $language);
		$registry->setDsConfig($config);

		return $registry;
	}
}
