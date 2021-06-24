<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Json;
use \Shipard\Base\Utility;


class IconsManager extends Utility
{
	var array $modulesIcons = [];
	var $uiDefs = NULL;

	protected function scanModulesDir($path, $level)
	{
		$scanMask = $path . '/*';
		forEach (glob($scanMask, GLOB_ONLYDIR) as $subPath)
		{
			if (!is_file($subPath.'/module.json'))
			{
				if (!$this->scanModulesDir($subPath, $level + 1))
					return FALSE;
				continue;
			}
			if (!is_file($subPath . '/config/_icons.json'))
				continue;

			//echo str_repeat(' ', $level) . $subPath . "\n";

			$icons = $this->app()->loadCfgFile($subPath . '/config/_icons.json');
			if (!$icons)
			{
				echo "ERROR: file `" . $subPath . '/config/_icons.json' . "` is invalid\n";
				return FALSE;
			}

			$this->addModulesIcons($icons);

			foreach ($icons as $iconId => $iconDef)
			{
				forEach ($this->uiDefs['icons'] as $iconsId => $iconsDef)
				{
					if (!isset($iconDef[$iconsId]))
					{
						return $this->app()->err("ERROR: system icon `$iconId` in file `".$subPath . '/config/_icons.json'."`has not definition for `[$iconsId]`...");
					}
	
					$iconValue = $iconDef[$iconsId];
	
					$icn = $iconDef[$iconsId] = ['t' => 0, 'v' => $iconValue];
					$this->modulesIcons[$iconsId][$iconId] = $icn;
				}
			}
		}

		return TRUE;
	}

	protected function addModulesIcons(array $icons, string $prefix = '')
	{
		foreach ($icons as $iconId => $iconDef)
		{
			forEach ($this->uiDefs['icons'] as $iconsId => $iconsDef)
			{
				if (!isset($iconDef[$iconsId]))
				{
					return $this->app()->err("ERROR: system icon `$iconId` in file `".$subPath . '/config/_icons.json'."`has not definition for `[$iconsId]`...");
				}

				$iconValue = $iconDef[$iconsId];

				$icn = $iconDef[$iconsId] = ['t' => 0, 'v' => $iconValue];
				$this->modulesIcons[$iconsId][$prefix.$iconId] = $icn;
			}
		}
	}

	public function createModulesIcons()
	{
		$this->uiDefs = $this->app()->loadCfgFile(__SHPD_ROOT_DIR__.'/ui/ui.json');
		if (!$this->uiDefs)
			return $this->app()->err("ERROR: file `ui/ui.json` not found...");

		$this->scanModulesDir(__SHPD_MODULES_DIR__, 0);

		// -- system icons
		$systemIcons = $this->app()->loadCfgFile(__SHPD_ROOT_DIR__.'ui/icons/system-icons.json');
		if (!$systemIcons)
			return $this->err("File 'ui/icons/system-icons.json' not found.");
		$this->addModulesIcons($systemIcons, 'system/');

		// -- user icons
		$userIcons = $this->app()->loadCfgFile(__SHPD_ROOT_DIR__.'ui/icons/user-icons.json');
		if (!$userIcons)
			return $this->err("File 'ui/icons/user-icons.json' not found.");
		$this->addModulesIcons($userIcons);

		forEach ($this->uiDefs['icons'] as $iconsId => $iconsDef)
		{
			//echo Json::lint($this->modulesIcons[$iconsId]) . "\n----\n";
			file_put_contents('config/icons-' . $iconsId . '.json', Json::lint($this->modulesIcons[$iconsId]));
			file_put_contents('config/icons-' . $iconsId . '.data', serialize($this->modulesIcons[$iconsId]));
		}
	}
}
