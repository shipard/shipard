<?php

namespace e10doc\slr\dc;
use \Shipard\Utils\Utils, wkf\core\TableIssues;


/**
 * class DCImport
 */
class DCImport extends \Shipard\Base\DocumentCard
{
	/** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;

	protected $linkedAttachments = [];

	protected function addErrors()
	{
		/** @var \e10doc\slr\TableImports */
		$tableImports = $this->app()->table('e10doc.slr.imports');
		$e = $tableImports->importEngine ($this->recData['ndx']);
		if (!$e)
			return;
		$e->setImportNdx($this->recData['ndx']);
		$e->run();

		if ($e->messages() !== FALSE)
		{
			$code = $e->errorsHtml();

			$this->addContent('body',  [
				'pane' => 'e10-pane e10-pane-table', 'type' => 'line',
				'paneTitle' => ['text' => 'Chyby při zpracování', 'class' => 'h2 e10-error title', 'icon' => 'system/iconWarning'],
				'line' => ['code' => $code],
			]);
		}
	}

	protected function addAccDoc()
  {
    $docNdx = $this->recData['docAccBal'];
    $title = [];
    $title[] = ['text' => 'Závazky', 'class' => 'h2', 'icon' => 'docType/accDocs'];

    $docStateStyle = '';
    $body = [];
    if ($docNdx === 0)
    {
      $body[] = ['text' => 'Doklad zatím není vystaven', 'class' => 'e10-error'];

      $title [] = [
        'type' => 'action', 'action' => 'addwizard',
        'text' => 'Vystavit', 'data-class' => 'e10doc.slr.libs.WizardGenerateAccDocBalance',
        'icon' => 'system/actionAdd',
        'class' => 'pull-right'
      ];
    }
    else
    {
      $title [] = [
        'type' => 'action', 'action' => 'addwizard',
        'text' => 'Přegenerovat', 'data-class' => 'e10doc.slr.libs.WizardGenerateAccDocBalance',
        'icon' => 'cmnbkpRegenerateOpenedPeriod',
        'class' => 'pull-right'
      ];

      $docRecData = $this->tableDocsHeads->loadItem($docNdx);
      $title [] = [
        'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $docNdx,
        'text' => $docRecData['docNumber'],
        'icon' => $this->tableDocsHeads->tableIcon($docRecData),
        'class' => 'pull-right', 'actionClass' => 'btn btn-primary', 'type' => 'button'
      ];

      $docState = $this->tableDocsHeads->getDocumentState ($docRecData);
      $docStateStyle = ' e10-ds '.$this->tableDocsHeads->getDocumentStateInfo ($docState ['states'], $docRecData, 'styleClass');
    }
    $title[] = ['text' => '', 'class' => 'block'];

    $this->addContent('body', ['pane' => 'e10-pane e10-pane-table'.$docStateStyle, 'paneTitle' => $title, 'type' => 'line', 'line' => $body]);
  }

	protected function addEmpsRecs()
	{
		$q = [];
    array_push ($q, 'SELECT [empsRecs].*,');
		array_push ($q, ' emps.fullName AS empName, emps.personalId AS empPersonalId,');
		array_push ($q, ' imports.calendarYear, imports.calendarMonth');
		array_push ($q, ' FROM [e10doc_slr_empsRecs] AS [empsRecs]');
		array_push ($q, ' LEFT JOIN [e10doc_slr_emps] AS emps ON [empsRecs].[emp] = [emps].ndx');
		array_push ($q, ' LEFT JOIN [e10doc_slr_imports] AS [imports] ON [empsRecs].[import] = [imports].ndx');
		array_push ($q, ' WHERE [empsRecs].[import] = %i', $this->recData['ndx']);
		array_push ($q, ' AND [empsRecs].docState != %i', 9800);
		array_push ($q, ' ORDER BY emps.fullName, emps.personalId, [empsRecs].ndx');

		$t = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'pid' => $r['empPersonalId'],
				'emp' => $r['empName'],
			];

			$btns = [];
			$dsClass = '';
			$this->addEmpRecAccButtons($r, $btns, $dsClass);
			$item['actions'] = $btns;

			$item['_options']['cellClasses']['actions'] = $dsClass;

			$t[] = $item;
		}

		$h = ['#' => '#', 'pid' => ' Os.č.', 'emp' => 'Zaměstnanec', /*'costs' => '+Náklady',*/ 'actions' => ' Úč. doklad'];

    $this->addContent('body',  [
      'pane' => 'e10-pane e10-pane-table',
      'table' => $t, 'header' => $h,
    ]);
	}

	protected function addEmpRecAccButtons($empRecRecData, &$dest, &$docStateStyle)
  {
    $docNdx = $empRecRecData['docAcc'];

    $docStateStyle = '';
    if ($docNdx === 0)
    {
      $dest[] = [
        'type' => 'action', 'action' => 'addwizard',
        'text' => 'Vystavit', 'data-class' => 'e10doc.slr.libs.WizardGenerateAccDoc',
        'icon' => 'system/actionAdd',
        'class' => 'pull-right',
				'actionClass' => 'btn btn-xs btn-primary',
				'data-addparams' => 'empRecNdx='.$empRecRecData['ndx'],
      ];
    }
    else
    {
      $dest [] = [
        'type' => 'action', 'action' => 'addwizard',
        'text' => 'Přegenerovat', 'data-class' => 'e10doc.slr.libs.WizardGenerateAccDoc',
        'icon' => 'cmnbkpRegenerateOpenedPeriod',
        'class' => 'pull-right',
				'actionClass' => 'btn btn-xs btn-primary',
				'data-addparams' => 'empRecNdx='.$empRecRecData['ndx'],
      ];

      $docRecData = $this->tableDocsHeads->loadItem($docNdx);
      $dest [] = [
        'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $docNdx,
        'text' => $docRecData['docNumber'],
        'icon' => $this->tableDocsHeads->tableIcon($docRecData),
        'class' => 'pull-right', 'actionClass' => 'btn btn-xs btn-primary', 'type' => 'button'
      ];

      $docState = $this->tableDocsHeads->getDocumentState ($docRecData);
      $docStateStyle = $this->tableDocsHeads->getDocumentStateInfo ($docState ['states'], $docRecData, 'styleClass');
    }
  }

	public function addAttachments ()
	{
		$docsFrom = [];
		$docsTo = [];
		$this->linkedInboxOutbox($docsFrom, $docsTo);

		$this->addContentAttachments ($this->recData ['ndx']);

		foreach ($this->linkedAttachments as $la)
			$this->addContentAttachments ($la ['recid'], $la ['tableid'], $la ['title'], $la ['downloadTitle']);
	}

	function linkedInboxOutbox(&$docsFrom, &$docsTo)
	{
		if (!isset($this->recData['ndx']) || !$this->recData['ndx'])
			return;

		$fromTableId = 'e10doc.slr.imports';
		$docLinkId = 'e10doc-slr-imports-inbox';

		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app()->table ('wkf.core.issues');

		$q = [];
		/*	array_push($q, '(SELECT * FROM [wkf_core_issues]');
		array_push($q, ' WHERE (tableNdx = %i', 1078, ' AND recNdx = %i', $this->recData['ndx'], '))');
		array_push($q, ' UNION ');
		*/
		array_push($q, '(SELECT * FROM [wkf_core_issues]');
		array_push($q, ' WHERE EXISTS (SELECT ndx FROM [e10_base_doclinks] AS l WHERE linkId = %s', $docLinkId,
										' AND srcTableId = %s', $fromTableId,
										' AND srcRecId = %i', $this->recData['ndx'], ' AND l.dstRecId = wkf_core_issues.ndx))');
		array_push($q, ' ORDER BY dateCreate DESC, ndx DESC');
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			if ($r['docState'] === 9800)
				continue; // deleted
			$dateStr = $r['dateIncoming'] ? Utils::datef ($r['dateIncoming']) : Utils::datef ($r['date']);
			$msgItem = ['icon' => $tableIssues->tableIcon ($r), 'text' => '#'.$r['ndx'], 'class' => 'tag tag-contact',
				'prefix' => $dateStr,
				'docAction' => 'edit', 'table' => 'wkf.core.issues', 'pk' => $r['ndx']];
			if ($r['issueType'] === TableIssues::mtInbox)
			{
				$msgItem['title'] = 'Došlá pošta: '.$r['subject'];
				$docsFrom[] = $msgItem;
			}
			elseif ($r['issueType'] === TableIssues::mtOutbox)
			{
				$msgItem['title'] = 'Odeslaná pošta: '.$r['subject'];
				$docsTo[] = $msgItem;
			}
			else
			{
				$msgItem['title'] = 'TEST: '.$r['subject'];
				$docsTo[] = $msgItem;
			}

			$laTitleLeft = ['icon' => 'system/formAttachments', 'text' => 'Přílohy'];
			$laTitleRight = $msgItem;
			$laTitleRight ['class'] = 'pull-right';

			$laDownloadTitleLeft = ['icon' => 'system/actionDownload', 'text' => 'Soubory ke stažení'];
			$laDownloadTitleRight = $msgItem;
			$laDownloadTitleRight ['class'] = 'pull-right';

			$this->linkedAttachments[] = [
				'tableid' => 'wkf.core.issues', 'recid' => $r['ndx'],
				'title' => [$laTitleLeft, $laTitleRight], 'downloadTitle' => [$laDownloadTitleLeft, $laDownloadTitleRight]
			];
		}
	}

	public function createContentBody ()
	{
		$this->addErrors();
		$this->addAccDoc();
		$this->addEmpsRecs();

		$this->addAttachments();
	}

	public function createContent ()
	{
		$this->tableDocsHeads = $this->app()->table('e10doc.core.heads');

		$this->createContentBody ();
	}
}
