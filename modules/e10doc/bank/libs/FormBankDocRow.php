<?php

namespace e10doc\bank\libs;
use \Shipard\Application\DataModel;



class FormBankDocRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);

		$this->openForm (self::ltGrid);
			$this->openRow ();
				$this->addColumnInput ("debit", self::coColW3);
				$this->addColumnInput ("credit", self::coColW3);
				$this->addColumnInput ("dateDue", self::coColW3);

				if ($ownerRecData ['currency'] != $ownerRecData ['homeCurrency'])
					$this->addColumnInput ("exchangeRate", self::coColW3);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("bankAccount", self::coColW5);
				$this->addColumnInput ("symbol1", self::coColW3);
				$this->addColumnInput ("symbol2", self::coColW2);
				$this->addColumnInput ("symbol3", self::coColW2);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("person", self::coColW6);
				$this->addColumnInput ("text", self::coColW6);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("operation", self::coColW3|DataModel::coSaveOnChange);
				$needCentre = 0;

				if (isset ($operation['forceAccount']))
				{
					$this->addColumnInput ("debsAccountId", self::coColW3);
					$needCentre = 1;
				}
				elseif (in_array($this->recData ['operation'], [1030001, 1030002, 1020102, 1020103]))
				{
					$this->addColumnInput ('bankRequestCurrency', self::coColW1);
					if ($this->recData['bankRequestCurrency'] !== $ownerRecData['currency'])
						$this->addColumnInput ('bankRequestAmount', self::coColW3);
				}
				else
				if ($this->recData ['operation'] == 1099998)
				{
					$this->addColumnInput ("item", self::coColW6);
					$needCentre = 1;
				}
				if ($needCentre && $this->table->app()->cfgItem ('options.core.useCentres', 0))
					$this->addColumnInput ("centre", self::coColW3);
			$this->closeRow ();

			if ($needCentre &&
				($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0) ||
						$this->table->app()->cfgItem ('options.core.useProjects', 0))
					)
			{
				$this->openRow();
					if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
						$this->addColumnInput ('workOrder', self::coColW6);

					if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
						$this->addColumnInput ('project', self::coColW6);
				$this->closeRow();
			}
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'dateDue': return 'Datum';
    }
    return parent::columnLabel ($colDef, $options);
  }
}

