<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Json;
use \Shipard\Base\Utility;


class IconsManager extends Utility
{
	var array $modulesIcons = [];
	var $uiDefs = NULL;

	public function createSystemIcons()
	{
		$systemIcons = $this->app()->loadCfgFile('ui/icons/system-icons.json');
		if (!$systemIcons)
			return $this->err("File 'ui/icons/system-icons.json' not found.");

		ksort($systemIcons);

		$this->createSystemIcons_constFile($systemIcons);
				
		$uiDefs = $this->app()->loadCfgFile('ui/ui.json');
		if (!$uiDefs)
			return $this->app()->err("ERROR: file `ui/ui.json` not found...");

		$iconsTypesList = [];
		$iconsTypesListShort = [];

		forEach ($uiDefs['icons'] as $iconsId => $iconsDef)
		{
			echo "* {$iconsId}\n";
			$iconsTypeDef = $this->app()->loadCfgFile('ui/icons/' . $iconsId . '/icons.json');
			if (!$iconsTypeDef)
				return $this->app->err("File `".'ui/icons/' . $iconsId . '/icons.json'."` is invalid.");

			$iconsTypesList[$iconsId] = $iconsTypeDef;
			$iconsTypesListShort[$iconsId] = $iconsTypeDef['fullName'];

			$data = [];	
			$idx = 0;
			foreach ($systemIcons as $iconId => $iconDef)
			{
				if (!isset($systemIcons[$iconId][$iconsId]))
				{
					return $this->app()->err("ERROR: system icon `$iconId` has not definition for `[$iconId]`...");
				}

				$iconValue = $iconDef[$iconsId];

				$icn = $iconDef[$iconsId] = ['t' => 0, 'v' => $iconValue];
				$data[$idx] = $icn;
				$idx++;
			}

			file_put_contents('ui/icons/'.$iconsId.'/system-icons-map.json', Json::lint($data));
			file_put_contents('ui/icons/'.$iconsId.'/system-icons-map.data', serialize($data));
		}

		file_put_contents('modules/e10/server/config/uiIcons.json', Json::lint($iconsTypesList)."\n");
		file_put_contents('modules/e10/server/config/uiIconsShort.json', Json::lint($iconsTypesListShort)."\n");
		
		return TRUE;
	}

	function createSystemIcons_constFile ($icons)
	{		
		$c = '';
		$c .= "<?php\n\n";
		$c .= "namespace Shipard\\UI\\Core;\n";
		$c .= "class SystemIcons\n";
		$c .= "{\n";
		//$c .= "\tstatic".'$'."path = __APP_DIR__.'/e10-modules/translation/dicts/".implode("/", $idParts)."';\n";
		//$c .= "\t static".'$'."baseFileName = '$className';\n";
		$c .= "\tvar \$iconsId;\n";
		$c .= "\tprivate ?array ".'$'."data = NULL;\n\n";

		$c .= "\tconst\n";
		$idNdx = 0;
		foreach ($icons as $iconId => $iconDef)
		{
			$c .= "\t\t";
			if ($idNdx)
				$c .= ",";
			else
				$c .= " ";
			$c .= $iconId." = ".$idNdx."\n";

			$idNdx++;
		}
		$c .= "\t;\n\n";

		$c .= "
		public function systemIcon(int \$i)
		{
			if (!\$this->data)
			{
				\$this->data = unserialize(file_get_contents(__SHPD_ROOT_DIR__ . 'ui/icons/'.\$this->iconsId.'/system-icons-map.data'));
			}
	
			return \$this->data[\$i];
		}
		";
		$c .= "}\n";

		file_put_contents('src/UI/Core/SystemIcons.php', $c);

		return $c;
	}


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

		$systemIcons = $this->app()->loadCfgFile(__SHPD_ROOT_DIR__.'ui/icons/system-icons.json');
		if (!$systemIcons)
			return $this->err("File 'ui/icons/system-icons.json' not found.");
		$this->addModulesIcons($systemIcons, 'system/');

		forEach ($this->uiDefs['icons'] as $iconsId => $iconsDef)
		{
			//echo Json::lint($this->modulesIcons[$iconsId]) . "\n----\n";
			file_put_contents('config/icons-' . $iconsId . '.json', Json::lint($this->modulesIcons[$iconsId]));
			file_put_contents('config/icons-' . $iconsId . '.data', serialize($this->modulesIcons[$iconsId]));
		}
	}
}
