<?php

namespace Shipard\Form;
use \Shipard\Base\Content;


class FormSidebar extends Content
{
	var $html = '';
	var $tabs = [];

	public function addTab ($tabId, $name, $icon = '', $title = '')
	{
		$this->tabs[$tabId] = ['name' => $name, 'icon' => $icon, 'title' => $title];
	}

	public function setTabContent ($tabId, $content)
	{
		$this->tabs[$tabId]['content'] = $content;
	}

	public function createHtmlCode ()
	{
		$c = '';

		if (count($this->tabs) === 1)
		{
			forEach ($this->tabs as $tabId => $t)
			{
				$c .= $t['content'];
			}
		}
		else
		{
			$id = 'sdbr' . mt_rand () . '_' . time();
			$tabId = 1;
			$active = ' active';
			$c .= "<ul class='e10-sidebar-tabs' id='$id'>";
			forEach ($this->tabs as $tabId => $t)
			{
				$c .= "<li class='tab$active df2-action-trigger' data-action='sidebar-tab' data-tab-id='{$id}_$tabId'";
				if ($t['title'] !== '')
					$c .= ' title="'.utils::es ($t['name']).'"';
				$c .= '>';
				if ($t['icon'] !== '')
				{
					$i = $this->app()->ui()->icons()->cssClass($t['icon']);
					$c .= "<icon class='$i'></icon> ";
				}
				if ($t['name'] !== '')
					$c .= utils::es ($t['name']);

				$c .= '</li>';

				$tabId++;
				$active = '';
			}
			$c .= '</ul>';


			$tabId = 1;
			$active = ' active';
			forEach ($this->tabs as $tabId => $t)
			{
				$c .= "<div class='e10-sidebar-tab-content$active' id='{$id}_$tabId'>";
				$c .= $t['content'];
				$c .= '</div>';
				$tabId++;
				$active = '';
			}
		}
		return $c;
	}

}

