<?php

namespace E10\Persons;
use \Shipard\Viewer\TableViewPanel;
use \e10\base\libs\UtilsBase;

require_once __DIR__ . '/../../base/base.php';


use \E10\Application, \E10\utils, \E10\DataModel, \E10\TableView, \E10\TableViewDetail, \Shipard\Form\TableForm, \E10\DbTable;


/**
 * Tabulka Osoby
 *
 */

class TablePersons extends DbTable
{
	public static $defaultIconSet = array ('x-company', 'x-man', 'x-woman');

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.persons.persons", "e10_persons_persons", "Osoby", 1000);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['personType']))
			$recData['personType'] = 0;

		$this->checkDefaultValue ($recData, 'company', 0);
		$this->checkDefaultValue ($recData, 'complicatedName', 0);
		$this->checkDefaultValue ($recData, 'beforeName', '');
		$this->checkDefaultValue ($recData, 'afterName', '');
		$this->checkDefaultValue ($recData, 'middleName', '');
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$idFormula = $this->app()->cfgItem ('flags.e10.persons.idFormula', '%n');
			$recData['id'] = utils::createRecId($recData, $idFormula);
			$this->app()->db()->query ("UPDATE [e10_persons_persons] SET [id] = %s WHERE [ndx] = %i", $recData['id'], $recData['ndx']);
		}

		if ($recData ['docStateMain'] == 2 && $recData ['roles'] != '')
		{ // register user
			// -- check login/email
			if (!isset ($recData ['login']) || $recData ['login'] == '')
			{
				$email = $this->app()->db()->fetch('SELECT * FROM [e10_base_properties] WHERE [tableid] = %s AND [property] = %s AND [group] = %s AND [recid] = %i', 'e10.persons.persons', 'email', 'contacts', $recData['ndx']);
				if ($email)
				{
					$emailValue = trim($email ['valueString']);
					$emailHash = md5(strtolower(trim($email ['valueString'])));

					$this->app()->db()->query ("UPDATE [e10_persons_persons] SET [login] = %s, [loginHash] = %s WHERE [ndx] = %i", $emailValue, $emailHash, $recData['ndx']);
					$recData ['login'] = $emailValue;
					$recData ['loginHash'] = $emailHash;
				}
			}

			// -- send registration request
			if (isset ($recData ['login']) && $recData ['login'] != '' && $recData ['accountState'] === 0)
			{
				$this->app()->authenticator->activateAccount ($recData);
			}
		}

		if ($recData ['docStateMain'] === 2 || $recData ['docStateMain'] === 2)
		{
			// -- set validate request
			$this->app()->db()->query ('UPDATE [e10_persons_personsValidity] SET [revalidate] = 1 WHERE [person] = %i', $recData['ndx']);
		}
	} // checkAfterSave

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['personType']) && $recData['personType'] == 3)
		{ // robot
			//$recData ['fullName'] = str_replace('  ', ' ', trim ($recData ['lastName'].' '.$recData ['firstName'].' '.$recData ['middleName']));
			$recData ['lastName'] = $recData ['fullName']; // for better order

			if (isset($recData ['login']) && $recData ['login'] !== '')
				$recData ['loginHash'] = md5(strtolower(trim($recData ['login'])));
			else
				$recData ['loginHash'] = '';
		}
		else
		if ($recData['company'] === 0)
		{ // people
			if (!isset($recData['complicatedName']) || $recData['complicatedName'] === 0)
			{
				$recData ['beforeName'] = '';
				$recData ['afterName'] = '';
				$recData ['middleName'] = '';
			}
			$recData ['fullName'] = str_replace('  ', ' ', trim ($recData ['beforeName'].' '.$recData ['lastName'].' '.$recData ['firstName'].' '.$recData ['middleName'].' '.$recData ['afterName']));

			if (isset($recData ['login']) && $recData ['login'] !== '')
				$recData ['loginHash'] = md5(strtolower(trim($recData ['login'])));
			else
				$recData ['loginHash'] = '';

			$recData['personType'] = 1;
			//if (isset ($recData['passwordReset']) && $recData['passwordReset'] !== '')
			//	$this->app()->authenticator->resetPassword ($recData, $recData['passwordReset']);
		}
		else
		{ // company
			$recData ['firstName'] = '';
			$recData ['lastName'] = $recData ['fullName']; // for better order
			$recData ['beforeName'] = '';
			$recData ['afterName'] = '';
			$recData ['middleName'] = '';
			$recData ['login'] = '';
			$recData ['loginHash'] = '';

			$recData['personType'] = 2;
		}

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$idFormula = $this->app()->cfgItem ('flags.e10.persons.idFormula', '%n');
			$recData['id'] = utils::createRecId($recData, $idFormula);
		}
		if ($recData['docState'] === 4000)
				$recData['moreAddress'] = 1;
	}

	public function columnRefInput ($form, $srcTable, $srcColumnId, $options, $label, $inputPrefix)
	{
		if (!($options & TableForm::coHeader))
			return parent::columnRefInput ($form, $srcTable, $srcColumnId, $options, $label, $inputPrefix);

		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;

		$ndxColumn = '';
		$titleColumn = '';

		$autocomplete = $this->app()->model()->tableProperty ($this, 'autocomplete');
		if ($autocomplete)
		{
			$ndxColumn = $autocomplete ['columnValue'];
			$titleColumn = $autocomplete ['columnTitle'];
		}

		$q = "SELECT [fullName] FROM [e10_persons_persons] WHERE [ndx] = " . intval ($pk);
		$refRec = $this->app()->db()->query ($q)->fetch ();
		$refTitle = $refRec [$titleColumn];

		$thisTableId = $this->tableId();
		$srcTableId = $srcTable->tableId ();
		$ip = str_replace ('.', '_', $inputPrefix);

		$columnInputClass = 'e10-inputNdx';
		if ($options & DataModel::coSaveOnChange)
			$columnInputClass .= ' e10-ino-saveOnChange';

		$class = 'e10-inputReference header';

		$inputParams = '';
		if ($options & TableForm::coReadOnly)
			$inputParams = " readonly='readonly'";

		$inputCode  = '';
		$inputCode .= "<div id='{$form->fid}_refinp_$ip{$srcColumnId}' class='$class'$inputParams>";
		$inputCode .= "<input type='hidden' name='$inputPrefix{$srcColumnId}' id='inp_$ip{$srcColumnId}' class='$columnInputClass' data-column='$srcColumnId' data-fid='{$form->fid}'/>";
		$inputCode .= "<input name='$inputPrefix{$srcColumnId}' id='inp_refid_$ip{$srcColumnId}' class='e10-inputRefId e10-viewer-search' data-column='$srcColumnId' data-srctable='$srcTableId' data-sid='{$form->fid}Sidebar' autocomplete='off'$inputParams/>";

		$inputCode .= "<span class='btns' style='display:none;'>";
		if (!($options & TableForm::coReadOnly))
			$inputCode .= $this->app()->ui()->icon('system/actionClose', 'e10-inputReference-clearItem').'&nbsp;';
		$inputCode .= $this->app()->ui()->icon('system/actionOpen', 'e10-inputReference-editItem', 'i', " data-table='$thisTableId' data-pk='0'").'&nbsp;';
		$inputCode .= "</span>";

		$inputCode .= "<span class='e10-refinp-infotext'>" .$refTitle . '</span>';

		$clsf = \E10\Base\ListClassification::referenceWidget($form, $this, $pk);
		if ($clsf['html'] !== '')
		{
			$inputCode .= "<div style='padding: 2px; clear: both; margin: 4px; '>".$clsf['html'].'</div>';
		}

		$inputCode .= '</div>';

		$info ['widgetCode'] = NULL;
		$info ['inputCode'] = $inputCode;
		$info ['labelCode'] = NULL;
		if ($label)
			$info ['labelCode'] = "<label for='inp_refid_$ip{$srcColumnId}'>" . utils::es ($label) . "</label>";

		return $info;
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData ['id'] = '';

		$recData ['accountType'] = 0;
		$recData ['accountState'] = 0;
		$recData ['login'] = '';
		$recData ['loginHash'] = '';
		$recData ['roles'] = '';

		return $recData;
	}

	public function formId ($recData, $ownerRecData = NULL, $operation = 'edit')
	{
		if (isset($recData['personType']) && $recData['personType'] == 3)
			return 'robot';

		return 'personDefault';
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$title = $recData['fullName'];
		$info = ['title' => $title, 'docID' => '#'.$recData['id']];

		$info ['persons']['to'][] = $recData['ndx'];

		return $info;
	}

	function propertyEnabled ($recData, $groupId, $propertyId, $property, $loadedProperties)
	{
		if ($recData['personType'] == 3 && $groupId !== 'contacts')
			return FALSE;

		if (isset ($property['needPerson']) && $recData['company'] === 1)
			return FALSE;
		if (isset ($property['needCompany']) && $recData['company'] === 0)
			return FALSE;
		return TRUE;
	}

	public function checkDocumentPropertiesList (&$properties, $recData)
	{
		if (!isset($recData ['ndx']) || !$recData ['ndx'])
			$inGroups = [];
		else
			$inGroups = $this->db()->query ('SELECT [group] FROM [e10_persons_personsgroups] WHERE [person] = %i', $recData['ndx'])->fetchPairs(NULL, 'group');

		if (isset($recData['maingroup']))
			$inGroups[] = $recData['maingroup'];

		$groupsProperties = $this->app()->cfgItem('e10.persons.groupsProperties', FALSE);

		if ($groupsProperties === FALSE)
			return;

		foreach ($groupsProperties as $groupNdx => $groupCfg)
		{
			if (in_array($groupNdx, $inGroups))
				$properties += $groupCfg;
		}
	}

	public function loadAddresses ($pkeys, $asRecs = FALSE)
	{
		$addresses = [];
		if (count($pkeys))
		{
			$q = 'SELECT * FROM [e10_persons_address] WHERE tableid = %s AND recid IN %in';
			$addrs = $this->db()->query ($q, 'e10.persons.persons', $pkeys);
			if ($asRecs)
			{
				forEach ($addrs as $a)
					$addresses [$a ['recid']][] = $a;
			}
			else
			forEach ($addrs as $a)
				if ($a['street'] !== '' || $a['city'] !== '')
					$addresses [$a ['recid']][] = ['text' => $a['street'] . ', ' . $a['city'], 'icon' => 'system/iconMapMarker', 'class' => 'nowrap'];
		}

		return $addresses;
	}

	public function loadProperties ($pkeys, $disabledProperties = FALSE)
	{
		if (is_array($pkeys))
			$personsIds = implode (', ', $pkeys);
		else
			$personsIds = strval($pkeys);

		$properties = array ();

		if ($personsIds === '')
			return $properties;

		/* groups */
		$allGroups = $this->app()->cfgItem ('e10.persons.groups', FALSE);
		$q = "SELECT * FROM [e10_persons_personsgroups] WHERE person IN ($personsIds)";
		$groups = $this->db()->fetchAll ($q);
		forEach ($groups as $g)
		{
			$thisGroup = utils::searchArray ($allGroups, 'id', $g ['group']);
			if ($thisGroup)
				$properties [$g ['person']]['groups'][] = array ('text' => $thisGroup ['name'], 'class' => 'label label-default');
		}

		/* properties */

		$pdefs = $this->app()->cfgItem ('e10.base.properties');

		$q = "SELECT [recid], [group], [valueString], [valueDate], [property], [note] FROM [e10_base_properties] WHERE [tableid] = %s AND [recid] IN ($personsIds)";
		$contacts = $this->db()->fetchAll ($q, 'e10.persons.persons');
		forEach ($contacts as $c)
		{
			if ($c ['valueString'] == '')
				continue;
			if ($disabledProperties && in_array($c['property'], $disabledProperties))
				continue;

			$text = $c ['valueString'];
			if ($c ['valueDate'])
				$text = utils::datef ($c ['valueDate'], '%D');

			$np = array ('text' => $text, 'pid' => $c ['property'], 'class' => 'nowrap');
			if (isset ($pdefs [$c ['property']]['icon']))
				$np ['icon'] = $pdefs [$c ['property']]['icon'];
			if (isset ($pdefs [$c ['property']]['icontxt']))
				$np ['icontxt'] = $pdefs [$c ['property']]['icontxt'];
			if ($c['note'] != '')
				$np['prefix'] = $c['note'];
			if ($c ['valueDate'])
				$np['valueDate'] = $c['valueDate'];

			$properties [$c ['recid']][$c ['group']][] = $np;
		}

		return $properties;
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->icon ($recData);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$ndx = $recData ['ndx'];
		$properties = $this->loadProperties ($ndx);
		$classification = \E10\Base\loadClassification ($this->app(), $this->tableId(), $ndx);

		$contactInfo = [];
		if (isset ($properties [$ndx]['ids']))
			$contactInfo = $properties [$ndx]['ids'];

		if (count($contactInfo) !== 0)
			$hdr ['info'][] = ['class' => 'info', 'value' => $contactInfo];

		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['fullName']], ['text' => '#'.$recData ['id'], 'class' => 'pull-right id']]];
		
		$secLine = [];
		
		if (isset ($properties [$ndx]['groups']))
		{
			$secLine = $properties [$ndx]['groups'];
			$secLine[0]['icon'] = 'e10-persons-groups';
		}

		if (count($secLine) !== 0)
			$hdr ['info'][] = ['class' => 'info', 'value' => $secLine];

		$image = UtilsBase::getAttachmentDefaultImage($this->app(), $this->tableId(), $recData ['ndx']);
		if (isset($image ['smallImage']))
		{
			$hdr ['image'] = $image ['smallImage'];
			unset ($hdr ['icon']);
		}
		
		return $hdr;
	}

	public function icon ($recData, $iconSet = NULL)
	{
		if (isset($recData ['personType']) && $recData ['personType'] === 3)
			return 'system/personRobot';

		if ($recData ['company'])
			return 'system/personCompany';

		return 'system/personHuman';
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return $this->icon ($recData);
	}

	public function columnInfoAutocompleteQuery ($srcTable, $srcColumnDef, $srcRecData, $search)
	{
		$q = ' [docStateMain] < 3';
		if (isset ($srcColumnDef ['params']['inGroup']))
		{
			$g = intval ($srcColumnDef ['params']['inGroup']);
			$q .= " AND EXISTS (SELECT ndx FROM e10_persons_personsgroups WHERE e10_persons_persons.ndx = e10_persons_personsgroups.person and [group] = $g)";
			return $q;
		}
		return $q;
		//return parent::columnInfoAutocompleteQuery ($srcTable, $srcColumnDef, $srcRecData, $search);
	}

	public function loadDocument ($ndx)
	{
		$d = array ();
		$d ['recData'] = $this->loadItem ($ndx);
		if (!$d ['recData'])
			return FALSE;

		$d ['lists'] = $this->loadLists ($d ['recData']);

		$xf = array ();
		$xf ['person'] = array ('firstName' => $d ['recData']['firstName'], 'lastName' => $d ['recData']['lastName'],
														'fullName' => $d ['recData']['fullName'], 'company' => $d ['recData']['company'],
														'login' => $d ['recData']['login']);

		forEach ($d ['lists']['properties'] as $property)
			if ($property['group'] === 'contacts')
				$xf ['contacts'][] = array ('type' => $property ['property'], 'value' => $property ['value']);

		forEach ($d ['lists']['properties'] as $property)
			if ($property['group'] === 'ids')
			{
				$xf ['ids'][] = array ('type' => $property ['property'], 'value' => $property ['value']);
				$d ['property'][$property ['property']][] = $property ['value'];
			}
		forEach ($d ['lists']['address'] as $address)
		{
			$xf ['address'][] = array ('specification' => $address ['specification'], 'street' => $address ['street'],
																 'city' => $address ['city'], 'zipcode' => $address ['zipcode'], 'country' => $address ['country']);
			$d ['address'][] = ['specification' => $address ['specification'], 'street' => $address ['street'],
														'city' => $address ['city'], 'zipcode' => $address ['zipcode'], 'country' => $address ['country']];
		}
		$d ['xf'] = $xf;


		return $d;
	}

	public function loadPersonInfo ($ndx)
	{
		$info = [];
		$info['recData'] = $this->loadItem ($ndx);
		if ($info['recData'] === FALSE)
			return FALSE;

		$q [] = 'SELECT valueString FROM [e10_base_properties]';
		array_push ($q, ' WHERE [tableid] = %s', 'e10.persons.persons', ' AND [recid] = %i', $ndx);
		array_push ($q, ' AND [property] = %s', 'email', ' AND [group] = %s', 'contacts');
		array_push ($q, ' ORDER BY ndx');
		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			if (!isset($info['email']))
				$info['email'] = $r['valueString'];
			$info['emails'][] = $r['valueString'];
		}

		return $info;
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;
		if ($columnId === 'accountType')
		{
			if (!$this->app()->cfgServer['useHosting'] && $cfgKey == 2)
				return FALSE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}
}


/**
 * Class ViewPersonsBase
 * @package E10\Persons
 */
class ViewPersonsBase extends TableView
{
	public $mainGroup = 0;
	public $properties = array();
	var $classification = [];
	public $addresses = array();
	protected $loadAddresses = FALSE;
	protected $searchInProperties = TRUE;
	protected $showValidity = TRUE;
	protected $ftsInArchive = TRUE;

	public function init ()
	{
		if ($this->queryParam ('systemGroup'))
			$this->setMainGroup ($this->queryParam ('systemGroup'));

		if ($this->mainGroup)
			$this->addAddParam ('maingroup', $this->mainGroup);

		parent::init();
	}

	public function icon ($recData, $iconSet = NULL)
	{
		return $this->table->icon ($recData, $iconSet);
	}

	public function selectRows ()
	{
		$q = $this->selectRowsCmd (0);
		$this->runQuery ($q);

		if (count ($this->queryRows) !== 0)
			return;

		$q = $this->selectRowsCmd (1);
		$this->runQuery ($q);
	}

	public function selectRowsCmd ($selectLevel)
	{
		$this->checkFastSearch ();

		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT persons.*';

		if ($this->showValidity)
			array_push ($q, ', validity.valid AS valid, validity.validVat AS validVat, validity.taxPayer AS taxPayer');

		array_push ($q, ' FROM [e10_persons_persons] AS persons');

		if ($this->showValidity)
			array_push ($q, ' LEFT JOIN [e10_persons_personsValidity] AS validity ON persons.ndx = validity.person');

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');

			$numItems = count($this->fastSearch);

			$i = 0;
			foreach($this->fastSearch as $searchValue)
			{
				if ($selectLevel === 0)
				{
					array_push ($q, '(([lastName] LIKE %s', $searchValue.'%');
					array_push ($q, ' OR [firstName] LIKE %s', $searchValue.'%');
					array_push ($q, ')');
					array_push ($q, ' OR ([lastName] LIKE %s', '%'.$searchValue.'%', ' AND [company] = 1)', ')');
				}
				else
				if ($selectLevel === 1)
					array_push ($q, '([lastName] LIKE %s', '%'.$searchValue.'%', ' OR [firstName] LIKE %s', '%'.$searchValue.'%', ')');
				if(++$i !== $numItems)
					array_push ($q, ' AND ');
			}

			if ($this->searchInProperties)
				array_push ($q, " OR EXISTS (SELECT ndx FROM e10_base_properties WHERE persons.ndx = e10_base_properties.recid AND valueString LIKE %s AND tableid = %s)", '%'.$fts.'%', 'e10.persons.persons');

			if ($numItems === 1)
				array_push ($q, ' OR ([id] LIKE %s)', $fts.'%');

			array_push ($q, ") ");
		}

		// -- mainGroup
		if ($this->mainGroup)
			array_push ($q, " AND EXISTS (SELECT ndx FROM e10_persons_personsgroups WHERE persons.ndx = e10_persons_personsgroups.person and [group] = {$this->mainGroup})");

		$this->defaultQuery ($q);
		$this->qryPanel ($q);

		// -- aktu??ln??
		if ($mainQuery === 'active' || $mainQuery == '')
		{
			if ($fts != '' && $this->ftsInArchive)
				array_push($q, ' AND [docStateMain] != 4');
			else
				array_push($q, ' AND [docStateMain] < 4');
		}

		// -- arch??v
		if ($mainQuery == 'archive')
			array_push ($q, " AND [docStateMain] = 5");

		// ko??
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [lastName], [firstName] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY [docStateMain], [lastName], [firstName] ' . $this->sqlLimit ());

		return $q;
	} // selectRowsCmd

	public function qryPanel (array &$q)
	{
		$qv = $this->queryValues();

		// -- type
		$humans = isset ($qv['personTypes']['humans']);
		$companies = isset ($qv['personTypes']['companies']);
		if ($humans xor $companies)
		{
			if ($humans)
				array_push ($q, ' AND [company] = 0');
			else
				array_push ($q, ' AND [company] = 1');
		}

		// -- groups
		if (isset ($qv['personGroups']))
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsgroups WHERE persons.ndx = e10_persons_personsgroups.person AND [group] IN %in', array_keys($qv['personGroups']), ')');

		// -- countries
		if (isset ($qv['personCountries']))
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_address',
											' WHERE persons.ndx = e10_persons_address.recid AND [tableid] = %s', 'e10.persons.persons', ' AND [country] IN %in', array_keys($qv['personCountries']),
											')');
		// -- city
		if (isset ($qv['geo']['city']) && $qv['geo']['city'] != '')
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_address',
				' WHERE persons.ndx = e10_persons_address.recid AND [tableid] = %s', 'e10.persons.persons', ' AND [city] LIKE %s', '%'.$qv['geo']['city'].'%',
				')');
		// -- street
		if (isset ($qv['geo']['street']) && $qv['geo']['street'] != '')
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_address',
				' WHERE persons.ndx = e10_persons_address.recid AND [tableid] = %s', 'e10.persons.persons', ' AND [street] LIKE %s', '%'.$qv['geo']['street'].'%',
				')');
		// -- zipcode
		if (isset ($qv['geo']['zipcode']) && $qv['geo']['zipcode'] != '')
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_address',
				' WHERE persons.ndx = e10_persons_address.recid AND [tableid] = %s', 'e10.persons.persons', ' AND [zipcode] LIKE %s', $qv['geo']['zipcode'].'%',
				')');

		// -- tags
		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE persons.ndx = recid AND tableId = %s', 'e10.persons.persons');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		// -- others - with error
		$withError = isset ($qv['others']['withError']);
		if ($withError)
			array_push($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person AND [valid] = 2)');

		$unused = isset ($qv['others']['unused']);
		if ($unused)
			array_push($q, ' AND persons.lastUseDate IS NULL');
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->properties = $this->table->loadProperties ($this->pks, ['officialName', 'shortName']);
		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

		// -- addresses
		if ($this->loadAddresses)
			$this->addresses = $this->table->loadAddresses($this->pks);
	}

	function decorateRow (&$item)
	{
		if (isset ($this->properties [$item ['pk']]['groups']))
			$item ['i2'] = $this->properties [$item ['pk']]['groups'];

		if (isset ($this->properties [$item ['pk']]['ids']))
			$item ['t2'] = array_merge($item ['t2'], $this->properties [$item ['pk']]['ids']);
		else
		if (isset ($this->properties [$item ['pk']]['contacts']))
			$item ['t2'] = array_merge ($item ['t2'], array_slice ($this->properties [$item ['pk']]['contacts'], 0, 2, TRUE));

		if (!count($item ['t2']))
			$item ['t2'] = '??';

		if (isset ($this->classification [$item ['pk']]))
		{
			$item ['t3'] = [];
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);
		}
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->icon ($item);
		$listItem ['t1'] = $item['fullName'];

		$listItem ['i1'] = array ('text' => '#'.$item['id'], 'class' => 'id');

		$listItem ['t2'] = [];

		if ($this->showValidity)
		{
			if ($item['valid'] === 2)
				$listItem ['t2'][] = ['text' => '', 'icon' => 'system/iconWarning', 'class' => 'e10-error'];
			//elseif ($item['valid'] === 1)
			//	$listItem ['t2'][] = ['text' => '', 'icon' => 'system/iconCheck', 'class' => 'e10-success e10-off e10-small'];
		}

		return $listItem;
	}

	public function setMainGroup ($group)
	{
		$groupsMap = $this->table->app()->cfgItem ('e10.persons.groupsToSG', FALSE);
		if ($groupsMap && isset ($groupsMap [$group]))
			$this->mainGroup = $groupsMap [$group];
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- people/company
		$chbxPersonTypes = [
			'humans' => ['title' => 'Lid??', 'id' => 'humans'], 'companies' => ['title' => 'Spole??nosti', 'id' => 'companies']
		];
		$paramsPersonTypes = new \E10\Params ($this->app());
		$paramsPersonTypes->addParam ('checkboxes', 'query.personTypes', ['items' => $chbxPersonTypes]);
		$qry[] = array ('id' => 'itemTypes', 'style' => 'params', 'title' => 'Osoby', 'params' => $paramsPersonTypes);

		// -- groups
		if (!$this->mainGroup)
		{
			$grps = $this->app()->cfgItem ('e10.persons.groups');
			if (count($grps) !== 0)
			{
				$chbxPersonGroups = [];
				forEach ($grps as $g)
					$chbxPersonGroups[$g['id']] = ['title' => $g['name'], 'id' => $g['id']];

				$paramsPersonGroups = new \E10\Params ($panel->table->app());
				$paramsPersonGroups->addParam ('checkboxes', 'query.personGroups', ['items' => $chbxPersonGroups]);
				$qry[] = array ('id' => 'itemGroups', 'style' => 'params', 'title' => 'Skupiny', 'params' => $paramsPersonGroups);
			}
		}

		// -- countries
		$paramsPersonCountries = new \E10\Params ($panel->table->app());

		$countriesCfg = $this->app()->cfgItem ('e10.base.countries');
		$countriesQry = 'SELECT distinct country FROM [e10_persons_address] ORDER BY country';
		$countriesRows = $this->table->db()->query ($countriesQry);
		if (count($countriesRows) !== 0)
		{
			$chbxPersonCountries = [];
			forEach ($countriesRows as $r)
				$chbxPersonCountries[$r['country']] = ['title' => $countriesCfg[$r['country']]['name'], 'id' => $r['country']];

			$paramsPersonCountries->addParam ('checkboxes', 'query.personCountries', ['items' => $chbxPersonCountries]);
			$qry[] = array ('id' => 'personCountries', 'style' => 'params', 'title' => 'Zem??pis', 'params' => $paramsPersonCountries);
		}
		$paramsPersonCountries->addParam ('string', 'query.geo.city', ['title' => 'M??sto']);
		$paramsPersonCountries->addParam ('string', 'query.geo.street', ['title' => 'Ulice']);
		$paramsPersonCountries->addParam ('string', 'query.geo.zipcode', ['title' => 'PS??']);

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = array ('style' => 'params', 'title' => $cg['name'], 'params' => $params);
		}

		// -- others
		$chbxOthers = [
				'withError' => ['title' => 'S chybou', 'id' => 'withError'],
				'unused' => ['title' => 'Nepou??it??', 'id' => 'unused']
		];
		$paramsOthers = new \E10\Params ($this->app());
		$paramsOthers->addParam ('checkboxes', 'query.others', ['items' => $chbxOthers]);
		$qry[] = ['id' => 'errors', 'style' => 'params', 'title' => 'Ostatn??', 'params' => $paramsOthers];



		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
} // class ViewPersonsBase


/**
 * Class ViewPersons
 * @package E10\Persons
 */
class ViewPersons extends ViewPersonsBase
{
	public function init ()
	{
		parent::init();

		$mq [] = array ('id' => 'active', 'title' => 'Aktivn??');
		$mq [] = array ('id' => 'archive', 'title' => 'Arch??v');
		$mq [] = array ('id' => 'all', 'title' => 'V??e');
		$mq [] = array ('id' => 'trash', 'title' => 'Ko??');
		$this->setMainQueries ($mq);

		$this->setPanels (TableView::sptQuery);
	}}

/**
 * Z??kladn?? detail Osoby
 *
 */

class ViewDetailPersons extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10.persons.DocumentCardPerson');
	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		if ($this->app()->hasRole('root'))
		{
			$merge = $this->app()->cfgItem ('registeredClasses.mergeRecords.e10-persons-persons', FALSE);

			if ($merge !== FALSE)
			{
				$toolbar [] = [
					'type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10.persons.persons',
					'text' => 'Slou??it osoby', 'data-class' => 'lib.persons.MergePersonsWizard', 'icon' => 'icon-code-fork'
				];
			}
		}
		return $toolbar;
	}

	public function loadContacts ()
	{
		$properties = $this->table->loadProperties ($this->item['ndx']);
		if (isset ($properties[$this->item['ndx']]['contacts']))
			return $properties[$this->item['ndx']]['contacts'];
	}
}

/*
 * FormPersons
 *
 */

class FormPersons extends TableForm
{
	function checkLoadedList ($list)
	{
		if (($list->listId == 'groups') && (count($list->data) == 0))
		{
			if (isset ($this->recData ['maingroup']))
				$list->data [] = $this->recData ['maingroup'];

			if (isset ($this->recData ['maingroup']))
				unset ($this->recData['maingroup']);
		}
	}

	public function docLinkEnabled ($docLink)
	{
		if (isset ($docLink['systemGroup']))
		{
			if (isset($this->lists ['groups']))
				$usrgrps = explode ('.', $this->lists ['groups']);
			else
				$usrgrps = array_keys($this->app()->db()->query('SELECT [group] FROM [e10_persons_personsgroups] WHERE [person] = %i', $this->recData['ndx'])->fetchAssoc('group'));
			$userGroupNdx = -1;

			$groupsMap = $this->table->app()->cfgItem ('e10.persons.groupsToSG', FALSE);
			if ($groupsMap && isset ($groupsMap [$docLink['systemGroup']]))
				$userGroupNdx = $groupsMap [$docLink['systemGroup']];

			if (in_array ($userGroupNdx, $usrgrps))
				return TRUE;

			return FALSE;
		}

		return TRUE;
	}

	public function loadGroups ()
	{
		$q = "SELECT * FROM [e10_persons_personsgroups] WHERE person = %i";
		$groups = $this->table->db()->fetchAll ($q, $this->recData ['ndx']);
		forEach ($groups as $g)
			$this->groups [] = $g ['group'];
	}

	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		if (!isset ($this->recData['company']))
			$this->recData['company'] = 0;

		$this->openForm ();


		$this->layoutOpen (TableForm::ltGrid);
			$this->openRow ('grid-form-tabs');
				$this->addColumnInput ("company", TableForm::coColW2);
				if ($this->recData['company'] === 0)
					$this->addColumnInput ("complicatedName", TableForm::coColW6);
			$this->closeRow ();

			$this->openRow ('grid-form-tabs');
				if ($this->recData['company'] == 0)
				{
					if ($this->recData['complicatedName'] == 0)
					{
						$this->addColumnInput ("firstName", TableForm::coColW6);
						$this->addColumnInput ("lastName", TableForm::coColW6);
					}
					else
					{
						$this->addColumnInput ("beforeName", TableForm::coColW1|TableForm::coPlaceholder);
						$this->addColumnInput ("firstName", TableForm::coColW4|TableForm::coPlaceholder);
						$this->addColumnInput ("middleName", TableForm::coColW2|TableForm::coPlaceholder);
						$this->addColumnInput ("lastName", TableForm::coColW4|TableForm::coPlaceholder);
						$this->addColumnInput ("afterName", TableForm::coColW1|TableForm::coPlaceholder);
					}
				}
				else
					$this->addColumnInput ("fullName", TableForm::coColW12);
			$this->closeRow ();
		$this->layoutClose ();

		$tabs ['tabs'][] = ['text' => 'Kontakty', 'icon' => 'formContacts'];
		$tabs ['tabs'][] = ['text' => 'Zat????d??n??', 'icon' => 'system/formSorting'];
		$tabs ['tabs'][] = ['text' => 'P????lohy', 'icon' => 'system/formAttachments'];
		$tabs ['tabs'][] = ['text' => 'Nastaven??', 'icon' => 'system/formSettings'];
		$this->openTabs ($tabs);
			$this->openTab ();
					$this->addList ('properties', '', TableForm::loAddToFormLayout);
					$this->addList ('address', '', TableForm::loAddToFormLayout);
			$this->closeTab ();

			$this->openTab ();
				$this->addList ('groups', 'Skupiny');

				$this->addList ('clsf', '', TableForm::loAddToFormLayout);

				$this->addSeparator(TableForm::coH1);
				$this->addList ('doclinks', '', TableForm::loAddToFormLayout);

				$this->layoutOpen(TableForm::ltVertical);
					$this->addList ('connections', 'Vazby k jin??m osob??m');
				$this->layoutClose();
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ('language');
				if ($this->recData['company'] == 0)
				{
					$this->addColumnInput ("gender");
					if ($this->table->app()->hasRole ('admin'))
					{
						$this->addColumnInput ("roles");
						$this->addColumnInput ("login");
						$this->addColumnInput ("accountType");
					}
					elseif ($this->table->app()->hasRole ('admusr'))
					{
						$this->addColumnInput ('roles');
					}
				}
				$this->addColumnInput ('id');
			$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}
} // class FormPersons


/**
 * Class FormPersonsRobot
 * @package E10\Persons
 */
class FormPersonsRobot extends TableForm
{
	public function docLinkEnabled ($docLink)
	{
		return FALSE;
	}

	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$this->layoutOpen (TableForm::ltGrid);
			$this->openRow ('grid-form-tabs');
				$this->addColumnInput ("fullName", TableForm::coColW12);
			$this->closeRow ();
		$this->layoutClose ();

		$tabs ['tabs'][] = ['text' => 'Kontakty', 'icon' => 'formContacts'];
		$tabs ['tabs'][] = ['text' => 'Zat????d??n??', 'icon' => 'system/formSorting'];
		$tabs ['tabs'][] = ['text' => 'P????lohy', 'icon' => 'system/formAttachments'];
		$tabs ['tabs'][] = ['text' => 'Nastaven??', 'icon' => 'system/formSettings'];
		$this->openTabs ($tabs);
			$this->openTab ();
				$this->addList ('properties', '', TableForm::loAddToFormLayout);
				$this->addList ('address', '', TableForm::loAddToFormLayout);
			$this->closeTab ();

			$this->openTab ();
				$this->addList ('groups', 'Skupiny');
				$this->addList ('clsf', '', TableForm::loAddToFormLayout);

				$this->addSeparator(TableForm::coH1);
				$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->openTab ();
				if ($this->table->app()->hasRole ('admin'))
				{
					$this->addColumnInput ("roles");
					$this->addColumnInput ("login");
				}
			$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}
}

/**
 * Class ViewDetailPersonsRights
 * @package E10\Persons
 */
class ViewDetailPersonsRights extends TableViewDetail
{
	public function createDetailContent ()
	{
		$rm = new \lib\RightsManager($this->app());
		$rm->setPerson($this->item);

		$x = $rm->createDetailReview ();
		$this->addContent(['type' => 'tiles', 'pane' => 'test', 'tiles' => $x, 'class' => 'panes']);

		$this->apiKeys();
	}

	public function apiKeys ()
	{
		if (!$this->app()->hasRole('admin'))
			return;

		$q[] = 'SELECT * FROM e10_persons_userspasswords WHERE [pwType] = 1';
		array_push($q, 'AND [person] = %i', $this->item['ndx']);

		$keys = [];
		$rows = $this->table->db()->query($q);
		foreach ($rows as $r)
		{
			$k = ['key' => ['text' => $r['emailHash'], 'docAction' => 'edit', 'table' => 'e10.persons.userspasswords', 'pk' => $r['ndx']]];
			$keys[] = $k;
		}

		$title = [];
		$title[] = ['icon' => 'icon-plug', 'text' => 'P??ihla??ovac?? kl????e k API'];

		$title[] = [
				'text'=> 'Nov??', 'docAction' => 'new', 'table' => 'e10.persons.userspasswords', 'type' => 'button',
				'actionClass' => 'btn btn-success btn-xs', 'icon' => 'system/actionAdd', 'class' => 'pull-right',
				'addParams' => "__pwType=1&__person={$this->item['ndx']}"
		];

		$h = ['#' => '#', 'key' => 'Kl????'];
		$this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
												'title' => $title, 'header' => $h, 'table' => $keys]);
	}
}
