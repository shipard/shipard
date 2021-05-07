<?php

namespace e10doc\base;
use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableDocKinds
 * @package e10doc\base
 */
class TableDocKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.dockinds', 'e10doc_base_dockinds', 'Druhy dokladů');
	}

	public function saveConfig ()
	{
		$docKinds = array ();

		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_dockinds] WHERE docState != 9800 ORDER BY [ndx]');

		foreach ($rows as $r) 
		{
			$docKinds [strval($r['ndx'])] = [
				'ndx' => $r ['ndx'], 'docType' => $r['docType'], 'activity' => $r['activity'],
				'debsAccountId' => isset ($r['debsAccountId']) ? $r['debsAccountId'] : '',
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName']
			];
		}
		$docKinds ['0'] = ['ndx' => 0, 'docType' => '', 'fullName' => '', 'shortName' => '', 'debsAccountId' => ''];

		// -- save to file
		$cfg ['e10']['docs']['kinds'] = $docKinds;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.docKinds.json', utils::json_lint (json_encode ($cfg)));


		// -- properties
		unset ($cfg);
		$tablePropDefs = new \E10\Base\TablePropdefs($this->app());
		$cfg ['e10doc']['heads']['properties'] = $tablePropDefs->propertiesConfig($this->tableId());
		file_put_contents(__APP_DIR__ . '/config/_e10doc.heads.properties.json', utils::json_lint(json_encode ($cfg)));
	}

	public function columnInfoEnumSrc ($columnId, $form)
	{
		if ($columnId === 'activity')
		{
			if (!$form || !isset($form->recData['docType']))
				return NULL;

			$docType = $this->app()->cfgItem ('e10.docs.types.'.$form->recData['docType'], FALSE);
			if ($docType && isset ($docType['activities']))
				return array_merge (['' => ['name'=>'']], $docType['activities']);

			return NULL;
		}

		return parent::columnInfoEnumSrc ($columnId, $form);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewDocKinds
 * @package e10doc\base
 */
class ViewDocKinds extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$bt = [];
		$bt[] = ['id' => 'ALL', 'title' => 'Vše', 'active' => 1];

		$docTypes = $this->app->cfgItem ('e10.docs.types');

		forEach ($docTypes as $dtid => $dt)
		{
			if (!isset ($dt['docNumbers']))
				continue;
			$addParams = ['docType' => $dtid];
			$nbt = ['id' => $dtid, 'title' => $dt['shortcut'], 'active' => 0, 'addParams' => $addParams];
			$bt [] = $nbt;
		}
		$this->setBottomTabs ($bt);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = $this->bottomTabId ();

		$q [] = 'SELECT * FROM [e10doc_base_dockinds]';
		array_push ($q, ' WHERE 1');

		if ($bottomTabId !== 'ALL')
			array_push ($q, ' AND [docType] = %s', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%',
				' OR [debsAccountId] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$docType = $this->app()->cfgItem ('e10.docs.types.'.$item['docType'], FALSE);
		if ($docType)
		{
			$listItem ['t2'] = $docType['fullName'];
			if ($item['activity'] != '')
				$listItem ['t2'] .= ' / ' . $docType['activities'][$item['activity']]['name'];
		}
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * Class FormDocKind
 * @package e10doc\base
 */
class FormDocKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('docType');
			$this->addColumnInput ('activity');
			$this->addColumnInput ('debsAccountId');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}

