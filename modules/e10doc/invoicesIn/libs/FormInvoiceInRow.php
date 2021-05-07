<?php

namespace e10doc\invoicesIn\libs;
use \Shipard\Application\DataModel;


class FormInvoiceInRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);
		$taxCode = $this->table->app()->cfgItem ('e10.base.taxCodes.' . $this->recData ['taxCode'], FALSE);
		$usePropertyExpenses = $this->table->app()->cfgItem ('options.property.usePropertyExpenses', 0);

		$this->openForm (self::ltGrid);
			$this->addColumnInput ('itemType', self::coHidden);
			$this->addColumnInput ('itemBalance', self::coHidden);
			$this->addColumnInput ('itemIsSet', self::coHidden);

			$this->openRow ();
				$this->addColumnInput ("operation", self::coColW3|DataModel::coSaveOnChange);
				if (isset ($operation['paymentSymbols']) || (isset ($this->recData ['itemBalance']) && $this->recData ['itemBalance']))
				{
					if (isset ($operation['paymentSymbols']))
					{
						$this->addColumnInput ("symbol1", self::coColW2);
						$this->addColumnInput ('symbol2', self::coColW1);
						$this->addColumnInput ("item", self::coColW6|self::coHeader);
					}
					else
					if (isset ($this->recData ['itemBalance']) && $this->recData ['itemBalance'])
					{
						$this->addColumnInput ("item", self::coColW6|self::coHeader);
						$this->addColumnInput ("symbol1", self::coColW2);
						$this->addColumnInput ('symbol2', self::coColW1);
					}
					else
					{
						$this->addColumnInput ("item", self::coColW9|self::coHeader);
					}
				}
				else
				{
					$this->addColumnInput ("item", self::coColW9|self::coHeader);
				}
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("text", self::coColW12);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("quantity", self::coColW3);
				$this->addColumnInput ("unit", self::coColW2);
				$this->addColumnInput ("priceItem", self::coColW3);
				if ($ownerRecData && $ownerRecData ['taxPayer'])
					$this->addColumnInput ("taxCode", self::coColW4|DataModel::coSaveOnChange);
			$this->closeRow ();

			$this->openRow ();
				if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
					$this->addColumnInput ("centre", self::coColW2);

				if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
					$this->addColumnInput ('workOrder', self::coColW5);

				if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
					$this->addColumnInput ('project', self::coColW5);

				if ($usePropertyExpenses)
					$this->addColumnInput ('property', self::coColW5);

				if (isset ($taxCode['reverseChargeAmount']) && $taxCode['reverseChargeAmount'] === 'w')
					$this->addColumnInput ("weightNet", self::coColW3);
			$this->closeRow ();

		$this->closeForm ();
	}
}
