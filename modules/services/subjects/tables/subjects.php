<?php

namespace services\subjects;

use \e10\utils, \e10\TableView, \e10\TableViewGrid, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\TableViewPanel, \e10\str;


/**
 * Class TableSubjects
 * @package services\subjects
 */
class TableSubjects extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjects.subjects', 'services_subjects_subjects', 'Subjekty');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewSubjects
 * @package services\subjects
 */
class ViewSubjects extends TableView
{
	var $nomencTypeTobe;
	var $nomencTypeNuts;
	var $region1;
	var $region2;
	var $kinds;
	var $sizes;

	public function init ()
	{
		parent::init();
		$this->disableIncrementalSearch = TRUE;

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'archive', 'title' => 'Archív'];
		$mq [] = ['id' => 'invalid', 'title' => 'Vadné'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		$this->nomencTypeTobe = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-tobe')->fetch();
		$this->nomencTypeNuts = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nuts')->fetch();

		$this->region1 = $this->app()->cfgItem('nomenc.cz-nuts-3');
		$this->region2 = $this->app()->cfgItem('nomenc.cz-nuts-4');
		$this->kinds = $this->app()->cfgItem('services.subjects.kinds');
		$this->sizes = $this->app()->cfgItem('services.subjects.sizes');

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item['region1'])
			$props[] = ['text' => $this->region1[$item['region1']]['sn'], 'class' => 'label label-info'];
		if ($item['region2'])
			$props[] = ['text' => $this->region2[$item['region2']]['sn'], 'class' => 'label label-default'];
		if ($item['kind'])
			$props[] = ['text' => $this->kinds[$item['kind']]['sn'], 'class' => 'label label-default', 'icon' => 'icon-folder-open-o'];
		if ($item['size'])
			$props[] = ['text' => $this->sizes[$item['size']]['sn'], 'class' => 'label label-default', 'icon' => 'icon-expand'];

		if (count($props))
			$listItem['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$qv = $this->queryValues ();
		if ($fts === '' && isset($qv['text']))
			$fts = $qv['text']['text'];


		$q [] = 'SELECT * FROM [services_subjects_subjects] AS [subjects]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (1 ');

			$words = preg_split('/[\s-]+/', $fts);
			$fullTextQuery = '';
			foreach ($words as $w)
			{
				if (str::strlen($w) < 4)
					continue;
				if ($fullTextQuery !== '')
					$fullTextQuery .= ' ';
				$fullTextQuery .= '+'.$w;
			}

			if ($fullTextQuery !== '')
				array_push ($q, ' AND MATCH([fullName]) AGAINST (%s IN BOOLEAN MODE)', $fullTextQuery);
			else
			{
				if (str::strlen($fts) > 2)
					array_push($q, ' AND [fullName] LIKE %s', $fts . '%');
			}
			array_push ($q, ')');
		}

		// -- search panel
		if (isset($qv['nomencTobe']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_nomenc WHERE subjects.ndx = recId AND tableId = %s', 'services.subjects.subjects');
			array_push ($q, ' AND [nomencType] = %i', $this->nomencTypeTobe['ndx']);
			array_push ($q, ' AND [nomencItem] IN %in', array_keys($qv['nomencTobe']));
			array_push ($q, ')');
		}

		if (isset($qv['kinds']))
		{
			array_push ($q, ' AND subjects.kind IN %in', array_keys($qv['kinds']));
		}

		if (isset($qv['sizes']))
		{
			array_push ($q, ' AND subjects.size IN %in', array_keys($qv['sizes']));
		}

		if (isset($qv['region1']))
		{
			array_push ($q, ' AND subjects.region1 IN %in', array_keys($qv['region1']));
		}

		if (isset($qv['region2']))
		{
			array_push ($q, ' AND subjects.region2 IN %in', array_keys($qv['region2']));
		}

		if (isset($qv['branches']))
			array_push ($q,
				' AND EXISTS (SELECT ndx FROM services_subjects_subjectsBranches WHERE subjects.ndx = subject ',
				' AND [branch] IN %in', array_keys($qv['branches']), ')'
			);

		if (isset($qv['activities']))
			array_push ($q,
					' AND EXISTS (SELECT ndx FROM services_subjects_subjectsBranches WHERE subjects.ndx = subject ',
					' AND [activity] IN %in', array_keys($qv['activities']), ')'
			);

		if (isset($qv['commodities']))
			array_push ($q,
					' AND EXISTS (SELECT ndx FROM services_subjects_subjectsBranches WHERE subjects.ndx = subject ',
					' AND [commodity] IN %in', array_keys($qv['commodities']), ')'
			);


		$this->queryMain ($q, '', ['[fullName]'], TRUE);
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- kinds
		$kinds = $this->app()->cfgItem ('services.subjects.kinds');
		$this->qryPanelAddCheckBoxes($panel, $qry, $kinds, 'kinds', 'Druhy', 'fn');

		// -- sizes
		$sizes = $this->app()->cfgItem ('services.subjects.sizes');
		$this->qryPanelAddCheckBoxes($panel, $qry, $sizes, 'sizes', 'Velikosti', 'fn');

		// -- branches
		$activities = $this->app()->cfgItem ('services.subjects.branches.branches');
		$this->qryPanelAddCheckBoxes($panel, $qry, $activities, 'branches', 'Obory', 'fn');

		// -- branchesParts
		//$activities = $this->app()->cfgItem ('services.subjects.branches.parts');
		//$this->qryPanelAddCheckBoxes($panel, $qry, $activities, 'parts', 'Podobory', 'fn');

		// -- activities
		$activities = $this->app()->cfgItem ('services.subjects.activities');
		$this->qryPanelAddCheckBoxes($panel, $qry, $activities, 'activities', 'Činnosti', 'fn');

		// -- commodities
		$commodities = $this->app()->cfgItem ('services.subjects.commodities');
		$this->qryPanelAddCheckBoxes($panel, $qry, $commodities, 'commodities', 'Komodity', 'fn');

		// -- region 1
		$region1 = $this->app()->cfgItem('nomenc.cz-nuts-3');
		if (count($region1) !== 0)
		{
			$chbxs = [];
			forEach ($region1 as $enumNdx => $r)
				$chbxs[$enumNdx] = ['title' => $r['sn'], 'id' => $enumNdx];

			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.region1', ['items' => $chbxs]);
			$qry[] = ['id' => 'region1', 'style' => 'params', 'title' => 'Kraje', 'params' => $params];
		}

		// -- region 2
		$region2 = $this->app()->cfgItem('nomenc.cz-nuts-4');
		if (count($region2) !== 0)
		{
			$chbxs = [];
			forEach ($region2 as $enumNdx => $r)
				$chbxs[$enumNdx] = ['title' => $r['sn'], 'id' => $enumNdx];

			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.region2', ['items' => $chbxs]);
			$qry[] = ['id' => 'region2', 'style' => 'params', 'title' => 'Okresy', 'params' => $params];
		}

		// -- Type of Bussiness Entity
		$q = [];
		$q[] = 'SELECT * FROM [e10_base_nomencItems] ';
		array_push($q, 'WHERE [nomencType] = %i', $this->nomencTypeTobe['ndx']);
		array_push($q, 'AND docStateMain < 5');
		array_push($q, 'ORDER BY [order], shortName, ndx');
		$rows = $this->table->db()->query ($q);
		if (count($rows) !== 0)
		{
			$chbxs = [];
			forEach ($rows as $r)
				$chbxs[$r['ndx']] = ['title' => $r['shortName'], 'id' => $r['ndx']];

			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.nomencTobe', ['items' => $chbxs]);
			$qry[] = ['id' => 'nomencTobe', 'style' => 'params', 'title' => 'Právní formy', 'params' => $params];
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewSubjectsSources
 * @package services\subjects
 */
class ViewSubjectsSources extends ViewSubjects
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = FALSE;

		TableView::init();

		$this->nomencTypeTobe = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-tobe')->fetch();
		$this->nomencTypeNuts = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nuts')->fetch();

		$this->region1 = $this->app()->cfgItem('nomenc.cz-nuts-3');
		$this->region2 = $this->app()->cfgItem('nomenc.cz-nuts-4');
		$this->kinds = $this->app()->cfgItem('services.subjects.kinds');
		$this->sizes = $this->app()->cfgItem('services.subjects.sizes');
	}
}


/**
 * Class ViewDetailSubject
 * @package services\subjects
 */
class ViewDetailSubject extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('services.subjects.DocumentCardSubject');
	}
}


/**
 * Class FormSubject
 * @package services\subjects
 */
class FormSubject extends TableForm
{
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

			$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Obory', 'icon' => 'icon-circle-o-notch'];
			$tabs ['tabs'][] = ['text' => 'Zařazení', 'icon' => 'icon-list-ol'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addList ('properties', '', TableForm::loAddToFormLayout);
					$this->addList ('address', '', TableForm::loAddToFormLayout);
					$this->addSeparator(TableForm::coH2);
					$this->addColumnInput ('region1');
					$this->addColumnInput ('region2');
					$this->addColumnInput ('size');
					$this->addColumnInput ('kind');
				$this->closeTab ();

				$this->openTab ();
					$this->addList ('branches');
				$this->closeTab ();

				$this->openTab ();
					$this->addList ('nomenclature');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

