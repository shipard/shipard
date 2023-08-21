<?php

namespace e10\users;

use \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \Shipard\Utils\Utils;
use \Shipard\Utils\Str;


/**
 * Class TableUsers
 */
class TableUsers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.users.users', 'e10_users_users', 'Uživatelé');
	}

  public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['login']];

		return $hdr;
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		//$recData['created'] = new \DateTime ();
	}

	public function createUser(array $userInfo)
	{
		$newUser = [
			'fullName' => trim($userInfo['fullName']),
			'login' => Str::tolower(trim($userInfo['email'])),
			'email' => Str::tolower(trim($userInfo['email'])),
			'person' => 0,
			'docState' => 4000, 'docStateMain' => 2,
		];

		$exist = $this->db()->query('SELECT * FROM e10_users_users WHERE [login] = %s', $newUser['login'])->fetch();
		if ($exist)
			return 0;

		$newUserNdx = $this->dbInsertRec($newUser);
		$this->docsLog ($newUserNdx);

		$tableRequests = new \e10\users\TableRequests($this->app());
		$newRequest = ['user' => $newUserNdx, 'ui' => $userInfo['ui'] ?? 1];
		$newRequestNdx = $tableRequests->dbInsertRec($newRequest);

		if ($newRequestNdx)
		{
			$sendRequestEngine = new \e10\users\libs\SendRequestEngine($this->app());
			$sendRequestEngine->setRequestNdx($newRequestNdx);
			$sendRequestEngine->sendRequest();
		}

		return $newUserNdx;
	}
}


/**
 * Class ViewUsers
 */
class ViewUsers extends TableView
{
	var $accountStates;

	public function init ()
	{
		$this->accountStates = $this->app()->cfgItem('e10.users.accountStates');

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries();

		parent::init();

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = [
			['text' => $item['login'], 'class' => 'label label-default', 'icon' => 'user/signIn']
		];
		if ($item['login'] !== $item['email'])
			$listItem ['t2'][] = ['text' => $item['email'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];

		$listItem ['i2'] = [
			['text' => $this->accountStates[$item['accState']]['fn'], 'class' => 'label label-default'],
		];


		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [users].*');
		array_push ($q, ' FROM [e10_users_users] AS [users]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [users].[fullName] LIKE %s', '%' . $fts . '%');
      array_push($q, ' OR [users].[login] LIKE %s', '%' . $fts . '%');
			array_push($q, ')');
		}

		$qv = $this->queryValues ();
		if (isset ($qv['accStates']))
			array_push ($q, ' AND [users].[accState] IN %in', array_keys($qv['accStates']));


		$this->queryMain ($q, '[users].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];


		$enum = [];
		foreach ($this->app()->cfgItem('e10.users.accountStates') as $ndx => $k)
			$enum[$ndx] = $k['fn'];
		$this->qryPanelAddCheckBoxes($panel, $qry, $enum, 'accStates', 'Stav účtu');


		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createToolbar ()
	{
		$t = parent::createToolbar();
		unset ($t[0]);
		return $t;
	}
}


/**
 * Class FormUser
 */
class FormUser extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput('fullName');
      $this->addColumnInput('login');
			$this->addColumnInput('email');
      $this->addColumnInput('person');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailUser
 */
class ViewDetailUser extends TableViewDetail
{
	public function createDetailContent ()
	{
    $this->addDocumentCard('e10.users.libs.dc.DCUser');
	}
}

