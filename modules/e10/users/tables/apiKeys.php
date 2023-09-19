<?php

namespace e10\users;

use \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \Shipard\Utils\Utils;


/**
 * Class TableApiKeys
 */
class TableApiKeys extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.users.apiKeys', 'e10_users_apiKeys', 'Klíče');
	}

	public function checkBeforeSave(&$recData, $ownerData = NULL)
  {
		if (!isset($recData['key']) || $recData['key'] === '')
			$recData['key'] = Utils::createToken(40);
    if (!isset($recData['tsCreated']) || Utils::dateIsBlank($recData['tsCreated']))
			$recData['tsCreated'] = new \DateTime();
	}

  public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

    if (!isset($recData['key']) || $recData['key'] === '')
			$recData['key'] = Utils::createToken(40);
  }
}

/**
 * Class FormApiKey
 */
class FormApiKey extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput('key');
      $this->addColumnInput('user');
		$this->closeForm ();
	}
}
