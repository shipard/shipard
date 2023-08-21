<?php

namespace e10\users\libs\dc;
use \Shipard\Utils\Utils;



/**
 * class DCUser
 */
class DCUser extends \Shipard\Base\DocumentCard
{
  var \e10\persons\TablePersons $tablePersons;

	public function createContentBody ()
	{
    $this->createContentBody_Contacts();
    $this->createContentBody_Persons();
    $this->createContentBody_Requests();
	}

	function createContentBody_Contacts()
	{
    $q = [];
    array_push($q, 'SELECT contacts.*,');
    array_push($q, ' persons.fullName AS personName, persons.id AS personId, persons.docState AS personDocState, persons.docStateMain AS personDocStateMain');
    array_push($q, ' FROM e10_persons_personsContacts AS [contacts]');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [contacts].person = [persons].ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [contactEmail] = %s', $this->recData['login']);
    array_push($q, ' ORDER BY persons.docStateMain, contactName');

    $table = [];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $pd = ['docState' => $r['personDocState'], 'docStateMain' => $r['personDocStateMain']];
      $docStates = $this->tablePersons->documentStates ($pd);
			$docStateClass = $this->tablePersons->getDocumentStateInfo ($docStates, $pd, 'styleClass');

      $item = [
        'name' => $r['contactName'],
        'role' => $r['contactRole'],
        'person' => ['text' => $r['personName'], 'suffix' => $r['personId'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk' => $r['person']],
      ];

      if ($docStateClass)
        $item['_options'] = ['cellClasses' => ['person' => 'e10-ds '.$docStateClass]];

      $table[] = $item;
    }

    $h = [
      'name' => 'Jméno',
      'role' => 'Funkce',
      'person' => 'Osoba',
    ];


    $this->addContent('body', [
      'pane' => 'e10-pane e10-pane-table', 'paneTitle' => ['text' => 'Kontakty', 'class' => 'h2'],
      'table' => $table, 'header' => $h,
      ]
    );
	}

	function createContentBody_Requests()
	{
    $requestStates = $this->app()->cfgItem('e10.users.requestStates');
		$requestTypes = $this->app()->cfgItem('e10.users.requestTypes');

    $q = [];
    array_push($q, 'SELECT requests.*, uis.fullName AS uiFullName');
    array_push($q, ' FROM e10_users_requests AS [requests]');
    array_push($q, ' LEFT JOIN e10_ui_uis AS uis ON [requests].ui = uis.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [user] = %i', $this->recData['ndx']);
    array_push($q, ' ORDER BY tsFinished DESC, ndx');

    $table = [];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'type' => [
          ['text' => $requestTypes[$r['requestType']]['fn'], 'class' => ''],
          ['text' => $r['uiFullName'], 'class' => 'label label-default', 'icon' => 'tables/e10.ui.uis'],
        ],
        'tsCreated' => Utils::datef($r['tsCreated'], '%S, %T'),
        'state' => ['text' => $requestStates[$r['requestState']]['fn'], 'class' => ''],
      ];

      if (!Utils::dateIsBlank($r['tsFinished']))
        $item['state']['suffix'] = Utils::datef($r['tsFinished'], '%S, %T');

      //if ($docStateClass)
      //  $item['_options'] = ['cellClasses' => ['person' => 'e10-ds '.$docStateClass]];

      /*
      $btn = [
        'type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => '', 'title' => 'Aktivace účtu',
        'data-report' => 'e10.users.libs.reports.ReportRequestActivate',
        'data-table' => 'e10.users.requests', 'data-pk' => strval($r['ndx']), 'actionClass' => 'btn-xs btn-primary', 'class' => 'pull-right'];
      $btn['subButtons'] = [];
      $btn['subButtons'][] = [
        'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem',
        'btnClass' => 'btn-primary btn-xs',
        'data-table' => 'e10.users.requests', 'data-pk' => strval($r['ndx']), 'data-pk' => strval($r['ndx']),
        'data-class' => 'Shipard.Report.SendFormReportWizard',
        'data-addparams' => 'reportClass=' . 'e10.users.libs.reports.ReportRequestActivate' . '&documentTable=' . 'e10.users.requests' .'&focusedPKPrimary=' . strval($r['ndx'])
      ];
      */

      if ($r['requestType'] === 0 && $r['requestState'] < 3)
      {
        $btn =
        [
          'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem',
          'btnClass' => 'btn-primary btn-xs', 'text' => 'Odeslat',
          'data-table' => 'e10.users.requests', 'data-pk' => strval($r['ndx']), 'data-pk' => strval($r['ndx']),
          'data-class' => 'Shipard.Report.SendFormReportWizard',
          'data-addparams' => 'reportClass=' . 'e10.users.libs.reports.ReportRequestActivate' . '&documentTable=' . 'e10.users.requests' .'&focusedPKPrimary=' . strval($r['ndx'])
        ];

        $item['info'] = $btn;
      }

      $table[] = $item;
    }

    $h = [
      'type' => 'Požadavek',
      'tsCreated' => 'Vytvořeno',
      'state' => 'Stav',
      'info' => 'Pozn.'
    ];


    $this->addContent('body', [
      'pane' => 'e10-pane e10-pane-table', 'paneTitle' => ['text' => 'Požadavky', 'class' => 'h2'],
      'table' => $table, 'header' => $h,
      ]
    );
	}

	function createContentBody_Persons()
	{
    $q = [];
    array_push($q, 'SELECT persons.*');
    array_push($q, ' FROM e10_persons_persons AS [persons]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND EXISTS (SELECT ndx FROM e10_base_properties WHERE persons.ndx = e10_base_properties.recid ',
                   ' AND valueString = %s', $this->recData['login'],
                   ' AND tableid = %s)', 'e10.persons.persons');

    $table = [];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'person' => ['text' => $r['fullName'], 'suffix' => $r['id'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk' => $r['ndx']],
      ];

      $table[] = $item;
    }

    $h = [
      'person' => 'Osoba',
    ];

    if (count($table))
    {
      $this->addContent('body', [
        'pane' => 'e10-pane e10-pane-table', 'paneTitle' => ['text' => 'Osoby', 'class' => 'h2'],
        'table' => $table, 'header' => $h,
        ]
      );
    }
	}

	public function createContent ()
	{
    $this->tablePersons = new \e10\persons\TablePersons($this->app());
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}

