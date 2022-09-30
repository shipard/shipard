<?php

namespace e10doc\templates\libs;
use \Shipard\Base\Utility;
use e10doc\core\CreateDocumentUtility;


/**
 * class Generator
 */
class Generator extends Utility
{
  /** @var \e10doc\templates\TableHeads */
  var $tableTemplatesHeads;
	/** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;
	/** @var \e10doc\core\TableRows */
	var $tableDocsRows;

  var $templateNdx = 0;
  var $templateRecData = NULL;

  var array $invHead;
  var array $invRows = [];
  var ?\e10doc\templates\libs\TemplatesScheduler $scheduler = NULL;
  var \Shipard\Utils\Variables $variables;

  public function init()
  {
    $this->tableTemplatesHeads = $this->app()->table('e10doc.templates.heads');
		$this->tableDocsHeads = $this->app()->table('e10doc.core.heads');
		$this->tableDocsRows = $this->app()->table('e10doc.core.rows');

    $this->variables = new \Shipard\Utils\Variables($this->app());
  }

  public function setScheduler(\e10doc\templates\libs\TemplatesScheduler $scheduler)
  {
    $this->scheduler = $scheduler;
  }

  public function setTemplate(int $templaneNdx)
  {
    $this->templateNdx = $templaneNdx;
    $this->templateRecData = $this->tableTemplatesHeads->loadItem($this->templateNdx);
  }

	function saveDocument ($templateRecData, $existedDocNdx = 0)
	{
    $docNdx = 0;

    if ($existedDocNdx)
    {
      $docNdx = $existedDocNdx;
    }
    else
    {
      $exist = $this->db()->query('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', $this->invHead['docType'], ' AND [linkId] = %s', $this->invHead['linkId'])->fetch();

      if ($exist && $this->scheduler->save !== 2)
        return 0; // exist, rewrite is disabled

      if ($exist)
      {
        $docNdx = $exist['ndx'];
        $this->invHead['ndx'] = $docNdx;
        $this->tableDocsHeads->dbUpdateRec ($this->invHead);

        $update = $this->invHead;
        $update['docStateMain'] = 2;
        $update['docState'] = 4000;
        $this->tableDocsHeads->dbUpdateRec ($update);
        $this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
        $this->db()->query ('DELETE FROM [e10doc_core_taxes] WHERE [document] = %i', $docNdx);
        $this->db()->query ('DELETE FROM [e10doc_debs_journal] WHERE [document] = %i', $docNdx);
      }
      else
      {
        $docNdx = $this->tableDocsHeads->dbInsertRec ($this->invHead);
        $this->invHead['ndx'] = $docNdx;
      }
    }

    $f = $this->tableDocsHeads->getTableForm ('edit', $docNdx);


		if ($templateRecData['dstDocState'] == CreateDocumentUtility::sdsConcept)
		{
			$f->recData['docStateMain'] = 0;
			$f->recData['docState'] = 1000;
		}
		elseif ($templateRecData['dstDocState'] == CreateDocumentUtility::sdsConfirmed)
		{
			$f->recData['docStateMain'] = 1;
			$f->recData['docState'] = 1200;
		}
		elseif ($templateRecData['dstDocState'] == CreateDocumentUtility::sdsDone)
		{
			$f->recData['docStateMain'] = 2;
			$f->recData['docState'] = 4000;
		}

    $this->tableDocsHeads->checkDocumentState ($f->recData);

		forEach ($this->invRows as $r)
		{
			$r['document'] = $docNdx;
			$this->tableDocsRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
		{
			$this->tableDocsHeads->dbUpdateRec($f->recData);
		}

    $this->tableDocsHeads->checkAfterSave2 ($f->recData);

    $this->db()->query ('DELETE FROM [e10_base_properties] WHERE [tableid] = %s', 'e10doc.core.heads', ' AND [recid] = %i', $docNdx);
		if ($this->docNote !== '')
		{
			$newProp = [
				'property' => 'note-ext', 'group' => 'notes', 'tableid' => 'e10doc.core.heads',
				'recid' => $docNdx, 'valueMemo' => $this->docNote
			];
			$this->db()->query ('INSERT INTO e10_base_properties', $newProp);
		}

		$this->tableDocsHeads->docsLog ($f->recData['ndx']);

    return $f->recData['ndx'];
	}

  public function send($documentNdx)
  {
    if (!$documentNdx)
      return;

    if ($this->scheduler->resetOutbox)
    {
      $update = ['docState' => 9800, 'docStateMain' => 4,];
      $this->db()->query ('UPDATE [wkf_core_issues] SET ', $update, ' WHERE [recNdx] = %i', $documentNdx, ' AND [tableNdx] = %i', $this->tableDocsHeads->ndx);
    }

    $formReportEngine = new \Shipard\Report\FormReportEngine($this->app());
    $formReportEngine->setParam('documentTable', 'e10doc.core.heads');
    $formReportEngine->setParam('reportClass', 'e10doc.invoicesOut.libs.InvoiceOutReport');
    $formReportEngine->setParam('documentNdx', $documentNdx);

    $formReportEngine->createReport();
    $formReportEngine->createMsg();

    $formReportEngine->sendMsg($this->templateRecData['dstDocAutoSend']);
  }
}
