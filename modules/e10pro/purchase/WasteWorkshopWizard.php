<?php

namespace e10pro\purchase;


use \e10\TableForm, \e10\Wizard;


/**
 * Class WasteWorkshopWizard
 * @package e10pro\purchase
 */
class WasteWorkshopWizard extends Wizard
{
	var $wasteWorkshopEngine = NULL;

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt ();
		}
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Načíst provozovny'];


		$hdr ['info'][] = ['class' => 'info', 'value' => $this->wasteWorkshopEngine->personRecData['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'IČ: '.$this->wasteWorkshopEngine->personCID];

		return $hdr;
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->recData['personNdx'] = $this->app->testGetParam('personNdx');

		$this->openForm ();
			$this->addInput('personNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 30);
			$this->initWelcome();
		$this->closeForm ();
	}

	function initWelcome ()
	{
		$e = new \e10pro\purchase\WasteWorkshopEngine($this->app());
		$e->setPerson(intval($this->recData['personNdx']));
		$e->loadRemote();

		$this->wasteWorkshopEngine = $e;

		if (!count($e->remoteWorkshops))
		{
			$this->addStatic([['text' => 'Není registrována žádná provozovna', 'class' => 'e10-bold']]);
			$this->stepResult['lastStep'] = 1;
			return;
		}

		$cnt = 0;
		foreach (\e10\sortByOneKey($e->remoteWorkshops, 'order', TRUE) as $rwId => $rw)
		{
			$this->openRow();
				$this->addCheckBox('rw-'.$rwId, NULL, "1", 0, TRUE);

				$l = $rw['street'];
				$l .= ', '.$rw['zipcode'].' '.$rw['city'];

				$this->addStatic([['text' => $rwId, 'class' => 'e10-bold'], ['text' => $l, 'class' => '']]);
				if ($cnt < 3)
					$this->recData['rw-'.$rwId] = '1';

				$this->addInput('address-'.$rwId, '', self::INPUT_STYLE_STRING, TableForm::coHidden, 30);
				$this->recData['address-'.$rwId] = json_encode($rw);
			$this->closeRow();

			$cnt++;
		}
	}

	public function doIt ()
	{
		$personNdx = intval($this->recData['personNdx']);
		$saveIds = [];
		$addresses = [];

		foreach ($this->recData as $key => $value)
		{
			if (substr($key, 0, 3) === 'rw-')
			{
				if ($value != '1')
					continue;
				$parts = explode ('-', $key);
				$saveIds[] = $parts[1];

				continue;
			}
			if (substr($key, 0, 8) === 'address-')
			{
				$parts = explode ('-', $key);
				$id = $parts[1];
				$a = json_decode($value, TRUE);
				unset($a['order']);
				$addresses[$id] = $a;
			}
		}

		// -- save addresses
		$firstAddressNdx = 0;
		foreach ($saveIds as $id)
		{
			$exist = $this->app()->db()->query ('SELECT * FROM [e10_persons_address] WHERE tableid = %s', 'e10.persons.persons',
				' AND [recid] = %i', $personNdx, ' AND [specification] = %s', $id, 'AND [type] = %i', 99)->fetch ();

			if ($exist)
			{ // update
				if (!$firstAddressNdx)
					$firstAddressNdx = $exist['ndx'];
			}
			else
			{ // insert
				$newAddress = $addresses[$id];
				$newAddress['tableid'] = 'e10.persons.persons';
				$newAddress['recid'] = intval($personNdx);

				$this->app()->db()->query ('INSERT INTO [e10_persons_address]', $newAddress);
				$newNdx = intval ($this->app()->db()->getInsertId ());
				if (!$firstAddressNdx)
					$firstAddressNdx = $newNdx;
			}
		}

		// -- update documents
		$this->app()->db()->query ('UPDATE [e10doc_core_heads] SET otherAddress1 = ', $firstAddressNdx,
			' WHERE [person] = %i', $personNdx, ' AND [dateAccounting] >= %d', '2016-01-01',
			' AND [docType] IN %in', ['purchase', 'stockin'], ' AND [otherAddress1] IS NULL');

		$this->stepResult ['close'] = 1;
	}
}
