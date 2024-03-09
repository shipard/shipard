<?php

namespace e10doc\invoicesOut\libs;



class FormInvoiceOutRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$this->initForm();

		if ($this->testNewDocRowsEdit)
			$this->renderFormForm();
		else
			$this->renderFormGrid();
	}

	public function renderFormGrid ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);
		$this->openForm (self::ltGrid);
			$this->addColumnInput ('itemType', self::coHidden);
			$this->addColumnInput ('itemBalance', self::coHidden);
			$this->addColumnInput ('itemIsSet', self::coHidden);
			$this->addColumnInput ('invPriceAcc', self::coHidden);

			$this->openRow ();
				$this->addColumnInput ('operation', self::coColW3);
				if (isset ($operation['paymentSymbols']) || (isset ($this->recData ['itemBalance']) && $this->recData ['itemBalance']))
				{
					if (isset ($this->recData ['itemBalance']) && $this->recData ['itemBalance'])
					{
						$this->addColumnInput ('item', self::coColW6|self::coHeader);
						$this->addColumnInput ('symbol1', self::coColW2);
						$this->addColumnInput ('symbol2', self::coColW1);
					}
					else
					{
						$this->addColumnInput ('symbol1', self::coColW2);
						$this->addColumnInput ('symbol2', self::coColW1);
						$this->addColumnInput ('item', self::coColW6|self::coHeader);
					}
				}
				else
				{
					if ($this->recData ['operation'] == 1090060)
					{
						$this->addColumnInput('property', self::coColW9 | self::coHeader);
					}
					else
					{
						$this->addColumnInput('item', self::coColW9 | self::coHeader);
					}
				}
			$this->closeRow ();

			$this->openRow ();
				if ($this->table->app()->cfgItem ('options.e10doc-sale.usrText1UseInvno', 0))
				{
					$this->addColumnInput ('text', self::coColW9);
					$this->addColumnInput("usrText1", self::coColW3);
				}
				else
					$this->addColumnInput ('text', self::coColW12);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ('quantity', self::coColW3);
				$this->addColumnInput ('unit', self::coColW2);
				$this->addColumnInput ('priceItem', self::coColW3);
				if ($ownerRecData && $ownerRecData ['taxPayer'])
					$this->addColumnInput ('taxCode', self::coColW4);
			$this->closeRow ();

			$this->openRow ();
				if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
					$this->addColumnInput ("centre", self::coColW2);

				if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
					$this->addColumnInput ('workOrder', self::coColW5);

				if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
					$this->addColumnInput ('project', self::coColW5);
			$this->closeRow ();

			if ($ownerRecData['taxPercentDateType'] == 3)
			{
				$this->openRow ();
					$this->addColumnInput ('dateVATRate', self::coColW12);
				$this->closeRow ();
			}
		$this->closeForm ();
	}

	public function renderFormForm()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_PARENT_FORM);

		$ownerRecData = $this->option ('ownerRecData');
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);
		$this->openForm ();

		$this->addColumnInput ('operation');
		if (isset ($operation['paymentSymbols']) || (isset ($this->recData ['itemBalance']) && $this->recData ['itemBalance']))
		{
			if (isset ($this->recData ['itemBalance']) && $this->recData ['itemBalance'])
			{
				$this->addColumnInput ('item');
				$this->addColumnInput ('symbol1');
				$this->addColumnInput ('symbol2');
			}
			else
			{
				$this->addColumnInput ('symbol1');
				$this->addColumnInput ('symbol2');
				$this->addColumnInput ('item');
			}
		}
		else
		{
			if ($this->recData ['operation'] == 1090060)
			{
				$this->addColumnInput('property');
			}
			else
			{
				$this->addColumnInput('item');
			}
		}

		$this->addColumnInput ('text');

		$this->openRow();
			$this->addColumnInput ('quantity');
			$this->addColumnInput ('unit');
		$this->closeRow();

		$this->addColumnInput ('priceItem');
		if ($ownerRecData && $ownerRecData ['taxPayer'])
			$this->addColumnInput ('taxCode');

		if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
			$this->addColumnInput ('centre');

		if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
			$this->addColumnInput ('workOrder');

		if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
			$this->addColumnInput ('project');

		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
	{
		switch ($colDef ['sql'])
		{
			case	'usrText1': return $this->app()->cfgItem('options.e10doc-sale.usrText1Label', 'Už. txt 1');

		}
		return parent::columnLabel ($colDef, $options);
	}
}
