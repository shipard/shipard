<?php

namespace e10pro\custreg;

use \E10\utils, \E10\str, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;

/**
 * Class TableCal
 * @package E10pro\Hosting\Services
 */
class TableRegistrations extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.custreg.registrations', 'e10pro_custreg_registrations', 'Registrace zákazníků');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['firstName'].' '.$recData ['lastName']];

		return $hdr;
	}

	public function loadRegPerson ($recData, $tablePersons)
	{
		$item = ['firstName' => $recData['firstName'], 'lastName' => $recData['lastName'], 'id' => $recData['id'], 'ndx' => $recData['ndx']];

		// -- properties
		$q = 'SELECT * FROM [e10_base_properties] WHERE [tableid] = %s AND [recid] = %i';
		$rows = $this->db()->query($q, 'e10.persons.persons', $recData['ndx']);
		foreach ($rows as $r)
		{
			$pid = $r['property'];
			if (isset($item[$pid]))
				continue;

			if ($r['valueDate'])
			{
				$item[$pid] = $r['valueDate'];
			}
			else
				$item[$pid] = $r['valueString'];

			$item['properties'][$pid] = $r->toArray();
		}

		if (isset ($item['birthdate']))
			$item['birthDate'] = utils::datef ($item['birthdate'], '%D');
		if (isset ($item['bankaccount']))
			$item['bankAccount'] = $item['bankaccount'];

		// -- address
		$addresses = $tablePersons->loadAddresses([$recData['ndx']], TRUE);
		if (isset ($addresses[$recData['ndx']][0]))
		{
			$item['street'] = $addresses[$recData['ndx']][0]['street'];
			$item['city'] = $addresses[$recData['ndx']][0]['city'];
			$item['zipcode'] = $addresses[$recData['ndx']][0]['zipcode'];

			$item['address'] = $addresses[$recData['ndx']][0];
		}

		return $item;
	}
}


/**
 * Class ViewRegistrations
 * @package e10pro\custreg
 */
class ViewRegistrations extends TableView
{
	var $eventTypes;
	var $countries;

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['lastName'].' '.$item['firstName'];

		$address = $item['street'].', '.$item['city'].' '.$item['zipcode'];
		$listItem ['t2'] = ['text' => $address, 'icon' => 'icon-map-marker'];

		$props = [];
		$props [] = ['icon' => 'icon-envelope', 'text' => $item['email'], 'class' => 'e10-tag e10-tag-contact'];
		$props [] = ['icon' => 'icon-phone', 'text' => $item['phone'], 'class' => 'e10-tag e10-tag-contact'];
		$props [] = ['icon' => 'icon-institution', 'text' => $item['bankAccount'], 'class' => 'e10-tag e10-tag-contact'];
		$listItem ['t3'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_custreg_registrations]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [lastName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[lastName]', '[firstName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailRegistration
 * @package e10pro\custreg
 */
class ViewDetailRegistration extends TableViewDetail
{
	var $tablePersons;
	var $similarUsers = [];

	public function createDetailContent ()
	{
		$this->tablePersons = $this->app()->table ('e10.persons.persons');
		$this->item ['bankaccount'] = $this->item ['bankAccount'];

		$i = $this->item;

		$info[0] = ['p1' => 'Jméno', 't1' => $i['firstName'], 'p2' => 'OP', 't2' => $i['idcn']];
		$info[] = ['p1' => 'Příjmení', 't1' => $i['lastName'], 'p2' => 'Datum narození', 't2' => utils::datef($i['birthDate'])];
		$info[] = ['p1' => 'Ulice', 't1' => $i['street'], 'p2' => 'E-mail', 't2' => $i['email']];
		$info[] = ['p1' => 'Město', 't1' => $i['city'], 'p2' => 'Mobil', 't2' => $i['phone']];
		$info[] = ['p1' => 'PSČ', 't1' => $i['zipcode'], 'p2' => 'Účet', 't2' => $i['bankaccount']];

		$info[0]['_options']['cellClasses']['t1'] = 'width30';
		$info[0]['_options']['cellClasses']['t2'] = 'width30';


		$h = ['p1' => ' ', 't1' => '', 'p2' => ' ', 't2' => ''];

		$title = [['icon' => 'icon-globe', 'text' => 'Registrační údaje']];
		$title[] = ['type' => 'action', 'action' => 'addwizard', 'table' => 'e10.persons.persons',
			'text' => 'Přidat', 'data-class' => 'e10pro.custreg.RegistrationAddWizard', 'icon' => 'icon-plus-circle',
			'class' => 'pull-right', 'actionClass' => 'btn-xs',
			'data-addparams' => 'registrationNdx='.$this->item['ndx']];

		$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
							'title' => $title,
							'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);

		$this->searchSimilarUsers();
	}

	public function searchSimilarUsers ()
	{
		$this->item['birthDate'] = utils::datef ($this->item['birthDate'], '%D');

		$q[] = 'SELECT * FROM [e10_persons_persons] AS persons WHERE 1';

		array_push($q, ' AND [firstName] = %s', $this->item['firstName'], ' AND [lastName] = %s', $this->item['lastName']);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->addSimilarUser($r);
		}

		$this->similarUsers = \E10\sortByOneKey($this->similarUsers, 'order', TRUE);

		foreach ($this->similarUsers as $i)
		{
			$info[0] = ['p1' => 'Jméno', 't1' => $i['firstName'], 'p2' => 'OP', 't2' => $i['idcn']];
			$info[1] = ['p1' => 'Příjmení', 't1' => $i['lastName'], 'p2' => 'Datum narození', 't2' => utils::datef($i['birthdate'])];
			$info[2] = ['p1' => 'Ulice', 't1' => $i['street'], 'p2' => 'E-mail', 't2' => $i['email']];
			$info[3] = ['p1' => 'Město', 't1' => $i['city'], 'p2' => 'Mobil', 't2' => $i['phone']];
			$info[4] = ['p1' => 'PSČ', 't1' => $i['zipcode'], 'p2' => 'Účet', 't2' => $i['bankaccount']];

			$info[0]['_options']['cellClasses']['t2'] = $i['status']['idcn'];
			$info[1]['_options']['cellClasses']['t2'] = $i['status']['birthDate'];
			$info[2]['_options']['cellClasses']['t2'] = $i['status']['email'];
			$info[2]['_options']['cellClasses']['t1'] = $i['status']['street'];
			$info[3]['_options']['cellClasses']['t2'] = $i['status']['phone'];
			$info[3]['_options']['cellClasses']['t1'] = $i['status']['city'];
			$info[4]['_options']['cellClasses']['t2'] = $i['status']['bankAccount'];
			$info[4]['_options']['cellClasses']['t1'] = $i['status']['zipcode'];

			$info[0]['_options']['cellClasses']['t1'] = ' width30';
			$info[0]['_options']['cellClasses']['t2'] .= ' width30';

			$h = ['p1' => ' ', 't1' => '', 'p2' => ' ', 't2' => ''];

			$title = [['icon' => 'icon-user', 'text' => '#'.$i['id']]];
			$title[] = ['type' => 'action', 'action' => 'addwizard', 'table' => 'e10.persons.persons',
				'text' => 'Sloučit Osoby', 'data-class' => 'e10pro.custreg.RegistrationMergeWizard', 'icon' => 'icon-code-fork',
				'class' => 'pull-right', 'actionClass' => 'btn-xs',
				'data-addparams' => 'registrationNdx='.$this->item['ndx'].'&personNdx='.$i['ndx']];

			$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'title' => $title,
				'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);

			unset ($info);
		}
	}

	public function addSimilarUser ($recData)
	{
		$item = $this->table->loadRegPerson ($recData, $this->tablePersons);

		if (!isset($item['email'])) $item['email'] = '';
		if (!isset($item['phone'])) $item['phone'] = '';
		if (!isset($item['bankaccount'])) $item['bankaccount'] = '';

		$item['order'] = 10;

		$this->checkField('idcn', $item);
		$this->checkField('birthDate', $item);
		$this->checkField('email', $item);
		$this->checkField('phone', $item);
		$this->checkField('bankAccount', $item);
		$this->checkField('street', $item);
		$this->checkField('city', $item);
		$this->checkField('zipcode', $item);

		$this->similarUsers[] = $item;
	}

	public function checkField ($id, &$item)
	{
		if (isset ($item[$id]) && isset($this->item[$id]) && str::strcasecmp($item[$id], $this->item[$id]) === 0)
		{
			$item['status'][$id] = 'e10-row-plus';
			$item['order'] -= 2;
		}
		else
		if ((!isset($item[$id]) || $item[$id] == '') && (isset($this->item[$id]) && $this->item[$id] != ''))
		{
			$item['status'][$id] = 'e10-row-plus';
			$item['order']--;
		}
		else
		{
			$item['status'][$id] = 'e10-row-minus';
			$item['order']++;
		}
	}
}


/**
 * Class FormRegistration
 * @package e10pro\custreg
 */
class FormRegistration extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput('firstName');
			$this->addColumnInput('lastName');
			$this->addColumnInput('birthDate');
			$this->addColumnInput('idcn');
			$this->addColumnInput('street');
			$this->addColumnInput('city');
			$this->addColumnInput('zipcode');
			$this->addColumnInput('email');
			$this->addColumnInput('phone');
			$this->addColumnInput('bankAccount');
		$this->closeForm ();
	}
}


