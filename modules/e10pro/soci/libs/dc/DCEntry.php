<?php

namespace e10pro\soci\libs\dc;
use \Shipard\Utils\Utils;
use \wkf\core\TableIssues;


/**
 * class DCEntry
 */
class DCEntry extends \Shipard\Base\DocumentCard
{
  protected $linkedAttachments = [];

  public function attachments ()
	{
		$this->addContentAttachments ($this->recData ['ndx']);
    $this->addLinkedAttachments();

		foreach ($this->linkedAttachments as $la)
			$this->addContentAttachments ($la ['recid'], $la ['tableid'], $la ['title'], $la ['downloadTitle']);
	}

	function linkedInboxOutbox(&$docsFrom, &$docsTo)
	{
		if (!isset($this->recData['ndx']) || !$this->recData['ndx'])
			return;

		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app()->table ('wkf.core.issues');

		$rows = $this->db()->query (
			'SELECT * FROM [wkf_core_issues] WHERE ',
			' EXISTS (SELECT ndx FROM [e10_base_doclinks] AS l WHERE linkId = %s', 'e10pro-soci-entries-inbox', ' AND srcTableId = %s', 'e10pro.soci.entries',
			' AND srcRecId = %i', $this->recData['ndx'], ' AND l.dstRecId = wkf_core_issues.ndx)',
			' ORDER BY dateCreate DESC, ndx DESC'
		);

		foreach ($rows as $r)
		{
			if ($r['docState'] === 9800)
				continue; // deleted
			$dateStr = $r['dateIncoming'] ? utils::datef ($r['dateIncoming']) : utils::datef ($r['date']);
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

  protected function addLinkedAttachments()
  {
    $docsFrom = [];
    $docsTo = [];

    // -- inbox/outbox
    $this->linkedInboxOutbox($docsFrom, $docsTo);
  }

  public function addCoreInfo()
  {
    $entryTo = $this->app()->loadItem($this->recData['entryTo'], 'e10mnf.core.workOrders');
    $entryKind = $this->app()->cfgItem('e10pro.soci.entriesKinds.'.$this->recData['entryKind']);
    $entryState = $this->app()->cfgItem('e10pro.soci.entryStates.'.$this->recData['entryState'], NULL);

    $pidLabels = [['text' => $this->recData['email'], 'class' => '']];
    $existedPersonNdx = $this->checkPerson($pidLabels);

    $t = [];

    $personInfo = [];

    if (!$this->recData['dstPerson'])
    {
      $personInfo [] = ['text' => $this->recData['firstName'].' '.$this->recData['lastName'], 'class' => 'block'];
      if (!$existedPersonNdx)
      {
        $personInfo [] = [
          'type' => 'action', 'action' => 'addwizard',
          'text' => 'Vytvořit', 'data-class' => 'e10pro.soci.libs.WizardGenerateFromEntries',
          'icon' => 'cmnbkpRegenerateOpenedPeriod',
          'class' => 'pull-right'
        ];
      }
      else
      {
        $personInfo [] = [
          'type' => 'action', 'action' => 'addwizard',
          'text' => 'Osoba existuje, propojit', 'data-class' => 'e10pro.soci.libs.WizardLinkEntryToPerson',
          'data-addParams' => 'personNdx='.$existedPersonNdx,
          'icon' => 'cmnbkpRegenerateOpenedPeriod',
          'class' => 'pull-right'
        ];
      }
    }
    else
    {
      $personInfo [] = [
        'text' => $this->recData['lastName'].' '.$this->recData['firstName'],
        'docAction' => 'edit',
        'table' => 'e10.persons.persons',
        'pk' => $this->recData['dstPerson'],
      ];
    }

    $t[] = ['t' => 'Datum přihlášky', 'v' => Utils::datef($entryTo['dateIssue'])];
    $t[] = ['t' => 'Přihláška do', 'v' => $entryTo['title']];
    if ($this->recData['entryPeriod'])
    {
      $period = $this->app()->cfgItem('e10pro.soci.periods.'.$this->recData['entryPeriod'], NULL);
      if ($period)
        $t[] = ['t' => 'Období', 'v' => $period['sn']];
    }
    $t[] = ['t' => 'Jméno', 'v' => $personInfo];
    $t[] = ['t' => 'Datum narození', 'v' => Utils::datef($this->recData['birthday'])];
    $t[] = ['t' => 'E-mail', 'v' => $this->recData['email']];
    $t[] = ['t' => 'Telefon', 'v' => $this->recData['phone']];

    if ($entryKind['useSaleType'] ?? 0)
    {
      $saleTypes = $this->table->columnInfoEnum ('saleType', 'cfgText');
      $t[] = ['t' => 'Sleva', 'v' => $saleTypes[$this->recData['saleType']]];
    }
    if ($entryKind['usePaymentPeriod'] ?? 0)
    {
      $paymentPeriods = $this->table->columnInfoEnum ('paymentPeriod', 'cfgText');
      $t[] = ['t' => 'Platba na období', 'v' => $paymentPeriods[$this->recData['paymentPeriod']]];
    }

    if ($this->recData['note'] && trim($this->recData['note']) !== '')
      $t[] = ['t' => 'Poznámka', 'v' => $this->recData['note']];


    $t[0]['_options']['cellClasses']['t'] = 'width10em';

    $h = ['t' => '_T', 'v' => 'H'];

    $paneClass = 'e10-ds '.$entryState['stateClass'];
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table '.$paneClass, 'type' => 'table', 'header' => $h, 'table' => $t,
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
  }

  protected function checkPerson(&$labels)
  {
    $personNdx = 0;

		$q = [];
		array_push($q, 'SELECT persons.*');
		array_push($q, ' FROM e10_persons_persons AS persons');
		//array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON props.recid = persons.ndx');
		array_push($q, ' WHERE 1');

    array_push($q, ' AND [persons].firstName = %s', $this->recData['firstName']);
    array_push($q, ' AND [persons].lastName = %s', $this->recData['lastName']);

    //$newPerson ['contacts'][] = ['type' => 'email', 'value' => $this->entryRecData['email']];

    /*
		array_push($q, ' AND props.[group] = %s', 'ids');
		array_push($q, ' AND props.[property] = %s', 'pid');
		array_push($q, ' AND props.[tableid] = %s', 'e10.persons.persons');
    array_push($q, ' AND props.[valueString] = %s', $pid);
    */

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $labels[] = [
        'text' => $r['fullName'], 'class' => 'pull-right', 'icon' => 'system/iconUser',
        'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10.persons.persons'
      ];

      $personNdx = $r['ndx'];
    }

    return $personNdx;
  }

  protected function addInvoicing()
  {
    $ie = new \e10pro\soci\libs\EntriesInvoicingEngine($this->app());
    $ie->init();
    $ie->setEntry($this->recData['ndx']);
    $ie->loadInvoices();
    $ie->planInvoices();

    $title = [['text' => 'Faktury k vystavení', 'class' => 'h2 block']];
    if (count($ie->planInvoicesTable))
    {
      if ($this->recData['entryState'] !== 0)
      {
        $this->addContent ('body', [
          'pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'paneTitle' => $title,
          'line' => ['text' => 'Přihláška není platná, faktury není možné vystavit...', 'class' => 'e10-off']
        ]);
      }
      else
      {
        $this->addContent ('body', [
            'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'paneTitle' => $title,
            'header' => $ie->planInvoicesHead, 'table' => $ie->planInvoicesTable,
        ]);
      }
    }

    $title = ['text' => 'Vystavené faktury', 'class' => 'h2 block'];
    if (count($ie->existedInvoicesTable))
    {
      $this->addContent ('body', [
          'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'paneTitle' => $title,
          'header' => $ie->existedInvoicesHead, 'table' => $ie->existedInvoicesTable,
      ]);
    }
    else
    {
      $this->addContent ('body', [
        'pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'paneTitle' => $title,
        'line' => ['text' => 'Žádna faktura zatím nebyla vystavena...']
      ]);
    }
  }

  public function createContent ()
	{
    $this->addCoreInfo();
    $this->addInvoicing();
    $this->attachments ();
	}
}
