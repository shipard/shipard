<?php

namespace e10\users;

use \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \Shipard\Utils\Utils;


/**
 * Class TableUsersKeys
 */
class TableUsersKeys extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.users.usersKeys', 'e10_users_usersKeys', 'Klíče uživatelů');
	}

	public function XXX_checkBeforeSave(&$recData, $ownerData = NULL)
  {
		if (!isset($recData['key']) || $recData['key'] === '')
			$recData['key'] = Utils::createToken(40);
    if (!isset($recData['tsCreated']) || Utils::dateIsBlank($recData['tsCreated']))
			$recData['tsCreated'] = new \DateTime();
	}

  public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

  //  if (!isset($recData['key']) || $recData['key'] === '')
	//		$recData['key'] = Utils::createToken(4);
  }
}

/**
 * Class FormUserKey
 */
class FormUserKey extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
      $this->addColumnInput('keyType');
			$this->addColumnInput('key');
      $this->addColumnInput('user');
		$this->closeForm ();
	}
}
