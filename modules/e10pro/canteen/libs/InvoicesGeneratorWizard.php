<?php

namespace e10pro\canteen\libs;


/**
 * class InvoicesGeneratorWizard
 */
class InvoicesGeneratorWizard extends \lib\docs\DocumentActionWizard
{
	protected function init ()
	{
		$this->actionClass = 'e10pro.canteen.libs.InvoicesGeneratorAction';
		parent::init();
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'docType/invoicesOut';
    $hdr ['info'][] = ['class' => 'title', 'value' => 'Vystavit faktury za stravné'];

			$hdr ['info'][] = ['class' => 'info', 'value' => [
					[
						'text' => 'Vyberte rok a měsíc, za který chcete faktury vygenerovat',
						'icon' => 'system/iconCalendar',
						'class' => 'block'
					],
					[
						'text' => 'Generování faktur se spustí na pozadí po tisku tlačítka Pokračovat a bude trvat několik minut...',
						'class' => ''
					]
				],
			];
		return $hdr;
	}
}

