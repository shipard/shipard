<?php

namespace E10\Witems;

use E10\Application, E10\TableForm, E10\Wizard, E10\utils;


/**
 * MergeItemsWizard
 *
 */

class MergeItemsWizard extends Wizard
{
	var $mergeTargetNdx = 0;
	var $mergedNdxs = array();

	public function __construct($app, $options = NULL)
	{
		parent::__construct($app, $options);

		$this->dirtyColsReferences['item1'] = 'e10.witems.items';
		$this->dirtyColsReferences['item2'] = 'e10.witems.items';
		$this->dirtyColsReferences['item3'] = 'e10.witems.items';
	}

	public function doStep ()
	{
		if ($this->pageNumber == 2)
		{
			$this->mergeItems();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormCheck (); break;
			case 2: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->table = $this->app->table ('e10.witems.items');

		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->recData['mergeTargetNdx'] = $this->focusedPK;

		// -- check merged items
		$mi = $this->app->testGetParam('mergedItems');
		if ($mi != '')
		{
			$mip = explode (',', $mi);
			$micnt = 0;
			foreach ($mip as $miNum)
			{
				if ($miNum == $this->recData['mergeTargetNdx'])
					continue;
				$micnt++;
				$this->recData['item'.$micnt] = $miNum;
				if ($micnt === 3)
					break;
			}
		}

		$this->openForm ();
			$this->addInput('mergeTargetNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInputIntRef ('item1', 'e10.witems.items', 'Položka 1');
			$this->addInputIntRef ('item2', 'e10.witems.items', 'Položka 2');
			$this->addInputIntRef ('item3', 'e10.witems.items', 'Položka 3');

			$this->layoutOpen(TableForm::ltGrid);
				$this->addCheckBox('deleteMergedItems', 'Smazat po sloučení', 1, TableForm::coColW6);
			$this->layoutClose();
		$this->closeForm ();
	}

	public function renderFormCheck ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('mergeTargetNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('item1', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('item2', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('item3', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('deleteMergedItems', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 3);
		$this->closeForm ();
	}

	public function mergeItems ()
	{
		$this->merge();
		$this->stepResult ['close'] = 1;
	}

	public function merge ()
	{
		$this->mergeTargetNdx = intval($this->recData['mergeTargetNdx']);
		$mndx = intval ($this->recData['item1']); if ($mndx != 0) $this->mergedNdxs[] = $mndx;
		$mndx = intval ($this->recData['item2']); if ($mndx != 0) $this->mergedNdxs[] = $mndx;
		$mndx = intval ($this->recData['item3']); if ($mndx != 0) $this->mergedNdxs[] = $mndx;

		$mergeClasses = $this->app()->cfgItem ('registeredClasses.mergeRecords.e10-witems-items', FALSE);
		forEach ($mergeClasses as $classId)
		{
			$o = $this->app()->createObject ($classId);
			$o->setMergeParams ($this->mergeTargetNdx, $this->mergedNdxs, $this->recData['deleteMergedItems']);
			$o->merge();
		}


	}

	public function createHeader ()
	{
		$hdr = array ();
		$hdr ['icon'] = 'icon-code-fork';

		$hdr ['info'][] = array ('class' => 'title', 'value' => 'Sloučení položek');
		$item = $this->app()->loadItem ($this->recData['mergeTargetNdx'], 'e10.witems.items');
		$hdr ['info'][] = array ('class' => 'info', 'value' => $item['fullName'].' ('.$item['id'].')');

		return $hdr;
	}


}
