<?php

namespace wkf\bboard\libs;
use \Shipard\UI\Core\WidgetBoard;


/**
 * Class WidgetBBoards
 */
class WidgetBBoards extends WidgetBoard
{
	/** @var  \wkf\bboard\TableBBoards */
	var $tableBBoards;
	var $usersBBoards = NULL;

	var $help = '';

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		$parts = explode ('-', $this->activeTopTab);

		$this->addContentViewer('wkf.bboard.msgs',
				'default', ['widgetId' => $this->widgetId, 'bboard' => $parts[1], 'viewerMode' => $viewerMode]);
	}

	public function init ()
	{
		$this->tableBBoards = $this->app->table ('wkf.bboard.bboards');
		$this->usersBBoards = $this->tableBBoards->usersBBoards();

		$this->createTabs();

		parent::init();
	}

	function createTabs ()
	{
		if (!$this->usersBBoards || !count($this->usersBBoards))
		{
			return;
		}

		foreach ($this->usersBBoards as $bboardNdx => $bb)
		{
			$icon = 'icon-file';
			if (isset($p['icon']) && $p['icon'] !== '')
				$icon = $p['icon'];

			$tab = [];
			$tab[] = ['text' => $bb['sn'], 'icon' => $icon, 'class' => ''];

			$tabs['bboard-'.$bb['ndx']] = ['line' => $tab, 'ntfBadgeId' => 'ntf-badge-bboards-p'.$bb['ndx'], 'action' => 'load-bboard-' . $bb['ndx']];
		}

		$this->toolbar = ['tabs' => $tabs];
	}

	public function title()
	{
		return FALSE;
	}
}
