<?php

namespace e10\persons;

use e10\utils, e10\json;
use e10pro\gdpr\TablePersonsRelations;
use \e10\base\libs\UtilsBase;


/**
 * Class DocumentCardPerson
 */
class DocumentCardPerson extends \e10\DocumentCard
{
	var $info = [];

	/** @var \e10\persons\TableAddress */
	var $tableAddress;

	/** @var \e10pro\gdpr\TablePersonsRelations */
	var $tableRelations = NULL;
	var $relationsCategories;

	/** @var \e10\persons\libs\Vcard */
	var $vcard;

	var $properties = NULL;
	var $contacts = '';
	var $validity;
	var $addressesAll;
	var $addresses;
	var $groups = [];
	var $ids = [];
	var $privacy = NULL;

	public function createContent ()
	{
		$this->loadData();
		$this->createContentBody();
		$this->createHeader();
	}

	public function createHeader ()
	{
		$this->header = [];
		$this->header['icon'] = $this->table->tableIcon($this->recData);
		$this->header['info'][] = ['class' => 'title', 'value' => [['text' => $this->recData ['fullName']], ['text' => '#'.$this->recData ['id'], 'class' => 'pull-right id']]];
		if (count($this->ids))
			$this->header['info'][] = ['class' => 'info', 'value' => $this->ids];
		if (count($this->groups))
			$this->header['info'][] = ['class' => 'info', 'value' => $this->groups];

		$image = UtilsBase::getAttachmentDefaultImage ($this->app(), $this->table->tableId(), $this->recData ['ndx']);
		if (isset($image ['smallImage']))
			$this->header['image'] = $image ['smallImage'];
	}

	function loadData()
	{
		$this->tableAddress = $this->app()->table('e10.persons.address');
		$this->tableRelations = $this->app()->table('e10.persons.relations');
		$this->relationsCategories = $this->app()->cfgItem ('e10.persons.categories.categories', NULL);

		$this->loadDataProperties();
		$this->loadDataValidity();
		$this->loadDataAddresses();
		$this->loadDataRelations();
		$this->loadDataVcard();
	}

	function loadDataAddresses()
	{
		$this->addresses = [];
		$this->addressesAll = $this->tableAddress->loadAddresses($this->table, $this->recData['ndx'], FALSE);

		foreach ($this->addressesAll as $a)
		{
			if (!count($a))
				continue;
			$address = ['text' => $a['text'], 'class' => 'block', 'icon' => $a['icon'], 'typeTitle' => $a['typeTitle']];

			$this->addresses[] = $address;
		}
	}

	public function loadDataPersonInfo ()
	{
		$listsClasses = $this->app->cfgItem ('registeredClasses.personInfo', []);
		foreach ($listsClasses as $class)
		{
			if (isset ($class['role']) && !$this->app->hasRole($class['role']))
				continue;
			$classId = $class['classId'];
			$object = $this->app->createObject($classId);
			if (!$object)
				continue;

			$object->tileMode = 1;

			$object->createInfo ($this->recData['ndx'], $this);
		}

		$this->createContentInfo ();
	}

	function loadDataProperties ()
	{
		$this->contacts = '';

		$this->properties = $this->table->loadProperties ($this->recData['ndx']);
		if (isset ($this->properties[$this->recData['ndx']]['contacts']))
		{
			foreach ($this->properties[$this->recData['ndx']]['contacts'] as &$p)
			{
				$p['class'] = 'e10-tag e10-bg-t1';
			}

			$this->contacts = $this->properties[$this->recData['ndx']]['contacts'];
		}

		if (isset ($this->properties[$this->recData['ndx']]['groups']))
		{
			$this->groups = $this->properties[$this->recData['ndx']]['groups'];
		}

		if (isset ($this->properties[$this->recData['ndx']]['ids']))
		{
			$this->ids = $this->properties[$this->recData['ndx']]['ids'];
		}
	}

	protected function loadDataVcard()
	{
		$this->vcard = new \e10\persons\libs\Vcard($this->app());
		$this->vcard->setPerson($this->recData['ndx']);
		$this->vcard->run();
	}

	function loadDataRelations ()
	{
		if (!$this->relationsCategories)
			return;

		$this->privacy = ['icon' => 'tables/e10.persons.relations', 'relations' => []];

		$q [] = 'SELECT relations.*,';
		array_push ($q, ' parentPersons.fullName AS parentPersonFullName, parentPersons.id AS parentPersonId');
		array_push ($q, ' FROM [e10_persons_relations] AS relations');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS parentPersons ON relations.parentPerson = parentPersons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND person = %i', $this->recData['ndx']);
		array_push ($q, ' AND relations.docStateMain != %i', 4);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$c = $this->relationsCategories[$r['category']];

			$item = [
				'text' => $c['fn'], 'class' => 'label label-default',
				'docAction' => 'edit', 'table' => 'e10.persons.relations', 'pk' => $r['ndx']
				];
			if ($r['parentPersonFullName'])
				$item['suffix'] = $r['parentPersonFullName'];

			if ($r['source'] === 1)
			{
				$todayYear = intval(utils::today('Y'));

				$prefix = '';
				$yearFrom = 0;
				$yearTo = 0;
				if ($r['validFrom'])
					$yearFrom = intval($r['validFrom']->format('Y'));
				if ($r['validTo'])
					$yearTo = intval($r['validTo']->format('Y'));

				if ($yearFrom)
					$prefix .= $yearFrom;
				if ($yearTo && $yearTo === $todayYear)
					$prefix .= ' ➝ ';
				elseif ($yearTo && $yearTo !== $todayYear && $yearTo !== $yearFrom)
					$prefix .= ' ➝ '.$yearTo;

				if ($prefix !== '')
					$item['prefix'] = $prefix;
			}
			else
			{
				$prefix = '';
				if ($r['validFrom'])
					$prefix .= utils::datef($r['validFrom'], '%D');
				if ($r['validFrom'] || $r['validTo'])
					$prefix .= ' ➝ ';
				if ($r['validTo'])
					$prefix .= utils::datef($r['validTo'], '%D');

				if ($prefix !== '')
					$item['prefix'] = $prefix;
			}

			$this->privacy['relations'][] = $item;
		}

		$addRelationButton = [
			'icon' => 'system/actionAdd', 'action' => '', 'XXXdropUp' => '1', 'dropRight' => 1,
			'text' => '', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-default',
			'class' => 'pull-right-absolute',
			'dropdownMenu' => []
		];
		foreach ($this->relationsCategories as $catNdx => $catDef)
		{
			if ($catDef['useOnHuman'] && $this->recData['personType'] != 1 && !$catDef['useOnCompany'])
				continue;


			$addParams = '__person='.$this->recData['ndx'].'&__category='.$catNdx;
			if ($catDef['needParentPerson'])
				$addParams .= '&__parentPerson='.intval($this->app()->cfgItem ('options.core.ownerPerson', 0));

			$addRelationButton['dropdownMenu'][] = [
				'action' => 'new', 'data-table' => 'e10.persons.relations', 'icon' => 'system/actionAdd',
				'text' => $catDef['fn'], 'data-addParams' => $addParams,
			];
		}
		$this->privacy['relations'][] = $addRelationButton;
	}

	public function loadDataValidity ()
	{
		$this->validity = ['class' => '', 'icon' => 'icon-question-circle'];

		$validity = $this->db()->query('SELECT * FROM [e10_persons_personsValidity] WHERE [person] = %i', $this->recData['ndx'])->fetch();

		if (!$validity)
		{
			$line = ['text' => 'Kontrola zatím nebyla provedena'];
			$this->validity['class'] = 'e10-row-this';
			//$this->addContent('body', ['pane' => 'e10-pane e10-pane-table e10-row-this', 'type' => 'line', 'line' => $line]);
		}
		elseif ($validity['valid'] === 1)
		{
			$line = [['text' => 'V pořádku', 'XXicon' => 'system/iconCheck', 'suffix' => utils::datef ($validity['updated'], '%D, %T')]];
			if ($validity['revalidate'])
				$line [] = ['text' => 'údaje byly opraveny, je naplánována nová kontrola', 'icon' => 'system/docStateEdit', 'class' => 'e10-small block'];
			$this->validity['class'] = 'e10-row-plus';
			$this->validity['icon'] = 'system/iconCheck';
			//$this->addContent('body', ['pane' => 'e10-pane e10-pane-table e10-row-plus', 'type' => 'line', 'line' => $line]);
		}
		else
		{
			$this->validity['icon'] = 'system/iconWarning';
			$title = ['text' => 'Při kontrole byly nalezeny chyby', 'XXXicon' => 'system/iconWarning', 'class' => 'e10-error h2'];
			$line = [$title];

			if ($validity['revalidate'])
				$line [] = ['text' => 'údaje byly opraveny, je naplánována nová kontrola', 'icon' => 'system/iconCheck', 'class' => 'e10-small block'];

			$msg = json::decode($validity['msg']);
			foreach ($msg as $partId => $part)
			{
				foreach ($part as $valueId => $error)
				{
					$info = ['text' => $valueId.': '.$error['msg'], 'class' => 'block', 'icon' => 'system/iconAngleRight'];
					if (isset($error['registerName']))
						$info['suffix'] = $error['registerName'];
					$line[] = $info;
				}
			}

			$this->validity['class'] = 'e10-warning1';
		}

		$ve = new \e10\persons\PersonValidator($this->app());
		$tools = $ve->onlineTools($this->recData);
		if ($tools)
		{
			$line[] = ['text' => '', 'class' => 'break padd5'];
			foreach ($tools as $t)
			{
				$t['class'] = 'btn btn-default btn-sm';
				$t['icon'] = 'system/iconLink';
				$line[] = $t;
			}
		}

		$this->validity['content'] = $line;
	}

	public function createContentBody ()
	{
		$contentContacts = $this->contentContacts();
		$this->addContent('body', $contentContacts);

		$this->addDiaryPinnedContent();
		$this->loadDataPersonInfo ();
	}

	public function contentContacts ()
	{
		$t = [];

		// -- ids
		if (0 && count($this->ids))
		{
			$t [] = [
				'c1' => '',
				'c2' => $this->ids,
			];
		}

		// -- contacts
		if ($this->contacts !== '')
		{
			$ccc = $this->contacts;

			$qrBtn = "<span class='pull-right' data-toggle='popover' data-trigger='hover' data-html='true' data-placement='left'";
			$qrBtn .= " data-content=\"<img style='max-width: 100%;' src='{$this->vcard->info['vcardQRCodeURL']}'>\"";
			$qrBtn .= " onmouseover='$(this).popover(\"show\")'";
			$qrBtn .= '>';
			$qrBtn .= $this->app()->ui()->icon('user/addressBook');
			$qrBtn .= '</span>';

			$ccc[] = ['code' => $qrBtn];
			$t [] = [
				'c1' => ['icon' => 'system/iconIdBadge', 'text' => ''],
				'c2' => $ccc,
				'_options' => ['cellTitles' => ['c1' => 'Kontaktní údaje']]
			];
		}

		// -- address
		if (count($this->addresses))
		{
			/*
			$t [] = [
				'c1' => ['icon' => 'system/iconHome', 'text' => ''],
				'c2' => $this->addresses,
				'_options' => ['cellTitles' => ['c1' => 'Poštovní adresa']]
			];
			*/
			foreach ($this->addresses as $a)
			{
				$t [] = [
					'c1' => ['icon' => $a['icon'], 'text' => ''],
					'c2' => [['text' => $a['text'], 'class' => ''], ['text' => $a['typeTitle'], 'class' => 'e10-small pull-right']],
				];
			}
		}

		if ($this->privacy)
		{
			$t [] = [
				'c1' => ['icon' => $this->privacy['icon'], 'text' => ''],
				'c2' => $this->privacy['relations'],
				'_options' => ['cellTitles' => ['c1' => 'Vztahy']]
			];
		}

		// -- validity
		$t [] = [
			'c1' => ['icon' => $this->validity['icon'], 'text' => ''],
			'c2' => $this->validity['content'],
			'_options' => ['class' => $this->validity['class']]
		];


		$h = ['c1' => 'c1', 'c2' => 'c2'];
		return [
			'pane' => 'e10-pane e10-pane-top', 'type' => 'table', 'table' => $t, 'header' => $h,
			'params' => ['forceTableClass' => 'dcInfo fullWidth', 'hideHeader' => 1]
		];
	}

	public function createContentHeader ()
	{
		$recData = $this->recData;
		$hdr ['icon'] = $this->table->icon ($recData);
		$hdr ['class'] = 'e10-pane-header '.$this->docStateClass();

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['fullName']], ['text' => '#'.$recData ['id'], 'class' => 'pull-right id']]];

		$ndx = $recData ['ndx'];
		$properties = $this->table->loadProperties ($ndx);
		$classification = \E10\Base\loadClassification ($this->app, $this->table->tableId(), $ndx);

		$contactInfo = array ();
		if (isset ($properties [$ndx]['ids']))
			$contactInfo = $properties [$ndx]['ids'];

		if (count($contactInfo) !== 0)
			$hdr ['info'][] = array ('class' => 'info', 'value' => $contactInfo);


		$secLine = array();
		if (isset ($properties [$ndx]['groups']))
		{
			$secLine = $properties [$ndx]['groups'];
			$secLine[0]['icon'] = 'e10-persons-groups';
		}
		if (isset ($classification [$ndx]['places']))
			$secLine = array_merge ($secLine, $classification [$ndx]['places']);
		if (count($secLine) !== 0)
			$hdr ['info'][] = array ('class' => 'info', 'value' => $secLine);

		$image = UtilsBase::getAttachmentDefaultImage ($this->app, $this->table->tableId(), $recData ['ndx']);
		if (isset($image ['smallImage']))
		{
			$hdr ['image'] = $image ['smallImage'];
			unset ($hdr ['icon']);
		}

		$this->addContent('header', ['type' => 'tiles', 'tiles' => [$hdr], 'class' => 'panes']);


		$title = ['icon' => $this->table->icon ($recData), 'text' => $recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);

		if (count($contactInfo) !== 0)
			$this->addContent('subTitle', ['type' => 'line', 'line' => $contactInfo]);

	}

	public function createContentPersonInfo ()
	{
		$listsClasses = $this->app->cfgItem ('registeredClasses.personInfo', []);
		foreach ($listsClasses as $class)
		{
			if (isset ($class['role']) && !$this->app->hasRole($class['role']))
				continue;
			$classId = $class['classId'];
			$object = $this->app->createObject($classId);
			$object->createInfo ($this->recData['ndx'], $this);
		}

		$this->createContentInfo ();
	}

	public function createContentConnections ()
	{
		$connectionTypes = $this->app()->cfgItem ('e10.persons.connectionTypes');
		$pks = [];
		$c = [];

		// -- connections TO
		$q = 'SELECT connections.*, persons.fullName as personFullName FROM [e10_persons_connections] as connections, [e10_persons_persons] as persons WHERE [person] = %i AND connections.connectedPerson = persons.ndx ORDER BY connectionType, ndx';
		$rows = $this->db()->query ($q, $this->recData['ndx']);

		forEach ($rows as $r)
		{
			$ndx = $r['connectedPerson'];
			$newConnection = ['fullName' => $r['personFullName'], 'ndx'=> $ndx, 'ct' => $connectionTypes[$r['connectionType']]['label']];
			if ($r['note'] !== '')
				$newConnection['ct'] .= ' ('.$r['note'].')';
			$c[$ndx] = $newConnection;
			$pks[] = $ndx;
		}

		// -- connections FROM
		$q = 'SELECT connections.*, persons.fullName as personFullName FROM [e10_persons_connections] as connections, [e10_persons_persons] as persons WHERE [connectedPerson] = %i AND connections.person = persons.ndx ORDER BY connectionType, ndx';
		$rows = $this->table->db()->query ($q, $this->recData['ndx']);
		forEach ($rows as $r)
		{
			$ndx = $r['person'];
			$newConnection = ['fullName' => $r['personFullName'], 'ndx'=> $ndx,'ct' => $connectionTypes[$r['connectionType']]['opposite']];
			if ($r['note'] !== '')
				$newConnection['ct'] .= ' ('.$r['note'].')';
			$c[$ndx] = $newConnection;
			$pks[] = $ndx;
		}

		// -- attach properties
		$properties = $this->table->loadProperties ($pks);
		foreach ($properties as $personNdx => $p)
		{
			if (isset ($p['contacts']))
				$c[$personNdx]['contacts'] = $p['contacts'];
		}


		$this->addPart('conn', ['title' => 'Vazby', 'icon' => 'icon-arrows-alt', 'orderId' => 100]);
		foreach ($c as $oneConnection)
		{
			$orderId = '1-'.$oneConnection['ndx'];
			$newDoc = [
					'orderId' => $orderId,
					'docStateClass' => 'test', 'table' => 'e10.persons.persons', 'ndx' => $oneConnection['ndx'],
					'info' => [],
			];

			$newDoc['info'][] = [
					['text' => $oneConnection['fullName'], 'prefix' => $oneConnection['ct'], 'class' => 'block title'],

			];
			if (isset($oneConnection['contacts']))
				$newDoc['info'][] = $oneConnection['contacts'];

			$this->addItem('conn', $newDoc);
		}
	}
}
