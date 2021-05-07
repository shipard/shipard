<?php

namespace e10pro\helpdesk;

use e10\Utility;


/**
 * Class HelpDeskInfoEngine
 * @package e10pro\helpdesk
 */
class HelpDeskInfoEngine extends Utility
{
	var $requestError = TRUE;
	var $requestData = NULL;
	var $response;
	var $data;


	var $projectNdx = 0;
	var $usersNdxs = [];

	function checkProject ()
	{
		$existedProject = $this->db()->query ('SELECT * FROM [e10pro_wkf_projects] WHERE [id] = %s', $this->requestData['dsid'])->fetch();
		if ($existedProject && isset($existedProject['ndx']))
		{
			$this->projectNdx = $existedProject['ndx'];

			if ($this->requestData['dsName'] !== $existedProject['fullName'])
			{
				$this->db()->query ('UPDATE [e10pro_wkf_projects] SET fullName = %s', $this->requestData['dsName'], ' WHERE ndx = %i', $this->projectNdx);
			}

			return;
		}

		$tableProjects = $this->app->table ('e10pro.wkf.projects');
		$newProject = [
			'id' => $this->requestData['dsid'], 'fullName' => $this->requestData['dsName'],
			'mainGroup' => 1, 'author' => $this->app->userNdx(),
			'docState' => 4000, 'docStateMain' => 2
		];

		$projectNdx = $tableProjects->dbInsertRec($newProject);
		$tableProjects->docsLog ($projectNdx);

		// -- set default admins group
		$newLink = [
			'linkId' => 'e10pro-wkf-projects-admins',
			'srcTableId' => 'e10pro.wkf.projects', 'srcRecId' => $projectNdx,
			'dstTableId' => 'e10.persons.groups', 'dstRecId' => 6];
		$this->app->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);

		$this->projectNdx = $projectNdx;
	}

	function checkUsers ()
	{
		foreach ($this->requestData['users'] as $userInfo)
		{
			$userNdx = $this->checkOneUser($userInfo);
			if (!$userNdx)
			{
				continue;
			}
			$this->checkUserGroups($userNdx, $userInfo);

			$this->data['users'][] = $userInfo['loginHash'];
			$this->usersNdxs[] = $userNdx;
		}
	}

	function checkOneUser ($userInfo)
	{
		$existedUser = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE [loginHash] = %s', $userInfo['loginHash'])->fetch();
		if ($existedUser && isset ($existedUser['ndx']))
		{
			return $existedUser['ndx'];
		}

		$tablePersons = $this->app->table ('e10.persons.persons');
		$newPerson = [
			'login' => $userInfo['login'], 'loginHash' => $userInfo['loginHash'],
			'company' => 0, 'complicatedName' => $userInfo['complicatedName'],
			'beforeName' => $userInfo['beforeName'],
			'firstName' => $userInfo['firstName'],
			'middleName' => $userInfo['middleName'],
			'lastName' => $userInfo['lastName'],
			'afterName' => $userInfo['afterName'],
			'roles' => 'user',

			'personType' => 1, 'accountState' => 1, 'docState' => 4000, 'docStateMain' => 2,
		];
		$personNdx = $tablePersons->dbInsertRec($newPerson);
		$this->app()->db()->query ('UPDATE [e10_persons_persons] SET [id] = %s WHERE [ndx] = %i', strval($personNdx), $personNdx);

		$tablePersons->docsLog ($personNdx);

		return $personNdx;
	}

	function checkUserGroups ($userNdx, $userInfo)
	{
		// users: #4, forum: #8
		$wantedGroups = [4];
		if ($userInfo['pwUser'] > 1)
			$wantedGroups[] = 8;

		foreach ($wantedGroups as $groupNdx)
		{
			$existedGroup = $this->db()->query ('SELECT * FROM [e10_persons_personsgroups] WHERE [person] = %i', $userNdx,
				' AND [group] = %i', $groupNdx)->fetch();
			if (!$existedGroup)
			{
				$newItem = ['person' => $userNdx, 'group' => $groupNdx];
				$this->db()->query ('INSERT INTO [e10_persons_personsgroups]', $newItem);
			}
		}
	}

	function checkProjectPersons ()
	{
		$existedUsers = [];

		$q = [
			'SELECT * FROM [e10_base_doclinks] WHERE ',
			'srcTableId = %s', 'e10pro.wkf.projects', ' AND [dstTableId] = %s', 'e10.persons.persons',
			' AND [linkId] = %s', 'e10pro-wkf-projects-members', ' AND [srcRecId] = %i', $this->projectNdx
		];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$existedUserNdx = $r['dstRecId'];
			$existedUsers[$existedUserNdx] = $r['ndx'];
		}

		// -- add new users
		foreach ($this->usersNdxs as $userNdx)
		{
			if (isset($existedUsers[$userNdx]))
				continue;

			$newLink = [
				'linkId' => 'e10pro-wkf-projects-members',
				'srcTableId' => 'e10pro.wkf.projects', 'srcRecId' => $this->projectNdx,
				'dstTableId' => 'e10.persons.persons', 'dstRecId' => $userNdx];
			$this->app->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
		}

		// -- delete missing users
		foreach ($existedUsers as $userNdx => $docLinkNdx)
		{
			if (in_array($userNdx, $this->usersNdxs))
				continue;
			$this->app->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [ndx] = %i', $docLinkNdx);
		}
	}

	public function init()
	{
		$this->response = new \E10\Response ($this->app);
		$this->response->add ('objectType', 'call');
		$this->data = ['status' => 'error'];

		$requestDataStr = $this->app->postData ();
		$requestData = json_decode($requestDataStr, TRUE);
		if (!$requestData)
		{
			return;
		}
		$this->requestData = $requestData;
	}

	function doIt ()
	{
		$this->data['status'] = 'success';
		$this->data['users'] = [];

		$this->checkProject();
		$this->data['projectNdx'] = $this->projectNdx;
		$this->checkUsers();
		$this->checkProjectPersons();
	}

	public function run ()
	{
		$this->init();

		if ($this->requestData)
			$this->doIt();

		$this->response->add ('data', $this->data);
	}
}

/**
 * @param $app
 * @return \E10\Response
 */
function getHelpDeskInfo ($app)
{
	$e = new HelpDeskInfoEngine($app);
	$e->run();
	return $e->response;
}

