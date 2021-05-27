<?php

namespace Shipard\UI\Core;

use \Shipard\Base\BaseObject;
use \Shipard\UI\Core\SystemIcons;

/**
 * Class Icons
 */
class Icons extends BaseObject
{
	private string $iconsId = 'fa5';
	private SystemIcons $systemIcons;
	var ?array $iconsCfg = NULL;
	private ?array $modulesIcons = NULL;

	CONST itCss = 0, itLgt = 1;
	const icnDefault = 0;

	public function init()
	{
		$this->iconsId = $this->app()->cfgItem ('options.experimental.iconsTheme', 'fa5');
		$this->iconsCfg = $this->app()->cfgItem ('ui.app.icons.types.'.$this->iconsId);
		$this->systemIcons = new SystemIcons();
		$this->systemIcons->iconsId = $this->iconsId;
	}

	#[deprecated]
	public function cssClass($i)
	{
		if (strstr ($i, 'icon-') !== FALSE)
			return 'fas fa-'.substr($i, 5);

		return 'appIcon-'.$i;
	}

	public function systemIcon(int $i, string $addClass = '', string $element = 'i')
	{
		$si = $this->systemIcons->systemIcon($i);
		return $this->createIconElement($si, $addClass, $element);
	}

	public function icon(string $i, string $addClass = '', string $element = 'i')
	{
		if (!$this->modulesIcons)
		{
			$this->modulesIcons = unserialize (file_get_contents(__APP_DIR__.'/config/icons-'.$this->iconsId.'.data'));			
		}
		$icon = isset($this->modulesIcons[$i]) ? $this->modulesIcons[$i] : ['t' => 0, 'v' => ''];
		
		return $this->createIconElement($icon, $addClass, $element);
	}

	public function createIconElement(array $icon, string $addClass = '', string $element = 'i')
	{
		$c = '';
		if ($this->iconsCfg['type'] === self::itCss)
		{
			$class = "{$this->iconsCfg['cssBaseClass']}{$icon['v']}";
			if ($addClass !== '')
				$class .= ' '.$addClass;
			$c .= "<$element class='$class'>";
			$c .= "</{$element}>";
		}
		else
		{
			$class = "{$this->iconsCfg['cssBaseClass']}";
			if ($addClass !== '')
				$class .= ' '.$addClass;
			$c .= "<$element class='$class'>";
			$c .= $icon['v'];
			$c .= "</{$element}>";
		}
		
		return $c;
	}
}
