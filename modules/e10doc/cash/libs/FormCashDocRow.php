<?php

namespace e10doc\cash\libs;
use \Shipard\Application\DataModel;



class FormCashDocRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);

		$useProperty = 0;
		if ($ownerRecData['cashBoxDir'] == 2)
			$useProperty = $this->table->app()->cfgItem ('options.property.usePropertyExpenses', 0);

		$this->openForm (self::ltGrid);
			$this->addColumnInput ('itemType', self::coHidden);
			$this->addColumnInput ('itemBalance', self::coHidden);
			$this->addColumnInput ('itemIsSet', self::coHidden);
			if (isset ($operation['paymentSymbols']))
			{
				$this->openRow ();
					$this->addColumnInput ("operation", self::coColW3|DataModel::coSaveOnChange);
					$this->addColumnInput ("symbol1", self::coColW5);
					$this->addColumnInput ("symbol2", self::coColW4);
				$this->closeRow ();
				$this->openRow ();
					$this->addColumnInput ("item", self::coColW5|self::coHeader);
					$this->addColumnInput ("text", self::coColW7);
				$this->closeRow ();
			}
			else
			if (isset ($this->recData ['itemBalance']) && $this->recData ['itemBalance'])
			{
				$this->openRow ();
					$this->addColumnInput ("operation", self::coColW3|DataModel::coSaveOnChange);
					$this->addColumnInput ("item", self::coColW9|self::coHeader);
				$this->closeRow ();
				$this->openRow ();
					$this->addColumnInput ("symbol1", self::coColW3);
					$this->addColumnInput ("symbol2", self::coColW2);
					$this->addColumnInput ("text", self::coColW7);
				$this->closeRow ();
			}
			else
			{
				$this->openRow ();
					$this->addColumnInput ("operation", self::coColW3|DataModel::coSaveOnChange);
					if ($this->recData ['operation'] == 1090060)
					{
						$this->addColumnInput('property', self::coColW9 | self::coHeader);
					}
					else
					{
						$this->addColumnInput('item', self::coColW9 | self::coHeader);
					}
				$this->closeRow ();
				$this->openRow ();
					$this->addColumnInput ("text", self::coColW12);
				$this->closeRow ();
			}

			$this->openRow ();
				$this->addColumnInput ("quantity", self::coColW3);
				$this->addColumnInput ("unit", self::coColW1);
				$this->addColumnInput ("priceItem", self::coColW3);
				if ($ownerRecData && $ownerRecData ['taxPayer'] && $ownerRecData ['taxCalc'])
				{
					$this->addColumnInput ("taxCode", self::coColW5);
				}
			$this->closeRow ();

			if ($ownerRecData ['collectingDoc'])
			{
				$this->openRow ();
					$this->addColumnInput ('person', self::coColW12);
				$this->closeRow ();
			}

			$this->openRow ();
				if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
					$this->addColumnInput ("centre", self::coColW2);

				if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
					$this->addColumnInput ('workOrder', self::coColW5);

				if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
					$this->addColumnInput ('project', self::coColW5);

				if ($useProperty)
					$this->addColumnInput ('property', self::coColW5);

			$this->closeRow ();

		$this->closeForm ();
	}
}
