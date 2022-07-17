<?php

namespace e10mnf\core;

use \e10\utils, \Shipard\Form\TableFormShow, \e10\TableForm;


/**
 * Class WorkOrderShow
 * @package e10mnf\core
 */
class WorkOrderShow extends TableFormShow
{
	var $workOrderEngine;

	public function renderForm ()
	{
		$this->loadData();

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('sidebarWidth', '0.33');

		$tabs ['tabs'][] = ['text' => 'Rozbor', 'icon' => 'icon-pie-chart'];

		$this->openForm (TableForm::ltNone);
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$c = [];
					$this->workOrderEngine->createContentAll($c);
					$this->addContent($c);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	function loadData()
	{
		$this->workOrderEngine = $this->table->analysisEngine();
		$this->workOrderEngine->setWorkOrder($this->recData['ndx']);
		$this->workOrderEngine->doIt();
	}
}
