<?php

namespace e10\users\libs\dc;
use \Shipard\Utils\Utils;



/**
 * class DCRobot
 */
class DCRobot extends \Shipard\Base\DocumentCard
{
  var \e10\persons\TablePersons $tablePersons;

	public function createContentBody ()
	{
    $this->apiKeys ();
	}

  public function apiKeys ()
	{
		if (!$this->app()->hasRole('admin'))
			return;

     /** @var \e10\users\TableApiKeys */
    $tableApiKeys = $this->app()->table('e10.users.apiKeys');

    $q = [];
		array_push($q, 'SELECT * FROM e10_users_apiKeys ');
    array_push($q, ' WHERE 1');
		array_push($q, ' AND [user] = %i', $this->recData['ndx']);

		$keys = [];
		$rows = $this->table->db()->query($q);
		foreach ($rows as $r)
		{
      $docStates = $tableApiKeys->documentStates ($r);
			$docStateClass = $tableApiKeys->getDocumentStateInfo ($docStates, $r, 'styleClass');

			$k = [
        'key' => ['text' => $r['key'], 'docAction' => 'edit', 'table' => 'e10.users.apiKeys', 'pk' => $r['ndx']],
        '_options' => ['cellClasses' => ['key' => $docStateClass]],
      ];
			$keys[] = $k;
		}

		$title = [];
		$title[] = ['icon' => 'icon-plug', 'text' => 'Přihlašovací klíče k API'];

		$title[] = [
				'text'=> 'Nový', 'docAction' => 'new', 'table' => 'e10.users.apiKeys', 'type' => 'button',
				'actionClass' => 'btn btn-success btn-xs', 'icon' => 'system/actionAdd', 'class' => 'pull-right',
				'addParams' => "__user={$this->recData['ndx']}"
		];

		$h = ['#' => '#', 'key' => 'Klíč'];
		$this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
												'title' => $title, 'header' => $h, 'table' => $keys]);
	}

	public function createContent ()
	{
    $this->tablePersons = new \e10\persons\TablePersons($this->app());
		$this->createContentBody ();
	}
}

