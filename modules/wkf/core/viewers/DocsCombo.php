<?php

namespace wkf\core\viewers;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableView, \e10\utils, \wkf\core\TableIssues;
use \Shipard\Utils\World;

/**
 * Class DocsCombo
 * @package wkf\core\viewers
 */
class DocsCombo extends TableView
{
	/** @var  \wkf\base\TableSections */
	var $tableSections;
	var $usersSections;

	/** @var \e10doc\helpers\TableWkfSectionsRelations */
	var $tableWkfSectionsRelations;

	var $issuesStatuses;
	var $msgKinds;
	var $classification = [];

	var $thisUserId = 0;

	var $textRenderer;

	var $linkedPersons = [];
	var $atts = [];

	var $srcDocType = '';
	var $showSections = [];

	var $sourcesIcons = [
		0 => 'icon-keyboard-o', 1 => 'icon-envelope-o', 2 => 'icon-plug',
		3 => 'icon-android', 4 => 'system/iconWarning', 5 => 'icon-globe'
	];

	var $docPaymentMethods = NULL;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->queryParam('docType') !== FALSE)
			$this->srcDocType = $this->queryParam('docType');

		$this->docPaymentMethods = $this->table->app()->cfgItem ('e10.docs.paymentMethods', NULL);

		$mq [] = ['id' => 'active', 'title' => 'K řešení', 'icon' => 'system/filterActive'];
		$mq [] = ['id' => 'done', 'title' => 'Hotovo', 'icon' => 'system/filterDone'];
		$mq [] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'system/filterArchive'];
		$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
		if ($this->app()->hasRole('pwuser'))
			$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'system/filterTrash'];
		$this->setMainQueries($mq);

		$this->tableSections = $this->app->table ('wkf.base.sections');
		$this->usersSections = $this->tableSections->usersSections();
		$this->issuesStatuses = $this->app->cfgItem ('wkf.issues.statuses.all');

		$this->tableWkfSectionsRelations = $this->app->table ('e10doc.helpers.wkfSectionsRelations');

		$this->thisUserId = $this->app()->userNdx();

		$this->objectSubType = TableView::vsDetail;
		$this->enableToolbar = TRUE;

		if ($this->srcDocType !== '')
		{
			$this->tableWkfSectionsRelations->documentSections(['docType' => $this->srcDocType], $this->showSections);
		}

		if (!count($this->showSections))
		{
			if ($this->srcDocType === 'bank')
				$this->showSections[] = $this->table->defaultSection(54);
			elseif ($this->srcDocType === 'cash')
				$this->showSections[] = $this->table->defaultSection(55);
			else
				$this->showSections[] = $this->table->defaultSection(51);
		}

		if (!count($this->showSections))
			$this->showSections[] = 1;

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem['content'] = [];

//		$listItem ['icon'] = $this->table->tableIcon ($item);

		$ndx = $item ['ndx'];

		$item['pk'] = $ndx;
		$listItem ['pane'] = ['class' => 'padd5 e10-ds ', 'title' => [], 'body' => []];

		$title = [];
		$title[] = ['class' => 'id pull-right', 'text' => '#'.$item['issueId'], 'Xicon' => 'system/iconHashtag'];

		if ($item['onTop'])
			$title[] = ['class' => 'id pull-right e10-success', 'text' => '', 'icon' => 'system/iconPinned'];
		if ($item['priority'] < 10)
			$title[] = ['class' => 'id pull-right e10-error', 'text' => '', 'icon' => 'system/issueImportant'];
		elseif ($item['priority'] > 10)
			$title[] = ['class' => 'id pull-right e10-off', 'text' => '', 'icon' => 'system/issueNotImportant'];

		$title[] = ['class' => 'e10-bold df2-list-item-t1', 'text' => $item['subject'], 'icon' => $this->table->tableIcon($item, 1)];
		$title[] = ['text' => utils::datef ($item['dateCreate'], '%D, %T'), 'icon' => $this->sourcesIcons[$item['source']], 'class' => 'e10-off break'];

		$titleClass = '';
		$listItem ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title,
		];

		$docProperties = [];
		if ($item['docPrice'])
		{
			$pl = [
				'text' => Utils::nf($item['docPrice'], 2),
				'class' => 'label label-default', 'title' => 'Cena',
				'icon' => $this->docPaymentMethods[$item['docPaymentMethod']]['icon'] ?? 'system/iconMoney'
			];

			$curr = World::currency($this->app(), $item ['docCurrency']);
			$pl ['suffix'] = strtoupper($curr['i']);

			$docProperties[] = $pl;
		}

		if ($item['docSymbol1'] !== '')
			$docProperties[] = ['text' => $item['docSymbol1'], 'class' => 'label label-default', 'title' => 'Variabilní symbol', 'icon' => 'system/iconExchange'];

		if ($item['docDateDue'])
			$docProperties[] = ['text' => Utils::datef($item['docDateDue']), 'class' => 'label label-default', 'title' => 'Datum splatnosti', 'icon' => 'system/iconMoney'];
		if ($item['docDateTax'])
		{
			$ld = ['text' => Utils::datef($item['docDateTax']), 'class' => 'label label-default', 'title' => 'DUZP', 'icon' => 'detailDeferredTax'];
			if ($item['docDateTaxDuty'] && $item['docDateTax'] !== $item['docDateTaxDuty'])
			{
				$ld['suffix'] = Utils::datef($item['docDateTaxDuty']);
				$ld['title'] .= ' + DPPD';
			}

			$docProperties[] = $ld;
		}

		if ($item['docCentre'])
			$docProperties[] = ['text' => $item['docCentreName'], 'class' => 'label label-default', 'title' => 'Středisko', 'icon' => 'tables/e10doc.base.centres'];

		if ($item['docProperty'])
			$docProperties[] = ['text' => $item['docPropertyName'], 'class' => 'label label-default', 'title' => 'Majetek', 'icon' => 'tables/e10pro.property.property'];

		if (count($docProperties))
			$listItem ['pane']['body'][] = ['value' => $docProperties, 'class' => 'padd5'];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		$item ['pane']['class'] .= $item ['class'];
		$ndx = $item['pk'];

		if (isset ($this->linkedPersons [$ndx]['wkf-issues-from'][0]['pndx']))
		{
			$item ['pane']['title'][0]['value'][] = ['value' =>$this->linkedPersons [$ndx]['wkf-issues-from']];
		}

		if (isset($this->atts[$ndx]))
		{
			$links = $this->attLinks($ndx);
			if (count($links))
				$item ['pane']['body'][] = ['value' => $links, 'class' => 'padd5'];
		}
	}

	public function selectRows ()
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = 'active';

		$q = [];
		array_push ($q, 'SELECT issues.*,');
		array_push ($q, ' persons.fullName AS authorFullName, ');
		array_push ($q, ' targets.shortName AS targetName');
		array_push ($q, ' FROM [wkf_core_issues] AS issues');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON issues.author = persons.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_targets AS [targets] ON issues.target = targets.ndx');
		array_push ($q, ' WHERE 1');

		$this->qrySection($q);

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, 'issues.[subject] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR issues.[text] LIKE %s', '%'.$fts.'%');

			array_push ($q, ' OR EXISTS (',
				'SELECT persons.fullName FROM [e10_base_doclinks] AS docLinks, e10_persons_persons AS p',
				' WHERE issues.ndx = srcRecId AND srcTableId = %s', 'wkf.core.issues',
				' AND dstTableId = %s', 'e10.persons.persons', ' AND docLinks.dstRecId = p.ndx',
				' AND p.fullName LIKE %s)', '%'.$fts.'%'
			);

			array_push ($q, ')');
		}


		// -- fulltext & docState
		if ($fts !== '')
		{
			if ($mqId === 'active')
			{
				array_push($q, ' AND (issues.[docStateMain] IN %in', [1, 2, 5],
					' OR (issues.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
					')');
			}
			elseif ($mqId === 'done')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 2);
			elseif ($mqId === 'archive')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 5);
			elseif ($mqId === 'trash')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 4);
		}
		else
		{
			if ($mqId === 'active')
			{
				array_push($q, ' AND (issues.[docStateMain] = %i', 1,
					' OR (issues.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
					' OR issues.[docState] = 8000',
					')');
			}
			elseif ($mqId === 'done')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 2);
			elseif ($mqId === 'archive')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 5);
			elseif ($mqId === 'trash')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 4);
		}

		array_push ($q, ' ORDER BY [displayOrder]');
		array_push($q, $this->sqlLimit ());
		$this->runQuery ($q);
	}

	public function qrySection(&$q)
	{
		//array_push ($q, ' AND issues.[section] IN %in', array_keys($this->usersSections['all']));
		array_push ($q, ' AND issues.[section] IN %in', $this->showSections);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = \E10\Base\linkedPersons ($this->table->app(), $this->table, $this->pks, 'e10-small');
		$this->atts = \E10\Base\loadAttachments ($this->app(), $this->pks, $this->table->tableId());
		//$this->classification = \E10\Base\loadClassification ($this->app(), $this->table->tableId(), $this->pks);
	}

	public function createToolbar ()
	{
		return [];
	}

	function attLinks ($ndx)
	{
		$links = [];
		$attachments = $this->atts[$ndx];
		if (isset($attachments['images']))
		{
			foreach ($attachments['images'] as $a)
			{
				$icon = ($a['filetype'] === 'pdf') ? 'icon-file-pdf-o' : 'icon-picture-o';
				$l = ['text' => $a['name'], 'icon' => $icon, 'class' => 'e10-att-link btn btn-xs btn-default df2-action-trigger', 'prefix' => ''];
				$l['data'] =
					[
						'action' => 'open-link',
						'url-download' => $this->app()->dsRoot.'/att/'.$a['path'].$a['filename'],
						'url-preview' => $this->app()->dsRoot.'/imgs/-w1200/att/'.$a['path'].$a['filename']
					];
				$links[] = $l;
			}
		}

		return $links;
	}
}
