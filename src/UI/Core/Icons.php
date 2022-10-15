<?php

namespace Shipard\UI\Core;

use \Shipard\Base\BaseObject;

/**
 * Class Icons
 */
class Icons extends BaseObject
{
	private string $iconsId = 'fa5';
	var ?array $iconsCfg = NULL;
	private ?array $modulesIcons = NULL;

	CONST itCss = 0, itLgt = 1;
	const icnDefault = 0;

	public function init()
	{
		$this->iconsId = $this->app()->cfgItem ('options.appearanceApp.iconsTheme', 'fa5');
		$this->iconsCfg = $this->app()->cfgItem ('ui.app.icons.types.'.$this->iconsId, []);
	}

	#[deprecated]
	public function cssClass($i)
	{
		if (strstr ($i, 'icon-') !== FALSE)
			return 'fas fa-'.substr($i, 5);

		return 'appIcon-'.$i;
	}

	public function icon(string $i, string $addClass = '', string $element = 'i', string $params = '')
	{
		if (!$this->modulesIcons)
		{
			$this->modulesIcons = unserialize (file_get_contents(__APP_DIR__.'/config/icons-'.$this->iconsId.'.data'));
		}
		$icon = isset($this->modulesIcons[$i]) ? $this->modulesIcons[$i] : ['t' => 0, 'v' => ''];

		return $this->createIconElement($icon, $addClass, $element, $params);
	}

	public function createIconElement(array $icon, string $addClass = '', string $element = 'i', string $params = '')
	{
		$c = '';
		if ($this->iconsCfg['type'] === self::itCss)
		{
			$class = "{$this->iconsCfg['cssBaseClass']}{$icon['v']}";
			if ($addClass !== '')
				$class .= ' '.$addClass;
			$c .= "<$element class='$class'$params>";
			$c .= "</{$element}>";
		}
		else
		{
			$class = "{$this->iconsCfg['cssBaseClass']}";
			if ($addClass !== '')
				$class .= ' '.$addClass;
			$c .= "<$element class='$class'$params>";
			$c .= $icon['v'];
			$c .= "</{$element}>";
		}

		return $c;
	}

	public function exist(string $i)
	{
		if (!$this->modulesIcons)
		{
			$this->modulesIcons = unserialize (file_get_contents(__APP_DIR__.'/config/icons-'.$this->iconsId.'.data'));
		}

		return isset($this->modulesIcons[$i]);
	}
}
