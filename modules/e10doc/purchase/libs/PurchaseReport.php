<?php

namespace e10doc\purchase\libs;

class PurchaseReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.purchase.purchase');
	}

	public function loadData ()
	{
		parent::loadData();

		if ($this->recData ['paymentMethod'] === 4) // fakturace
			$this->data ['flags']['reportTitle'] = 'vážní lístek - podklad pro fakturu';
		else
		if ($this->recData ['paymentMethod'] === 6) // sběrný doklad
			$this->data ['flags']['reportTitle'] = 'sběrný vážní lístek';
		else
		if ($this->recData ['paymentMethod'] === 8) // likvidační protokol
			$this->data ['flags']['reportTitle'] = 'likvidační protokol';
		else
		if ($this->recData ['paymentMethod'] === 0) // příkaz k úhradě
			$this->data ['flags']['reportTitle'] = 'bezhotovostní platba';
		else
		if ($this->recData ['paymentMethod'] === 9) // šek
			$this->data ['flags']['reportTitle'] = 'úhrada šekem';
		else
		if ($this->recData ['paymentMethod'] === 10) // poštovní poukázka
			$this->data ['flags']['reportTitle'] = 'úhrada složenkou';
		else
			$this->data ['flags']['reportTitle'] = 'výdajový pokladní doklad';

		if ($this->recData['personType'] == 2)
		{
			$this->data ['person']['hotovostPrevzalJmeno'] = $this->recData['cashPersonName'];
			$this->data ['person']['hotovostPrevzalOP'] = $this->recData['cashPersonID'];
		}
		else
		{
			$this->data ['person']['hotovostPrevzalJmeno'] = $this->data ['person']['fullName'];
			forEach ($this->data ['person']['lists']['properties'] as $iii)
			{
				if ($iii['property'] === 'idcn')
					$this->data ['person']['hotovostPrevzalOP'] = $iii['value'];
			}
		}
		if ($this->recData ['paymentMethod'] !== 8) // likvidační protokol
			$this->data ['flags']['enablePrice'] = 1;

		/*
		$rowNumber = 1;
		forEach ($this->data ['rows'] as &$row)
		{
			$row ['rowNumber'] = $rowNumber;
			$rowNumber++;
			$row ['rowItemProperties'] = \E10\Base\getPropertiesTable ($this->table->app(), 'e10.witems.items', $row['item']);
		}
		*/
	}
} // class PurchaseReport
