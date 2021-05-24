<?php

namespace E10Pro\Reports\Persons;


use \E10\utils, E10Doc\Core\e10utils;


/**
 * Class reportContacts
 * @package E10Pro\Reports\Persons
 */
class reportContacts extends \E10\GlobalReport
{

	function init ()
	{
		$this->addMyParams();

		parent::init();

		$this->setInfo('icon', 'icon-envelope-o');
	}

	function createContent ()
	{
		$data = $this->createContent_Contacts();

		$h = ['firstName' => 'Jméno', 'lastName' => 'Příjmení', 'email' => 'E-mail', 'phone' => 'Telefon', 'street' => 'Ulice', 'city' => 'Město', 'zipcode' => 'PSČ'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

		$this->setInfo('title', 'Kontaktní údaje');
	}

	function createContent_Contacts ()
	{
		$q1 [] = 'SELECT props.recid as personNdx, props.valueString, props.property, persons.fullName as personFullName, persons.firstName as personFirstName, persons.lastName as personLastName, persons.company as personCompany  ';
		array_push ($q1, ' FROM e10_base_properties AS props');
		array_push ($q1, ' LEFT JOIN e10_persons_persons AS persons ON props.recid = persons.ndx');
		array_push ($q1, ' WHERE tableid = %s', 'e10.persons.persons');
		array_push ($q1, ' AND [group] = %s', 'contacts');
		array_push ($q1, ' AND [property] IN %in', ['email', 'phone']);
		array_push ($q1, ' AND persons.[docState] = 4000');

		// -- only with email
		array_push ($q1, ' AND EXISTS (SELECT recid FROM e10_base_properties WHERE props.recid = recid AND tableId = %s', 'e10.persons.persons',
											' AND [property] = %s', 'email', ')');


		$qv = $this->queryValues();
		// -- groups
		if (isset ($qv['personGroups']))
		{
			if (isset ($qv['personGroups']))
				array_push ($q1, ' AND EXISTS (SELECT ndx FROM e10_persons_personsgroups WHERE persons.ndx = e10_persons_personsgroups.person AND [group] IN %in', array_keys($qv['personGroups']), ')');
		}

		array_push ($q1, ' ORDER BY persons.fullName');

		$rows = $this->app->db()->query($q1);

		$data = [];

		forEach ($rows as $r)
		{
			$pndx = $r['personNdx'];

			if (!isset ($data[$pndx]))
			{
				$data[$pndx] = ['firstName' => $r['personFirstName'], 'lastName' => $r['personLastName'], 'fullName' => $r['personFullName'], 'company' => $r['personCompany']];
				if ($r['personCompany'])
					$data[$pndx]['firstName'] = '';
			}
			if ($r['property'] === 'email')
				$data[$pndx]['email'] = $r['valueString'];
			else
			if ($r['property'] === 'phone')
				$data[$pndx]['phone'] = $r['valueString'];
		}

		$q2 [] = 'SELECT * FROM e10_persons_address';
		array_push ($q2, ' WHERE tableid = %s', 'e10.persons.persons');
		$rows = $this->app->db()->query($q2);
		forEach ($rows as $r)
		{
			$pndx = $r['recid'];
			if (!isset ($data[$pndx]))
				continue;
			$data[$pndx]['street'] = $r['street'];
			$data[$pndx]['city'] = $r['city'];
			$data[$pndx]['zipcode'] = $r['zipcode'];
		}

		return $data;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		parent::createToolbarSaveAs ($printButton);
		$printButton['dropdownMenu'][] = ['text' => 'CSV (.csv)', 'icon' => 'icon-file-text-o', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'csv'];
		$printButton['dropdownMenu'][] = ['text' => 'vCard (.vcf)', 'icon' => 'icon-address-card', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'vcf'];
	}

	public function saveReportAs ()
	{
		if ($this->format === 'csv')
		{
			$fileName = utils::tmpFileName('csv');

			$h = ['firstName' => 'Jméno', 'lastName' => 'Příjmení', 'email' => 'E-mail', 'phone' => 'Telefon', 'street' => 'Ulice', 'city' => 'Město', 'zipcode' => 'PSČ'];

			$params ['colSeparator'] = ';';
			$rows = $this->createContent_Contacts();
			$data = utils::renderTableFromArrayCsv($rows, $h, $params);
			file_put_contents($fileName, $data);
			$this->fullFileName = $fileName;

			return;
		}

		if ($this->format === 'vcf')
		{
			$fileName = utils::tmpFileName('vcf');
			$rows = $this->createContent_Contacts();
			foreach ($rows as $row)
			{
				$vcard = $this->VCardOneRow($row);
				file_put_contents($fileName, $vcard, FILE_APPEND);
			}
			$this->fullFileName = $fileName;

			return;
		}

		parent::saveReportAs();
	}

	function VCardOneRow($row)
	{
		$v = "BEGIN:VCARD\nVERSION:3.0\r\n";

		if ($row['company'])
		{
			$v .= "KIND:organization\r\n";
			$v .= 'N:'.$this->vcEscape($row['fullName'])."\r\n";
		}
		else
			$v .= 'N:'.$this->vcEscape($row['firstName']).';'.$row['lastName']."\r\n";

		$v .= 'FN:'.$this->vcEscape($row['fullName'])."\r\n";
		if (isset($row['phone']))
			$v .= 'TEL;TYPE=WORK,VOICE:'.$this->vcEscape($row['phone'])."\r\n";
		if (isset($row['email']))
			$v .= 'EMAIL:'.$this->vcEscape($row['email'])."\r\n";

		$v .= "END:VCARD\r\n";

		return $v;
	}

	function vcEscape ($str)
	{
		return str_replace([';', ',', ':'], ['\;', '\,', '\:'], $str);
	}

	function addMyParams ()
	{
		$grps = $this->app()->cfgItem ('e10.persons.groups');
		if (count($grps) !== 0)
		{
			$chbxPersonGroups = [];
			forEach ($grps as $g)
				$chbxPersonGroups[$g['id']] = $g['name'];

			$this->qryPanelAddCheckBoxes($chbxPersonGroups, 'personGroups', 'Skupiny');
		}
	}
}


/**
 * Class reportDuplicities
 * @package E10Pro\Reports\Persons
 */
class reportDuplicities extends \e10doc\core\libs\reports\GlobalReport
{
	var $tablePersons;
	var $allProperties;

	function init ()
	{
		$this->allProperties = $this->app->cfgItem ('e10.base.properties', []);

		$this->addParam('switch', 'deleted', ['title' => 'Včetně smazaných', 'switch' => ['0' => 'Ne', '1' => 'Ano']]);
		$this->addParam('switch', 'maxCount', ['title' => 'Max. počet', 'switch' => ['100' => '100', '500' => '500', '1000' => '1 000', '0' => 'Vše']]);

		parent::init();
		$this->tablePersons = $this->app->table ('e10.persons.persons');

		$this->setInfo('title', 'Duplicitní osoby');
		$this->setInfo('icon', 'icon-code-fork');
		$this->setInfo('param', 'Včetně smazaných', $this->reportParams ['deleted']['activeTitle']);
	}

	function createContent ()
	{
		// -- properties
		$q[] = 'SELECT [property], [group], [tableid], [valueString], COUNT(*) as cnt FROM e10_base_properties props';
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON props.recid = persons.ndx AND props.tableid = %s', 'e10.persons.persons');
		//array_push ($q, ' WHERE [group] = %s', 'itemids', ' AND valueString != %s', '');
		array_push ($q, ' WHERE valueString != %s', '');

		if ($this->reportParams ['deleted']['value'] == 0)
			array_push ($q, ' AND persons.[docState] != %i', 9800);

		array_push ($q, ' GROUP BY property, [group], tableid, valueString HAVING cnt > 1');
		if ($this->reportParams ['maxCount']['value'] != 0)
			array_push ($q, ' LIMIT %i', $this->reportParams ['maxCount']['value']);

		$rows = $this->app->db()->query($q);

		$data = [];

		forEach ($rows as $r)
		{
			$p = $this->allProperties [$r['property']];

			$newItem = [
				'pn' => $p['name'].' '.$r['valueString'],
				'_options' => ['class' => 'subheader separator', 'colSpan' => ['pn' => 3]]
			];
			$data[] = $newItem;

			$qi [] = 'SELECT persons.*, ';
			array_push ($qi, ' (SELECT COUNT(*) FROM e10doc_core_heads WHERE person = persons.ndx) as cntHeads,');
			array_push ($qi, ' (SELECT COUNT(*) FROM e10doc_core_rows WHERE person = persons.ndx) as cntRows');
			array_push ($qi, ' FROM [e10_persons_persons] persons');
			array_push ($qi, ' WHERE EXISTS (SELECT ndx FROM e10_base_properties WHERE persons.ndx = e10_base_properties.recid AND valueString = %s AND tableid = %s AND property = %s AND [group] = %s)',
												$r['valueString'], 'e10.persons.persons', $r['property'], $r['group']);
			$persons = $this->app->db()->query($qi);

			$thisBlock = [];
			$personsPks = [];
			foreach ($persons as $person)
			{
				if ($this->reportParams ['deleted']['value'] == 0 && $person['docState'] === 9800)
					continue;

				$personNdx = $person['ndx'];

				$docStates = $this->tablePersons->documentStates ($person);
				$docStateClass = $this->tablePersons->getDocumentStateInfo ($docStates, $person, 'styleClass');

				$itm = [
					'pn' => ['text' => $person['id'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $personNdx],
					'name' => [['text' => $person['fullName'], 'icon' => $this->tablePersons->tableIcon ($person), 'class' => 'block']],
					'cntHeads' => $person['cntHeads'], 'cntRows' => $person['cntRows'],
					'_options' => array ('cellClasses' => ['pn' => $docStateClass])
				];

				$personsPks[] = $personNdx;
				$thisBlock[$personNdx] = $itm;
			}

			$properties = $this->tablePersons->loadProperties ($personsPks);
			$addresses = $this->tablePersons->loadAddresses ($personsPks);

			foreach ($thisBlock as $pndx => $oneItem)
			{
				$oneItem ['merge'] = [
					'type' => 'action', 'action' => 'addwizard', 'text' => 'Sloučit', 'icon' => 'icon-code-fork', 'class' => 'btn-xs',
					'data-table' => 'e10.witems.items', 'data-pk' => $oneItem['pn']['pk'], 'data-class' => 'lib.persons.MergePersonsWizard',
					'data-addparams' => 'mergedItems='.implode(',', $personsPks)
				];

				$this->addPersonProperties ($properties, $pndx, $oneItem);
				$this->addPersonAddresses ($addresses, $pndx, $oneItem);

				$data[] = $oneItem;
			}

			unset($thisBlock);
			unset($itemsPks);
			unset($qi);
		}
		unset($q);

		// -- names
		$q[] = 'SELECT fullName, COUNT(fullName) as cnt FROM [e10_persons_persons]';
		if ($this->reportParams ['deleted']['value'] == 0)
			array_push ($q, ' WHERE [docState] != %i', 9800);
		array_push ($q, ' GROUP BY fullName HAVING cnt > 1');
		if ($this->reportParams ['maxCount']['value'] != 0)
			array_push ($q, ' LIMIT %i', $this->reportParams ['maxCount']['value']);

		$rows = $this->app->db()->query($q);
		forEach ($rows as $r)
		{
			$newItem = [
				'pn' => 'Název: '.$r['fullName'],
				'_options' => ['class' => 'subheader separator', 'colSpan' => ['pn' => 3]]
			];
			$data[] = $newItem;

			$qi [] = 'SELECT persons.*, ';
			array_push ($qi, ' (SELECT COUNT(*) FROM e10doc_core_heads WHERE person = persons.ndx) as cntHeads,');
			array_push ($qi, ' (SELECT COUNT(*) FROM e10doc_core_rows WHERE person = persons.ndx) as cntRows');
			array_push ($qi, ' FROM [e10_persons_persons] persons');
			array_push ($qi, ' WHERE persons.fullName = %s', $r['fullName']);
			array_push ($qi, ' ORDER BY id');
			$persons = $this->app->db()->query($qi);

			$thisBlock = [];
			$personsPks = [];
			foreach ($persons as $person)
			{
				if ($this->reportParams ['deleted']['value'] == 0 && $person['docState'] === 9800)
					continue;
				$personNdx = $person['ndx'];

				$docStates = $this->tablePersons->documentStates ($person);
				$docStateClass = $this->tablePersons->getDocumentStateInfo ($docStates, $person, 'styleClass');

				$itm = [
					'pn' => ['text' => $person['id'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $personNdx],
					'name' => [['text' => $person['fullName'], 'icon' => $this->tablePersons->tableIcon ($person), 'class' => 'block']],
					'cntHeads' => $person['cntHeads'], 'cntRows' => $person['cntRows'],
					'_options' => ['cellClasses' => ['pn' => $docStateClass]]
				];
				$personsPks[] = $personNdx;
				$thisBlock[$personNdx] = $itm;
			}

			$properties = $this->tablePersons->loadProperties ($personsPks);
			$addresses = $this->tablePersons->loadAddresses ($personsPks);

			foreach ($thisBlock as $pndx => $oneItem)
			{
				$oneItem ['merge'] = [
					'type' => 'action', 'action' => 'addwizard', 'text' => 'Sloučit', 'icon' => 'icon-code-fork', 'class' => 'btn-xs',
					'data-table' => 'e10.witems.items', 'data-pk' => $oneItem['pn']['pk'], 'data-class' => 'lib.persons.MergePersonsWizard',
					'data-addparams' => 'mergedItems='.implode(',', $personsPks)
				];

				$this->addPersonProperties ($properties, $pndx, $oneItem);
				$this->addPersonAddresses ($addresses, $pndx, $oneItem);

				$data[] = $oneItem;
			}

			unset($thisBlock);
			unset($itemsPks);
			unset($qi);
		}

		$title = 'Duplicitní osoby';

		$h = ['pn' => 'Osoba', 'name' => 'Název', 'cntHeads' => ' Doklady', 'cntRows' => ' Pohyby', 'merge' => 'Akce'];
		$this->addContent (['type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data]);
	}

	public function addPersonAddresses ($addresses, $pndx, &$oneItem)
	{
		if (isset($addresses[$pndx]))
		{
			foreach ($addresses[$pndx] as $pset)
			{
				$pset['class'] .= ' e10-small';
				$oneItem ['name'][] = $pset;
			}
		}
	}

	public function addPersonProperties ($properties, $pndx, &$oneItem)
	{
		if (isset($properties[$pndx]))
		{
			foreach ($properties[$pndx] as $pset)
			{
				foreach ($pset as $psetItem)
				{
					$psetItem['class'] .= ' e10-small';
					$oneItem ['name'][] = $psetItem;
				}
			}
		}

	}
}
