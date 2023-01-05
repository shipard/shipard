<?php

namespace e10\persons;

use Google\Service\Vision\Word;
use \Shipard\Table\DbTable, \Shipard\Form\TableForm, \Shipard\Utils\Str;
use \Shipard\Utils\World;

/**
 * class TablePersonsContacts
 */
class TablePersonsContacts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.personsContacts', 'e10_persons_personsContacts', 'Adresy Osob');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec($recData);

		if (!isset($recData['adrCountry']) || $recData['adrCountry'] == 0)
		{
			$thc = $this->app()->cfgItem ('options.core.ownerDomicile', 'cz');
			$recData['adrCountry'] = World::countryNdx($this->app(), $thc);
		}
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$recData = $this->loadItem($pk);
		if (!$recData)
			return '';

		$refTitle = [];
		if ($recData['adrStreet'] !== '')
			$refTitle[] = ['text' => $recData['adrStreet']];
		if ($recData['adrCity'] !== '')
			$refTitle[] = ['text' => $recData['adrCity']];
		if ($recData['adrZipCode'] !== '')
			$refTitle[] = ['text' => $recData['adrZipCode']];

		return $refTitle;
	}
}


/**
 * class FormPersonContact
 */
class FormPersonContact extends TableForm
{
	var $idsOptions = NULL;

	public function renderForm ()
	{
		$this->loadContactIdsOptions();

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Kontakt', 'icon' => 'formContacts'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->openRow();
						$this->addColumnInput ('flagAddress', self::coRightCheckbox);
						if ($this->recData['flagAddress'])
						{
							$this->addColumnInput ('flagMainAddress', self::coRightCheckbox);
							$this->addColumnInput ('flagPostAddress', self::coRightCheckbox);
							$this->addColumnInput ('flagOffice', self::coRightCheckbox);
						}
					$this->closeRow();
					$needSep = 0;
					if ($this->recData['flagAddress'])
					{
						$this->addColumnInput ('adrSpecification');
						$this->addColumnInput ('adrStreet');
						$this->addColumnInput ('adrCity');
						$this->addColumnInput ('adrZipCode');
						$this->addColumnInput ('adrCountry');

						if ($this->idsOptions && isset($this->idsOptions['id1']))
						{
							$this->addSeparator(self::coH4);
							$this->addColumnInput ('id1');
							$needSep = 1;
						}
					}

					$this->addSeparator(self::coH4);
					$this->addColumnInput ('flagContact', self::coRightCheckbox);
					if ($this->recData['flagContact'])
					{
						$this->addColumnInput ('contactName');
						$this->addColumnInput ('contactRole');
						$this->addColumnInput ('contactEmail');
						$this->addColumnInput ('contactPhone');
					}
				$this->closeTab ();
				$this->openTab ();
				$this->closeTab ();
				$this->closeTabs ();
		$this->closeForm ();
	}

	public function loadContactIdsOptions()
	{
		if (!($this->recData['flagOffice'] ?? 0))
			return;

		$cid = World::countryId($this->app(), $this->recData['adrCountry']);
		if ($cid === '')
			return;

		$idsOptions = $this->app()->cfgItem('e10.persons.contactsIds.'.$cid, NULL);
		if (!$idsOptions)
			return;

		$personTypeRec = $this->app()->db()->query('SELECT [company] FROM [e10_persons_persons] WHERE [ndx] = %i', $this->recData['person'])->fetch();
		if (!$personTypeRec)
			return;

		if ($personTypeRec['company'] && isset($idsOptions['company']))
		{
			$this->idsOptions = $idsOptions['company'];
			return;
		}
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'id1': if ($this->idsOptions && isset($this->idsOptions['id1'])) return $this->idsOptions['id1']['label']; break;
    }

		return parent::columnLabel ($colDef, $options);
  }
}
