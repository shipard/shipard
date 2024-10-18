<?php

namespace e10mnf\core\libs;


/**
 * class WorkInProgressWidget
 */
class WorkInProgressWidget extends \Shipard\UI\Core\WidgetPane
{

	function renderData()
	{
		$this->renderDataWorkRecs();
	}

	function renderDataWorkRecs()
	{
		$wip = new \e10mnf\core\libs\WorkInProgressEngine($this->app());
		$wip->init();
		$wip->loadState();

		if ($this->widgetAction === 'action-wip-startWork')
		{
			$wip->startWork();
			$wip->loadState();
		}
		elseif ($this->widgetAction === 'action-wip-endWork')
		{
			$wip->endWork();
			$wip->loadState();
		}

		$c = '';

		$c .= $wip->buttonCode();

    if ($this->app()->mobileMode)
		  $this->addContent (['pane' => '', 'type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
    else
      $this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}


	public function createContent ()
	{
		$this->renderData();
	}

	public function title()
	{
		return FALSE;
	}
}
