<?php

namespace e10\witems\dc;
use E10\utils;


/**
 * Class Item
 * @package e10\witems\dc
 */
class Item extends \e10\DocumentCard
{
	var $dataSuppliers = [];
	var $dataCodes = [];
	var $relatedItems = [];

	public function createContentBody ()
	{
		$this->createContentBody_Codes();
		$this->createContentBody_Suppliers();
		$this->createContentBody_Related();
		$this->addContent ('body', \E10\Base\getPropertiesDetail ($this->table, $this->recData));
		$this->createContentBody_Set ();
		$this->createContentBody_Annotations();
		$this->addContentAttachments ($this->recData ['ndx']);
	}

	public function createContentBody_Related ()
	{
		$q[] = 'SELECT [ir].*,';
		array_push($q, ' relatedKinds.ndx AS relKindNdx, relatedKinds.fullName AS relKindName, relatedKinds.icon AS relKindIcon,');
		array_push($q, ' relItems.ndx AS relItemNdx, relItems.fullName AS relItemName, relItems.id AS relItemId, relItems.[type] AS relItemType');
		array_push($q, ' FROM [e10_witems_itemRelated] AS [ir]');
		array_push($q, ' LEFT JOIN [e10_witems_relatedKinds] AS relatedKinds ON [ir].kind = relatedKinds.ndx');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS relItems ON ir.relatedItem = relItems.ndx');
		array_push($q, ' WHERE ir.[srcItem] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY relatedKinds.[order], relItems.fullName');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$itemType = $this->app()->cfgItem ('e10.witems.types.'.$r['relItemType'], FALSE);

			$item = [
				'itemId' => ['text' => $r['relItemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $r['relItemNdx']],
				'itemType' => $itemType['shortName'],
				'itemTitle' => ['text' => $r['relItemName']],
			];

			if (!isset($this->relatedItems[$r['relKindNdx']]))
			{
				$this->relatedItems[$r['relKindNdx']] = ['title' => $r['relKindName'], 'items' => []];
			}

			$this->relatedItems[$r['relKindNdx']]['items'][] = $item;
		}

		if (!count($this->relatedItems))
			return;


		$t = [];
		foreach ($this->relatedItems as $riKind)
		{
			$item = ['itemId' => $riKind['title'], '_options' => ['colSpan' => ['itemId' => 3], 'class' => 'subheader']];
			$t[] = $item;
			foreach ($riKind['items'] as $oneItem)
			{
				$t[] = $oneItem;
			}
		}

		$h = ['itemId' => 'Položka', 'itemType' => 'Typ', 'itemTitle' => 'Název',];
		$title = [['text' => 'Související položky', 'class' => 'h1']];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
			'type' => 'table', 'table' => $t, 'header' => $h,
			'params' => ['hideHeader' => 1]
		]);
	}

	public function createContentBody_Codes ()
	{
		/** @var \e10\witems\TableItemCodes */
		$tableItemCodes = $this->app()->table('e10.witems.itemCodes');
		$personTypes = $tableItemCodes->columnInfoEnum ('personType', 'cfgText');

		$lastCodeKind = -1;
		$rowIndex = 0;

		$q[] = 'SELECT itemCodes.*,';
		array_push($q, ' persons.fullName AS personName, personsGroups.name AS groupName,');
		array_push($q, ' nomenc.fullName AS nomencName');
		array_push($q, ' FROM [e10_witems_itemCodes] AS itemCodes');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON itemCodes.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_groups] AS personsGroups ON itemCodes.personsGroup = personsGroups.ndx');
		array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS nomenc ON itemCodes.itemCodeNomenc = nomenc.ndx');
		array_push($q, ' WHERE itemCodes.[item] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY itemCodes.codeKind, itemCodes.systemOrder, itemCodes.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$codeKind = $this->app()->cfgItem('e10.witems.codesKinds.'.$r['codeKind']);
			$codeDir = $this->app()->cfgItem('e10.witems.codeDirs.'.$r['codeDir']);
			$refType = $codeKind['refType'] ?? 0;
			$askDir = $codeKind['askDir'] ?? 0;
			$askPerson = $codeKind['askPerson'] ?? 0;
			$askPersonType = $codeKind['askPersonType'] ?? 0;

			$item = [
				'code' => [
					['text' => $r['itemCodeText'], 'class' => 'e10-bold block'],
					['text' => $codeKind['sn'] ?? '-- chybný kód položky --', 'class' => 'e10-small'],
				],
				'info' => [],
			];
			if ($r['person'])
				$item['application'][] = ['text' => $r['personName'], 'class' => 'block', 'icon' => 'system/personCompany'];
			if ($r['personsGroup'])
				$item['application'][] = ['text' => $r['groupName'], 'class' => 'block', 'icon' => 'tables/e10.persons.groups'];
			if ($askPersonType)
				$item['application'][] = ['text' => $personTypes[$r['personType']] ?? '!!!', 'class' => 'block', 'icon' => 'tables/e10.persons.persons'];
			if ($r['addressLabel'])
			{
				$label = $this->app()->cfgItem('e10.base.clsf.addressTags.'.$r['addressLabel'], NULL);
				if ($label)
					$item['application'][] = ['text' => $label['name'], 'class' => 'label', 'icon' => 'tables/e10.base.clsfgroups', 'css' => $label['css']];
			}

			if (!Utils::dateIsBlank($r['validFrom']) || !Utils::dateIsBlank($r['validTo']))
			{
				$item['application'][] = ['text' => Utils::dateFromTo($r['validFrom'], $r['validTo'], NULL), 'class' => 'label label-info', 'icon' => 'system/iconCalendar'];
			}

			if($askDir)
				$item['info'][] = ['text' => $codeDir['sn'] ?? '!!!', 'class' => 'block'];
			if($refType === 1)
				$item['info'][] = ['text' => $r['nomencName'] ?? '!!!', 'class' => 'block e10-small'];

			if ($lastCodeKind != $r['codeKind'] && $rowIndex)
			{
				$item['_options'] = ['beforeSeparator' => 'separator'];
			}

			$lastCodeKind = $r['codeKind'];
			$rowIndex++;
			$this->dataCodes[] = $item;
		}

		if (count($this->dataCodes))
		{
			$h = ['code' => 'Kód', 'info' => 'Informace', 'application' => 'Uplatňuje se'];

			$title = [['text' => 'Kódy položek', 'class' => 'h1']];
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
				'type' => 'table', 'table' => $this->dataCodes, 'header' => $h
			]);
		}
	}

	public function createContentBody_Suppliers ()
	{
		$q[] = 'SELECT spl.*,';
		array_push($q, ' persons.fullName AS supplierName');
		array_push($q, ' FROM [e10_witems_itemSuppliers] AS spl');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON spl.supplier = persons.ndx');
		array_push($q, ' WHERE spl.[item] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY spl.rowOrder, spl.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'itemId' => $r['url'] != '' ? ['text' => $r['itemId'], 'url' => $r['url'], 'icon' => 'system/iconLink'] : $r['itemId'],
				'supplier' => $r['supplierName'],
			];

			$this->dataSuppliers[] = $item;
		}

		if (count($this->dataSuppliers))
		{
			$h = ['supplier' => 'Dodavatel', 'itemId' => 'Kód dod.',];
			$title = [['text' => 'Dodavatelé', 'class' => 'h1']];
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
				'type' => 'table', 'table' => $this->dataSuppliers, 'header' => $h
			]);
		}
	}

	public function createContentBody_Set ()
	{
		if (!$this->recData['isSet'])
			return;

		$errors = [];

		$today = utils::today();

		$q = [];
		array_push ($q, 'SELECT [setRows].*,');
		array_push ($q, ' [dstItems].fullName AS dstFullName, [dstItems].[id] AS dstId,');
		array_push ($q, ' [dstItems].validFrom AS dstItemValidFrom, [dstItems].validTo AS dstItemValidTo,');
		array_push ($q, ' [dstTypes].fullName AS dstTypeName, [dstTypes].[type] AS dstTypeType');
		array_push ($q, ' FROM [e10_witems_itemsets] AS [setRows]');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS [dstItems] ON [setRows].[item] = [dstItems].[ndx]');
		array_push ($q, ' LEFT JOIN [e10_witems_itemtypes] AS [dstTypes] ON dstItems.itemType = [dstTypes].ndx');
		array_push ($q, ' WHERE [setRows].[itemOwner] = %i', $this->recData['ndx']);

		$t = [];
		$h = ['id' => 'Pol.', 'title' => 'Název', 'type' => 'Typ', 'quantity' => ' Množ.'];

		$cntInvalid = 0;
		$cntValid = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$rowIsValid = 1;

			$itm = [
				'id' => ['text' => $r['dstId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $r['item']],
				'title' => [['text' => $r['dstFullName'], 'class' => 'block']],
				'type' => [['text' => $r['dstTypeName'], 'class' => 'block']],
				'quantity' => $r['quantity'],
			];

			if ($r['setItemType'] === 0 && $r['dstTypeType'] != 1)
			{
				$itm['type'][] = ['text' => 'Položka není Zásoba', 'icon' => 'system/iconWarning', 'class' => 'e10-error label label-default'];
				$rowIsValid = 0;
			}

			if (!utils::dateIsBlank($r['validTo']) /*&& $r['validTo'] < $today*/)
			{
				$itm['title'][] = ['text' => 'Platné do '.utils::datef($r['validTo']), 'class' => 'label label-default'];

			}

			if (!utils::dateIsBlank($r['dstItemValidTo']) && $r['dstItemValidTo'] < $today && $rowIsValid)
			{
				if (utils::dateIsBlank($r['validTo']) || (!utils::dateIsBlank($r['validTo']) && $r['validTo'] > $r['dstItemValidTo']))
				{
					$itm['title'][] = ['text' => 'Položka je neplatná k ' . utils::datef($r['dstItemValidTo']), 'class' => 'e10-error block'];
					$rowIsValid = 0;
				}
			}

			if (!utils::dateIsBlank($r['validFrom']))
			{
				$itm['title'][] = ['text' => 'Platné od ' . utils::datef($r['validFrom']), 'class' => 'label label-default'];
			}

			if ($rowIsValid)
				$cntValid++;
			else
				$cntInvalid++;

			$t[] = $itm;
		}

		if (!$cntValid)
			$errors[] = ['text' => 'Sada neobsahuje žádnou platnou položku', 'class' => 'e10-error block'];
		elseif ($cntInvalid && !$cntValid)
			$errors[] = ['text' => 'Sada obsahuje vadné řádky', 'class' => 'e10-error block'];

		$title = [['text' => 'Sada', 'class' => 'h1']];
		if (count($errors))
			$title = array_merge($title, $errors);

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
			'type' => 'table', 'table' => $t, 'header' => $h
		]);
	}

	public function createContentBody_Annotations ()
	{
		$annots = new \e10pro\kb\libs\AnnotationsList($this->app());
		$annots->addRecord($this->table->ndx, $this->recData['ndx']);
		$annots->load();
		$code = $annots->code();

		if ($code === '')
			return;

		$title = [['text' => 'Odkazy', 'class' => 'h1']];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table pageText', 'paneTitle' => $title,
			'type' => 'line', 'line' => ['code' => $code],
		]);

	}

	public function createContentTitle ()
	{
		$title = ['icon' => $this->table->icon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);

		$itemsTypes = $this->app->cfgItem ('e10.witems.types');
		$subTitle = [];
		if (isset ($itemsTypes [$this->recData['type']]))
			$subTitle[] = ['text' => $itemsTypes [$this->recData['type']]['.text']];

		if ($this->recData['brand'])
		{
			$brand = $this->app()->loadItem($this->recData['brand'], 'e10.witems.brands');
			$subTitle[] = ['text' => $brand['fullName']];
		}

		$this->addContent('subTitle', ['type' => 'line', 'line' => $subTitle]);
	}

	public function createContent ()
	{
		$this->createContentBody ();
		$this->createContentTitle ();
	}
}
