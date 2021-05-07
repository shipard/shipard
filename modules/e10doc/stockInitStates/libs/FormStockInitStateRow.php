<?php

namespace e10doc\stockInitStates\libs;



class FormStockInitStateRow extends \e10doc\core\libs\FormDocRows
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

		$this->openForm (self::ltVertical);
			$this->layoutOpen (self::ltHorizontal);
				$this->addColumnInput ("quantity");
				$this->addColumnInput ("unit");
				$this->addColumnInput ("priceItem");
			$this->layoutClose ();

      $this->layoutOpen (self::ltHorizontal);
        //$this->addColumnInput ("operation");
				$this->addColumnInput ("item");
				$this->addColumnInput ("taxCode", self::coNoLabel);
			$this->layoutClose ();

		$this->closeForm ();
	}
}
