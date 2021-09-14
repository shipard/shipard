<?php

namespace E10Doc\Core;

use \E10\DbTable, \E10\TableForm, \E10\utils, \E10\DataModel;
use \e10doc\core\libs\E10Utils;

/**
 * Daně Dokladu
 *
 */

class TableTaxes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.core.taxes", "e10doc_core_taxes", "Daně dokladů");
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$cfgTaxCode = E10Utils::taxCodeCfg($this->app(), $recData['taxCode']);
		$noPayTax = 0;
		if (isset ($cfgTaxCode ['noPayTax']) && ($cfgTaxCode ['noPayTax'] == 1))
			$noPayTax = 1;

		$fc = ($ownerData['currency'] !== $ownerData['homeCurrency']);
		if (!$fc)
		{
			$recData ['sumBaseHc'] = $recData['sumBase'];
			$recData ['sumTaxHc'] = $recData['sumTax'];
		}

		if ($noPayTax)
		{
			$recData['sumTotal'] = $recData['sumBase'];
			$recData['sumTotalHc'] = $recData['sumBaseHc'];
		}
		else
		{
			$recData['sumTotal'] = $recData['sumBase'] + $recData['sumTax'];
			$recData['sumTotalHc'] = $recData['sumBaseHc'] + $recData['sumTaxHc'];
		}

		if (!isset($recData['taxPeriod']) || !$recData['taxPeriod'])
		{
			$recData['taxPeriod'] = $ownerData['taxPeriod'];
			$recData['dateTax'] = $ownerData['dateTax'];
			$recData['dateTaxDuty'] = $ownerData['dateTaxDuty'];

			$recData['taxRate'] = $cfgTaxCode['rate'];
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}
}

/**
 * Class FormTaxesRow
 * @package E10Doc\Core
 */
class FormTaxesRow extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$fc = ($ownerRecData ['currency'] !== $ownerRecData ['homeCurrency']);

		$this->openForm (TableForm::ltGrid);
			if ($fc)
			{
				$this->openRow();
					$this->addColumnInput ('taxCode', TableForm::coColW3);
					$this->addColumnInput ('taxPercents', TableForm::coColW1);

					$this->addColumnInput ('sumBase', TableForm::coColW3|DataModel::coSaveOnChange);
					$this->addColumnInput ('sumTax', TableForm::coColW3|DataModel::coSaveOnChange);
					$this->addInfoText (utils::nf($this->recData['sumTotal'], 2), TableForm::coColW2);
				$this->closeRow();
				$this->openRow();
					$this->addStatic ('', TableForm::coColW4);
					$this->addColumnInput ('sumBaseHc', TableForm::coColW3|DataModel::coSaveOnChange);
					$this->addColumnInput ('sumTaxHc', TableForm::coColW3|DataModel::coSaveOnChange);
					$this->addInfoText (utils::nf($this->recData['sumTotalHc'], 2), TableForm::coColW2);
				$this->closeRow();
			}
			else
			{
				$this->addColumnInput ('taxCode', TableForm::coColW3);
				$this->addColumnInput ('taxPercents', TableForm::coColW1);
				$this->addColumnInput ('sumBase', TableForm::coColW3|DataModel::coSaveOnChange);
				$this->addColumnInput ('sumTax', TableForm::coColW3|DataModel::coSaveOnChange);
				$this->addInfoText (utils::nf($this->recData['sumTotal'], 2), TableForm::coColW2);
			}
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
	{
		$ownerRecData = $this->option ('ownerRecData');
		$fc = ($ownerRecData ['currency'] !== $ownerRecData ['homeCurrency']);

		if ($fc)
		{
			$fcn = $this->app()->cfgItem ('e10.base.currencies.'.$ownerRecData ['currency'].'.shortcut');
			$hcn = $this->app()->cfgItem ('e10.base.currencies.'.$ownerRecData ['homeCurrency'].'.shortcut');

			switch ($colDef ['sql'])
			{
				case 'sumBase': return 'Základ '.$fcn;
				case 'sumBaseHc': return 'Základ '.$hcn;
				case 'sumTax': return 'Daň '.$fcn;
				case 'sumTaxHc': return 'Daň '.$hcn;
			}
		}
		return parent::columnLabel ($colDef, $options);
	}
}




