<?php

namespace e10mnf\core;

use \e10\utils;


/**
 * Class WorkOrderCardAnalysis
 * @package e10mnf\core
 */
class WorkOrderCardAnalysis extends \e10\DocumentCard
{
	public function createContentHeader ()
	{
		$recData = $this->recData;


		$this->tablePersons = $this->app->table ('e10.persons.persons');
		$this->personRecData = $this->tablePersons->loadItem ($recData['customer']);
		$hdr = [];//$this->table->createPersonHeaderInfo ($this->personRecData, $recData);
		$hdr ['icon'] = $this->table->tableIcon ($recData);
		$hdr ['class'] = 'e10-pane-header '.$this->docStateClass();

		$docInfo [] = ['text' => $recData ['docNumber'], 'icon' => 'icon-file'];
		$hdr ['info'][] = ['class' => 'title', 'value' => $docInfo];

		if (isset ($this->recData ['ndx']))
		{
			$currencyName = $this->app()->cfgItem ('e10.base.currencies.'.$recData['currency'].'.shortcut');

		}
		else
		{
			$hdr ['info'][] = ['class' => 'title', 'value' => 'Nová zakázka'];
		}

		$this->addContent('header', ['type' => 'tiles', 'tiles' => [$hdr], 'class' => 'panes']);
	}

	public function createTitle ()
	{
		$title = ['text' => $this->recData ['docNumber'], 'suffix' => 'test123', 'icon' => $this->table->tableIcon($this->recData)];

		$this->addContent('title', ['type' => 'line', 'line' => $title]);

		if ($this->recData['customer'])
			$subTitle = [['icon' => $this->tablePersons->tableIcon ($this->personRecData), 'text' => $this->personRecData['fullName']]];

		if ($this->recData['title'] !== '')
			$subTitle[] = ['text' => $this->recData['title'], 'class' => 'e10-off block'];
		$this->addContent('subTitle', ['type' => 'line', 'line' => $subTitle]);
	}

	public function createContentBody ()
	{
		$e = $this->table->analysisEngine();
		$e->setWorkOrder($this->recData['ndx']);
		$e->doIt();
		$e->createCardContent($this);
	}

	public function createContent ()
	{
		$this->createContentHeader ();
		$this->createContentBody ();
		$this->createTitle();
	}
}
