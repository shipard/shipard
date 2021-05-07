<?php

namespace E10Pro\Meters;
use \E10\utils, \E10\TableForm, \E10\Wizard;


/**
 * Class MetersWizard
 * @package E10Pro\Meters
 */
class MetersWizard extends Wizard
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
		$metersGroup = $this->app->testGetParam('metersGroup');
		$this->recData['metersGroup'] = $metersGroup;
		$this->addInput('metersGroup', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

		$units = $this->app()->cfgItem ('e10.witems.units');


		$q[] = 'SELECT meters.ndx as meterNdx, meters.shortName as meterName, meters.unit as meterUnit FROM [e10_base_doclinks] as links';
		array_push($q, ' LEFT JOIN e10pro_meters_meters AS meters ON links.dstRecId = meters.ndx');
		array_push($q, ' WHERE [srcTableId] = %s', 'e10pro.meters.groups', ' AND [srcRecId] = %i', $metersGroup);

		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->openRow();
				$this->addInput('meter-'.$r['meterNdx'], $r['meterName'], self::INPUT_STYLE_DOUBLE);
				$this->addStatic($units[$r['meterUnit']]['shortcut']);
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
	}
}
