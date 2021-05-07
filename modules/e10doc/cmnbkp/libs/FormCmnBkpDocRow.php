<?php

namespace e10doc\cmnbkp\libs;
use \Shipard\Application\DataModel;
use \Shipard\Utils\Utils;


class FormCmnBkpDocRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

		// -- property
		if ($ownerRecData['activity'] === 'prpOther')
		{
			$this->renderForm_Property(TRUE);
			return;
		}

		switch ($this->recData ['operation'])
		{
			case 1090070:
			case 1090071:
			case 1090072:
			case 1090073:
						$this->renderForm_Property(); return;
		}

		switch ($ownerRecData['activity'])
		{
			//case 'balExchRateDiff': $this->renderForm_balExchRateDiff (); break;
			default: $this->renderForm_Default (); break;
		}
	}

	public function renderForm_Default ()
	{
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);
		$currencyMode = Utils::param($operation, 'currencyMode', '');

		$this->openForm (self::ltGrid);
			$this->openRow ();
				$this->addColumnInput ("operation", self::coColW3|DataModel::coSaveOnChange);

				if (isset ($operation['forceAccount']))
					$this->addColumnInput ("debsAccountId", self::coColW3);
				else
					$this->addColumnInput ("item", self::coColW9);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("debit", self::coColW2);
				$this->addColumnInput ("credit", self::coColW2);

				$this->addColumnInput ("symbol1", self::coColW2);
				$this->addColumnInput ("symbol2", self::coColW2);

				if ($currencyMode !== '')
				{
					$this->addColumnInput ("currency", self::coColW1);
					$this->addColumnInput ("dateDue", self::coColW3);
				}
				else
					$this->addColumnInput ("dateDue", self::coColW4);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("person", self::coColW6);
				$this->addColumnInput ("text", self::coColW6);
			$this->closeRow ();

			$this->openRow ();
				if (isset ($this->recData ['itemBalance']) && $this->recData ['itemBalance'])
				{
					$this->addColumnInput ('bankAccount', self::coColW6);
					$this->addColumnInput ('symbol3', self::coColW3);
				}

				//if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
				//	$this->addColumnInput ("centre", self::coColW3);
			$this->closeRow ();

			/*
			if ($this->recData ['operation'] == 1099998 &&
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
			*/
			if ($this->recData ['operation'] == 1099998 &&
				($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0) ||
					$this->table->app()->cfgItem ('options.core.useProjects', 0) ||
					$this->table->app()->cfgItem ('options.core.useCentres', 0) ||
					$this->table->app()->cfgItem ('options.property.usePropertyExpenses', 0))
			)
			{
				$this->openRow();
					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ("centre", self::coColW2);

					if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
						$this->addColumnInput ('workOrder', self::coColW5);

					if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
						$this->addColumnInput ('project', self::coColW5);

					if ($this->table->app()->cfgItem ('options.property.usePropertyExpenses', 0))
						$this->addColumnInput ('property', self::coColW5);
				$this->closeRow();
			}

		$this->closeForm ();
	}

	public function renderForm_Property ($accMode = FALSE)
	{
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);

		$this->openForm(self::ltGrid);
		if ($accMode)
		{
			$this->openRow ();
				$this->addColumnInput ('operation', self::coColW3|DataModel::coSaveOnChange);
				if (isset ($operation['forceAccount']))
				{
					$this->addColumnInput('debsAccountId', self::coColW2);
				}
				else
					$this->addColumnInput ('item', self::coColW9);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ('debit', self::coColW2);
				$this->addColumnInput ('credit', self::coColW2);
				$this->addColumnInput('property', self::coColW8);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ('text', self::coColW12);
			$this->closeRow ();
		}
		else
		{
			$this->addColumnInput('operation', self::coHidden);
			$this->openRow();
				$this->addColumnInput('property', self::coColW9);
				$this->addColumnInput('priceItem', self::coColW3);
			$this->closeRow();
		}
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'debit': return 'Má dáti';
      case	'credit': return 'Dal';
	    case	'priceItem': return 'Částka';
    }
    return parent::columnLabel ($colDef, $options);
  }
}

