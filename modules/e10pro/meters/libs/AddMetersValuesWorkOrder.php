<?php

namespace e10pro\meters\libs;
use \E10\utils, \Shipard\Form\TableForm, \Shipard\Form\Wizard;


/**
 * class AddMetersValuesWorkOrder
 */
class AddMetersValuesWorkOrder extends Wizard
{
	protected $newPersonNdx = 0;
	protected $tablePersons;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->tablePersons = $this->app()->table ('e10.persons.persons');
			$this->save();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function addValues ()
	{
		$workOrder = intval($this->app->testGetParam('workOrder'));
		$this->recData['workOrder'] = $workOrder;
		$this->addInput('workOrder', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

		$units = $this->app()->cfgItem ('e10.witems.units');

    $q = [];
    array_push ($q, 'SELECT [meters].*');
    array_push ($q, ' FROM [e10pro_meters_meters] AS meters');
		array_push($q, ' WHERE [workOrder] = %i', $workOrder);

		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->openRow();
        $title = $r['fullName'];
				$this->addInput('meter-'.$r['ndx'], $title, self::INPUT_STYLE_DOUBLE);
				$this->addStatic($units[$r['unit']]['shortcut']);
			$this->closeRow();
		}
	}

	public function renderFormWelcome ()
	{
		$this->recData['datetime'] = new \DateTime();

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addValues();
			$this->addInput('datetime', 'Datum a čas', self::INPUT_STYLE_DATETIME);
		$this->closeForm ();
	}

	public function save ()
	{
		foreach ($this->recData as $colId => $colValue)
		{
			if (substr($colId, 0, 6) !== 'meter-')
				continue;

			$meterNdx = intval(substr($colId, 6));

			$newValue = [
				'meter' => $meterNdx, 'value' => $colValue, 'created' => new \DateTime(),
				'dateTime' => $this->recData['datetime'], 'author' => $this->app()->user()->data ('id'),
				'docState' => 4000, 'docStateMain' => 2
			];

			$this->app()->db ()->query ('INSERT INTO [e10pro_meters_values]', $newValue);
		}

		$this->stepResult ['close'] = 1;
    $this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

  public function createHeader ()
	{
    $workOrderInfo = $this->app()->db()->query('SELECT [title] FROM [e10mnf_core_workOrders] WHERE ndx = %i', $this->recData['workOrder'])->fetch();
		$hdr = [];
		$hdr ['icon'] = 'tables/e10pro.meters.values';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přidat nový odečet'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $workOrderInfo['title'] ?? '!!!'];

		return $hdr;
	}
}
