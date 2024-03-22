<?php

namespace e10\users;

use \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \Shipard\Utils\Utils;


/**
 * Class TableUsers
 */
class TableRequests extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.users.requests', 'e10_users_requests', 'Požadavky na správu uživatelů');
	}

  public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

    $sendRequestEngine = new \e10\users\libs\SendRequestEngine($this->app());
    $sendRequestEngine->setRequestNdx($recData['ndx']);

		$hdr ['info'][] = [
			'class' => 'title', 'value' => [
				['text' => $sendRequestEngine->userRecData['fullName'], 'icon' => 'system/iconUser', 'class' => ''],
				['text' => $sendRequestEngine->userRecData['login'], 'icon' => 'user/signIn', 'class' => 'e10-small'],
			]
		];

		$hdr ['info'][] = ['class' => 'info', 'value' => $sendRequestEngine->requestUrl()];
		return $hdr;
	}

	public function checkBeforeSave(&$recData, $ownerData = NULL)
  {
		if (!isset($recData['requestId']) || $recData['requestId'] === '')
			$recData['requestId'] = Utils::createToken(60);
    if (!isset($recData['tsCreated']) || Utils::dateIsBlank($recData['tsCreated']))
			$recData['tsCreated'] = new \DateTime();
	}

	public function requestInfo($requestId, $requestUrlPart)
	{
		$info = [
			'errorInvalidRequest' => 1,
		];

		if (strlen($requestId) === 6)
			$requestRecData = $this->db()->query('SELECT * FROM e10_users_requests WHERE shortId = %s', $requestId)->fetch();
		else
			$requestRecData = $this->db()->query('SELECT * FROM e10_users_requests WHERE requestId = %s', $requestId)->fetch();
		if (!$requestRecData)
		{
			$info['errorMsg'] = 'Neplatná žádost';
			$info['errorRequestNotExist'] = 1;
			return $info;
		}

		$info['requestNdx'] = $requestRecData['ndx'];

		if ($requestRecData['requestState'] >= 3)
		{
			$info['errorMsg'] = 'Žádost již byla vyřešena';
			$info['errorRequestIsDone'] = 1;
			return $info;
		}

		$userRecData = $this->db()->query('SELECT * FROM e10_users_users WHERE ndx = %i', $requestRecData['user'])->fetch();
		if (!$userRecData)
		{
			$info['errorMsg'] = 'Neplatný uživatel';
			$info['errorInvalidUser'] = 1;
			return $info;
		}

		$info['requestId'] = $requestRecData['requestId'];
		$info['userNdx'] = $userRecData['ndx'];
		$info['userLogin'] = $userRecData['login'];
		$info['userEmail'] = $userRecData['email'];
		$info['userFullName'] = $userRecData['fullName'];

		unset($info['errorInvalidRequest']);
		$info['requestIsOk'] = 1;
		return $info;
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$info = parent::getRecordInfo($recData, $options);

		$requestType = $this->app()->cfgItem('e10.users.requestTypes.'.$recData['requestType']);
		$info['title'] = $requestType['fn'];
		$userRecData = $this->app()->loadItem($recData['user'], 'e10.users.users');
		if ($userRecData)
		{
			$info['title'] .= ': '.$userRecData['fullName'];
			$info ['emails']['to'] = $userRecData['email'];
		}

		$uiRecData = $this->app()->loadItem($recData['ui'], 'e10.ui.uis');
		if ($uiRecData && $uiRecData['sendRequestsFromEmail'] !== '')
			$info['emailFromAddress'] = $uiRecData['sendRequestsFromEmail'];

		return $info;
	}
}


/**
 * Class ViewRequests
 */
class ViewRequests extends TableView
{
	var $accountStates;
  var $requestStates;
	var $requestTypes;

	public function init ()
	{
    $this->requestStates = $this->app()->cfgItem('e10.users.requestStates');
		$this->requestTypes = $this->app()->cfgItem('e10.users.requestTypes');

		$this->enableDetailSearch = TRUE;

		parent::init();

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['userFullName'];

		$rt = $this->requestTypes[$item['requestType']];
		$listItem ['t2'] = [
			['text' => $item['uiFullName'], 'class' => 'label label-default', 'icon' => 'tables/e10.ui.uis'],
			['text' => $rt['fn'], 'class' => 'label label-info'],
			['text' => $item['userLogin'], 'class' => 'label label-default', 'icon' => 'user/signIn']
		];
		if ($item['userLogin'] !== $item['userEmail'] && $item['userEmail'] !== '')
			$listItem ['t2'][] = ['text' => $item['userEmail'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];

    $flags = [];
    $flags[] = ['text' => Utils::datef($item['tsCreated'], '%d %t'), 'class' => 'label label-default'];

		$labelState = ['text' => $this->requestStates[$item['requestState']]['fn'], 'class' => 'label label-default'];
		if ($item['requestState'] === 2)
			$labelState['suffix'] = Utils::datef($item['tsSent'], '%D, %T');
		elseif ($item['requestState'] === 3)
			$labelState['suffix'] = Utils::datef($item['tsFinished'], '%D, %T');
    $flags[] = $labelState;

		if ($item['shortId'] !== '')
			$flags[] = ['text' => $item['shortId'], 'class' => 'label label-default'];

    $listItem['t3'] = $flags;

		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [requests].*,');
    array_push ($q, ' users.fullName AS userFullName, users.login AS userLogin, users.email AS userEmail,');
    array_push ($q, ' uis.fullName AS uiFullName');
		array_push ($q, ' FROM [e10_users_requests] AS [requests]');
		array_push ($q, ' LEFT JOIN e10_users_users AS users ON [requests].user = users.ndx');
		array_push ($q, ' LEFT JOIN e10_ui_uis AS uis ON [requests].ui = uis.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [users].[fullName] LIKE %s', '%' . $fts . '%');
      array_push($q, ' OR [users].[login] LIKE %s', '%' . $fts . '%');
      array_push($q, ' OR [users].[email] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR [requests].[requestId] LIKE %s', $fts . '%');
			array_push($q, ')');
		}

		$qv = $this->queryValues ();
		if (isset ($qv['requestTypes']))
			array_push ($q, ' AND [requests].[requestType] IN %in', array_keys($qv['requestTypes']));
		if (isset ($qv['requestStates']))
			array_push ($q, ' AND [requests].[requestState] IN %in', array_keys($qv['requestStates']));

		if (isset ($qv['usersRoles']))
		{
			array_push ($q, ' AND EXISTS (',
			'SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks',
			' WHERE [users].ndx = srcRecId AND srcTableId = %s', 'e10.users.users',
			' AND dstTableId = %s', 'e10.users.roles',
			' AND docLinks.dstRecId IN %in)', array_keys($qv['usersRoles']));
		}


    array_push ($q, ' ORDER BY ndx');
    array_push ($q, $this->sqlLimit ());
		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		$enum = [];
		foreach ($this->app()->cfgItem('e10.users.requestTypes') as $ndx => $k)
			$enum[$ndx] = $k['fn'];
		$this->qryPanelAddCheckBoxes($panel, $qry, $enum, 'requestTypes', 'Druh požadavku');

		$enum = [];
		foreach ($this->app()->cfgItem('e10.users.requestStates') as $ndx => $k)
			$enum[$ndx] = $k['fn'];
		$this->qryPanelAddCheckBoxes($panel, $qry, $enum, 'requestStates', 'Stav požadavku');

		$enum = [];
		$rolesRows = $this->db()->query('SELECT * FROM [e10_users_roles] WHERE [docState] = %i', 4000);
		foreach ($rolesRows as $role)
		{
			$enum[$role['ndx']] = $role['fullName'];
		}
		$this->qryPanelAddCheckBoxes($panel, $qry, $enum, 'usersRoles', 'Role uživatelů');

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormRequest
 */
class FormRequest extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput('requestId');
      $this->addColumnInput('user');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailRequest
 */
class ViewDetailRequest extends TableViewDetail
{
	public function createDetailContent ()
	{
    //$this->addDocumentCard('');
	}
}

