<?php

namespace e10doc\waster\libs;


/**
 * class FormWasteLPDocRow
 */
class FormWasteLPDocRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$this->openForm (self::ltGrid);
			$this->addColumnInput ('itemType', self::coHidden);
			$this->addColumnInput ('itemBalance', self::coHidden);
			$this->addColumnInput ('itemIsSet', self::coHidden);
			$this->addColumnInput ('invPriceAcc', self::coHidden);

			$this->openRow ();
				/*if ($this->table->app()->cfgItem ('options.core.useOperations', 0))
				{
					$this->addColumnInput ("operation", self::coColW3);
					$this->addColumnInput ("item", self::coColW9|self::coHeader);
				}
				else*/
					$this->addColumnInput ('item', self::coColW12|self::coHeader);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ('text', self::coColW12);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ('quantity', self::coColW3);
				$this->addColumnInput ('unit', self::coColW2);
				$this->addColumnInput ('priceItem', self::coColW3);
			$this->closeRow ();

			$this->openRow ();
				if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
					$this->addColumnInput ("centre", self::coColW2);
				if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
					$this->addColumnInput ('workOrder', self::coColW5);
				if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
					$this->addColumnInput ('project', self::coColW5);
			$this->closeRow ();

		$this->closeForm ();
	}
}
