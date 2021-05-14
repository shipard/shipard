<?php

namespace e10doc\bankorder\libs;
use \Shipard\Form\TableForm;
use \Shipard\Application\DataModel;


class FormBankOrderRow extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);

		$this->openForm (self::ltGrid);
			$this->openRow ();
				$this->addColumnInput ("symbol1", self::coColW3);
				$this->addColumnInput ("symbol2", self::coColW2);
				$this->addColumnInput ("symbol3", self::coColW2);
				$this->addColumnInput ("bankAccount", self::coColW5);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("person", self::coColW6);
				$this->addColumnInput ("text", self::coColW6);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ('priceItem', self::coColW3);
				$this->addColumnInput ('dateDue', self::coColW3);
				$this->addColumnInput ('operation', self::coColW3|DataModel::coSaveOnChange);
			$this->closeRow ();
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'dateDue': return 'Datum';
			case	'priceItem': return 'Částka';
    }
    return parent::columnLabel ($colDef, $options);
  }
}
