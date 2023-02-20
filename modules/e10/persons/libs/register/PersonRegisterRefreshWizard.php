<?php

namespace e10\persons\libs\register;
use \Shipard\Form\Wizard;
use \Shipard\Utils\Json;
use \Shipard\Utils\Utils;


/**
 * class PersonRegisterRefreshWizard
 */
class PersonRegisterRefreshWizard extends Wizard
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
    $reg->makeDiff();

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
				if (count($reg->diff['msgs']))
				{
					$this->addStatic(['text' => 'Přehled změn', 'class' => 'h2 block ml1']);
					foreach ($reg->diff['msgs'] as $msg)
					{
						$this->addStatic('  ● '.$msg);
					}
				}

				if (count($reg->missingBA))
				{
					$this->addStatic(['text' => 'Nové bankovní účty', 'class' => 'h2 block ml1']);
					foreach ($reg->missingBA as $mba)
					{
						$baId = 'BA_'.$mba['bankAccount'];
						$label = [
							['text' => $mba['bankAccount'], 'class' => ''],
						];
						$this->addCheckBox($baId, $label, '1', self::coRightCheckbox);
						$this->recData[$baId] = 1;
					}
				}

				if (count($reg->missingOffices))
				{
					$this->addStatic(['text' => 'Nové provozovny', 'class' => 'h2 block ml1']);
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

				/*
				if (count($reg->diff['updates']))
				{
					$this->addStatic([
						'type' => 'line',
						'line' => ['code' => "<pre class='ml1 mt1 mr1 e10-bg-t6 padd5'>".Utils::es(Json::lint($reg->diff['updates'])).'</pre>']
					]);
				}
				*/
			}

  		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->init();
    $this->personNdx = $this->recData['personNdx'];

    $reg = new \e10\persons\libs\register\PersonRegister($this->app());
		$reg->setPersonNdx($this->personNdx);
    $reg->makeDiff();

		if (!$reg->generalFailure)
		{
			$reg->applyDiff();

			// -- new bank accounts
			$addBAIds = [];
			foreach ($this->recData as $key => $value)
			{
				if (!str_starts_with($key, 'BA_'))
					continue;
				if ($value != 1)
					continue;

				$addBAIds[] = substr($key, 3);
			}
			if (count($addBAIds))
				$reg->addBankAccounts($addBAIds);

			// -- new offices
			$addOfficesIds = [];
			foreach ($this->recData as $key => $value)
			{
				if (!str_starts_with($key, 'AO_'))
					continue;
				if ($value != 1)
					continue;

				$addOfficesIds[] = substr($key, 3);
			}
			if (count($addOfficesIds))
				$reg->addOfficesByNatIds($addOfficesIds);
		}

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'docType/bank';
    $hdr ['info'][] = ['class' => 'title', 'value' => 'Načíst změny z registru firem'];

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
