<?php

namespace e10\persons\libs\register;
use \Shipard\Form\Wizard;


/**
 * class AddOfficesWizard
 */
class AddOfficesWizard extends Wizard
{
	var $personId = '';
  var $personNdx = 0;

	function init()
	{
		$this->personId = $this->app()->testGetParam('personId');
    if (!isset($this->recData['personId']))
      $this->recData['personId'] = $this->personId;
    $this->personNdx = intval($this->app()->testGetParam('personNdx'));
    if (!isset($this->recData['personNdx']))
      $this->recData['personNdx'] = $this->personNdx;
    }

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
    $this->init();

    $reg = new \e10\persons\libs\register\PersonRegister($this->app());
		$reg->setPersonNdx($this->personNdx);


		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
      $this->addInput('personNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
      $this->addInput('personId', '', self::INPUT_STYLE_STRING, self::coHidden, 120);

			if ($reg->generalFailure)
			{
				$this->addStatic(['text' => "Selhalo načtení dat z registru - IČ `{$reg->personOid}` neexistuje.", 'class' => 'h2 block ml1 e10-error']);
			}
			else
			{
				if (count($reg->missingOffices))
				{
					foreach ($reg->missingOffices as $mo)
					{
						$addrId = 'AO_'.$mo['natId'];
						$label = [
							['text' => $mo['addressText'], 'class' => ''],
							['text' => 'IČP: '.$mo['natId'], 'class' => 'label label-default'],
						];
						$this->addCheckBox($addrId, $label, '1', self::coRightCheckbox);
					}
				}
				else
				{
					if (count($reg->registerData['address']) < 2)
					{
						$this->addStatic(['text' => 'Firma nemá žádné provozovny...', 'class' => 'padd5']);
					}
					else
					{
						$this->addStatic(['text' => 'Všechny provozovny již máte přidány...', 'class' => 'padd5']);
					}
				}
			}
  		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->init();
    $this->personNdx = $this->recData['personNdx'];

    $addOfficesIds = [];
    foreach ($this->recData as $key => $value)
    {
      if (!str_starts_with($key, 'AO_'))
        continue;
      if ($value != 1)
        continue;

      $addOfficesIds[] = substr($key, 3);
    }

    $reg = new \e10\persons\libs\register\PersonRegister($this->app());
		$reg->setPersonNdx($this->personNdx);
		if (!$reg->generalFailure)
    	$reg->addOfficesByNatIds($addOfficesIds);

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'user/home';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Načíst provozovny '/*.$this->requestRecData['subject']*/];

		/** @var \e10\persons\TablePersons $tablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');
		$personRecData = $tablePersons->loadItem($this->personNdx);

		if ($personRecData)
		{
			$hdr ['info'][] = ['class' => 'info', 'value' => [
				[
					'text' => ($personRecData ['fullName'] !== '') ? $personRecData ['fullName'] : '!!!'.$this->personNdx,
					'icon' => $tablePersons->tableIcon($personRecData),
				],
				['text' => '#'.$personRecData['id'], 'class' => 'pull-right']
			],
			];
		}

		return $hdr;
	}
}
