<?php

namespace e10pro\purchase;

use \lib\ui\FormDocumentSimple;


/**
 * Class MobilePurchase
 * @package e10pro\purchase
 */
class MobilePurchase extends FormDocumentSimple
{
	public function init()
	{
		parent::init();
		$this->classId = 'e10pro.purchase.MobilePurchase';
		$this->setTable('e10doc.core.heads');
	}

	public function createForm ()
	{
		$this->addColumnInput('weighingMachine', ['hidden' => 1]);
		$this->addColumnInput('docType', ['hidden' => 1]);
		$this->addColumnInput('warehouse', ['hidden' => 1]);


		$this->addColumnInput('weightIn', ['fromSensor' => $this->recData['weighingMachine']]);
		$this->addColumnInput('title', ['forceSelect' => 1, 'required' => 1, 'enumStyle' => 'radio']);

		$this->recData['_addPicture'] = $this->app->testGetParam ('addPicture');
		$this->addInput(FormDocumentSimple::itAddCameraPicture, '_addPicture', ['hidden' => 1]);
	}

	protected function columnInputEnum ($colId)
	{
		if ($colId === 'title')
		{
			$list = ['+0' => '+0', '+1' => '+1', '+2' => '+2', '+3' => '+4'];
			return $list;
		}

		return parent::columnInputEnum ($colId);
	}

	protected function columnInputLabel ($colId, $colDef)
	{
		if ($colId === 'title')
			return 'Poznámka';
		return parent::columnInputLabel ($colId, $colDef);
	}

	public function checkBeforeDone ()
	{
		$this->recData ['myBankAccount'] = intval($this->app->cfgItem('options.e10doc-buy.myBankAccountPurchases', 0));
	}

	public function checkAfterDone ()
	{
		$this->closeDocument();
	}

	protected function closeDocument ()
	{
		$docStatesDef = $this->app->model()->tableProperty ($this->table, 'states');
		if ($docStatesDef)
		{
			$f = $this->table->getTableForm ('edit', $this->recData['ndx']);

			if ($f->checkAfterSave())
				$this->table->dbUpdateRec ($f->recData);

			$this->table->checkDocumentState ($f->recData);
			$this->table->dbUpdateRec ($f->recData);
			$this->table->checkAfterSave2 ($f->recData);
		}
	}

	public function title1 ()
	{
		return 'Nový výkup';
	}
}
