<?php

namespace e10\users;
use \Shipard\Utils\Str;


/**
 * class ModuleServices
 */
class ModuleServices extends \e10\cli\ModuleServices
{
	protected function addUsersFromContacts()
	{
		$tableUsers = new \e10\users\TableUsers($this->app());
		//$this->db()->query('DELETE FROM e10_users_users');

		$tableRequests = new \e10\users\TableRequests($this->app());
		//$this->db()->query('DELETE FROM e10_users_requests');

    $q = [];
    array_push($q, 'SELECT contacts.*,');
    array_push($q, ' persons.fullName AS personName');
    array_push($q, ' FROM e10_persons_personsContacts AS [contacts]');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [contacts].person = [persons].ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [persons].[docState] = %i', 4000);
		array_push($q, ' AND [contacts].[flagContact] = %i', 1);
		array_push($q, ' AND [contacts].[contactEmail] != %s', '');
		array_push($q, ' ORDER BY [persons].ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'fullName' => trim($r['contactName']),
        'login' => Str::tolower(trim($r['contactEmail'])),
				'email' => Str::tolower(trim($r['contactEmail'])),
        'person' => 0,
				'docState' => 4000, 'docStateMain' => 2,
      ];

			$exist = $this->db()->query('SELECT * FROM e10_users_users WHERE [login] = %s', $item['login'])->fetch();
			if ($exist)
				continue;

			//echo "* ".json_encode($item)."\n";

			$newUserNdx = $tableUsers->dbInsertRec($item);
			$tableUsers->docsLog ($newUserNdx);

			$newRequest = ['user' => $newUserNdx];
			$newRequestNdx = $tableRequests->dbInsertRec($newRequest);
    }
	}

	protected function sendRequests()
	{
		$maxCount = 50;

		$q = [];
		array_push($q, 'SELECT [requests].* ');
		array_push($q, ' FROM [e10_users_requests] AS [requests]');
		array_push($q, ' LEFT JOIN e10_users_users AS [users] ON [requests].[user] = [users].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [users].[docState] = %i', 4000);
		array_push($q, ' AND [users].[email] != %s', '');
		array_push($q, ' AND requestState <= %i', 1);
		array_push($q, ' ORDER BY [requests].ndx');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$sendRequestEngine = new \e10\users\libs\SendRequestEngine($this->app());
			$sendRequestEngine->setRequestNdx($r['ndx']);

			echo '* '.$sendRequestEngine->userRecData['fullName'].'; '.$sendRequestEngine->userRecData['email']."\n";

			$sendRequestEngine->sendRequest();
			sleep(5);

			$cnt++;
			if ($cnt > $maxCount)
				break;
		}
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'add-from-contacts': return $this->addUsersFromContacts();
			case 'send-requests': return $this->sendRequests();
		}

		parent::onCliAction($actionId);
	}
}
