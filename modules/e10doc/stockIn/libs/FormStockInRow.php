<?php

namespace e10doc\stockIn\libs;


class FormStockInRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

		$this->openForm (self::ltGrid);
			$this->addColumnInput ('itemType', self::coHidden);
			$this->addColumnInput ('itemBalance', self::coHidden);
			$this->addColumnInput ('itemIsSet', self::coHidden);

			$this->openRow();
				$this->addColumnInput ("operation", self::coColW3);
				$this->addColumnInput ("item", self::coColW9);
			$this->closeRow();

      $this->openRow();
				$this->addColumnInput ("quantity", self::coColW3);
				$this->addColumnInput ("unit", self::coColW3);
				$this->addColumnInput ("priceItem", self::coColW3);

				if ($ownerRecData && $ownerRecData ['taxPayer'] && $ownerRecData ['taxCalc'])
					$this->addColumnInput ("taxCode", self::coColW3);
			$this->closeRow();

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
