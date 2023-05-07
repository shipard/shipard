<?php
namespace e10doc\stockIn\libs;

use \Shipard\Application\DataModel;


/**
 * class FormStockInRow
 */
class FormStockInRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$testDocRowPriceSource = intval($this->app()->cfgItem('options.experimental.testDocRowPriceSource', 0));

		$this->openForm (self::ltGrid);
			$this->addColumnInput ('itemType', self::coHidden);
			$this->addColumnInput ('itemBalance', self::coHidden);
			$this->addColumnInput ('itemIsSet', self::coHidden);

			$this->openRow();
				$this->addColumnInput ("operation", self::coColW3);
				$this->addColumnInput ("item", self::coColW9);
			$this->closeRow();

			if ($testDocRowPriceSource)
			{
				$this->openRow();

				$this->addColumnInput ("quantity", self::coColW2);
				$this->addColumnInput ("unit", self::coColW1);
				if ($this->recData['priceSource'] == 1)
				{
					$this->addColumnInput ("priceItem", self::coColW2|self::coDisabled);
					$this->addColumnInput ("priceAll", self::coColW3|DataModel::coSaveOnChange);
				}
				else
				{
					$this->addColumnInput ("priceItem", self::coColW3);
					$this->addColumnInput ("priceAll", self::coColW2|self::coDisabled);
				}
				if ($ownerRecData && $ownerRecData ['taxPayer'])
					$this->addColumnInput ("taxCode", self::coColW2|DataModel::coSaveOnChange);
				$this->addColumnInput ("priceSource", self::coColW2|DataModel::coSaveOnChange);

				$this->closeRow();
			}
			else
			{
				$this->openRow();
				$this->addColumnInput ("quantity", self::coColW3);
				$this->addColumnInput ("unit", self::coColW3);
				$this->addColumnInput ("priceItem", self::coColW3);

				if ($ownerRecData && $ownerRecData ['taxPayer'] && $ownerRecData ['taxCalc'])
					$this->addColumnInput ("taxCode", self::coColW3);
				$this->closeRow();
			}

		$this->closeForm ();
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case'unit': return '';
    }
    return parent::columnLabel ($colDef, $options);
  }
}
