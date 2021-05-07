<?php

namespace e10doc\cashRegister\libs;


class FormCashRegisterDocRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

		$this->openForm (self::ltGrid);
			$this->addColumnInput ("text", self::coHidden);
			$this->addColumnInput ("taxRate", self::coHidden);
			$this->addColumnInput ("taxCode", self::coHidden);
			$this->addColumnInput ("unit", self::coHidden);
			$this->addColumnInput ("itemType", self::coHidden);
			$this->addColumnInput ("itemBalance", self::coHidden);
			$this->addColumnInput ("itemIsSet", self::coHidden);

			$this->openRow ();
				$this->addColumnInput ("item", self::coInfoText|self::coNoLabel|self::coColW12);
			$this->closeRow ();

			$this->openRow ('right');
				$this->addColumnInput ("quantity", self::coColW4);
				$this->addColumnInput ("priceItem", self::coColW4);
  			//$this->addColumnInput ("taxCode");
			$this->closeRow ();
		$this->closeForm ();
	}
}


