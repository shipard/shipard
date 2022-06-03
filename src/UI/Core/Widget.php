<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;



class Widget extends \Shipard\Base\BaseObject
{
	var $html;
	var $objectData = [];
	var $definition;
	var $widgetId = '';
	var $forceFullCode = 0;

	public function createMainCode ()
	{
		return '';
	}

	public function createToolbar () {return [];}
	public function createToolbarCode () {return '';}

	public function createTabsCode ()
	{
		$c = "";

		$details = $this->subWidgets ();
		if (count($details))
		{
			$cnt = 0;
			$small = 0;
			$firstClass = " class='active'";
			$c .= "<ul class='df2-detail-menu widget' id='mainWidgetTabs'>\n";
			foreach ($details as $detail)
			{
				if ($cnt === 6)
				{
					$small = 1;
					$c .= "</ul>\n";
					$c .= "<ul class='e10-panelMenu-small widget' id='smallWidgetTabs'>\n";
				}
				$c .= "<li data-subreport='{$detail['id']}'$firstClass";
				if (isset($detail['remote']))
					$c .= " data-remote='{$detail['remote']}'";

				if ($small)
					$c .= " title=\"".Utils::es ($detail['title'])."\"";

				$c .= '>';

				if (isset($detail['ntfBadgeId']))
					$c .= "<span class='e10-ntf-badge' id='{$detail['ntfBadgeId']}' style='display:none;'></span>";

				$c .= $this->app()->ui()->icon($detail['icon'], '', 'div');
				if (!$small)
					$c .= Utils::es ($detail['title']);
				$c .= '</li>';

				$firstClass = "";

				$cnt++;
			}
			$c .= "</ul>\n";
		}

		return $c;
	}

	public function setDefinition ($d)
	{
		if ($d === NULL)
			$this->definition = Utils::searchArray($this->app->cfgItem ('widgets'), 'class', $this->app->requestPath(2));
		else
		if (is_string($d))
			$this->definition = Utils::searchArray($this->app->cfgItem ('widgets'), 'class', $d);
		else
			$this->definition = $d;
	}

	public function subWidgets () {return [];}

	public function checkAccess ($widgetClass = FALSE)
	{
		if ($this->app->hasRole('all'))
			return 2;

		$wc = ($widgetClass === FALSE) ? $this->definition['class'] : $widgetClass;

		$allRoles = $this->app->cfgItem ('e10.persons.roles');
		$userRoles = $this->app->user()->data ('roles');

		$accessLevel = 0;

		forEach ($userRoles as $roleId)
		{
			$r = $allRoles[$roleId] ?? [];
			if (!isset ($r['widgets'][$wc]))
				continue;
			if ($r['widgets'][$wc] > $accessLevel)
				$accessLevel = $r['widgets'][$wc];
		}

		return $accessLevel;
	}
}

