<?php

namespace e10doc\core\libs;
use \Shipard\Utils\Utils;


/**
 * class CreateDocumentUtility
 */
class CreateDocumentUtility extends \Shipard\Base\Utility
{
	public $docHead = [];
	public $docRows = [];
	var $inboxIssues = [];

  /** @var \E10Doc\Core\TableHeads */
	protected $tableDocs;
  /** @var \E10Doc\Core\TableRows */
	protected $tableRows;

	CONST sdsConcept = 0, sdsConfirmed = 1, sdsDone = 2;

	public function __construct ($app)
	{
		parent::__construct ($app);
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);
	}

	public function createDocumentHead ($docType)
	{
		$this->docHead = ['docType' => $docType];
		$this->tableDocs->checkNewRec($this->docHead);

		$this->docRows = [];
	}

	public function createDocumentRow ($row = NULL)
	{
		$r = ['quantity' => 1];
		$this->tableRows->checkNewRec($r);

		return $r;
	}

	public function addDocumentRow ($row)
	{
		$this->docRows[] = $row;
	}

	public function addInbox($issueNdx)
	{
		$this->inboxIssues[] = $issueNdx;
	}

	function saveDocument ($saveDocState = self::sdsConcept, $existedDocNdx = 0)
	{
    $docNdx = 0;

    if ($existedDocNdx)
    {
      $docNdx = $existedDocNdx;

      $this->docHead['ndx'] = $docNdx;
      $this->tableDocs->dbUpdateRec ($this->docHead);

      $update = $this->docHead;
      if ($this->docHead['docStateMain'] !== 0)
      {
        $update['docStateMain'] = 2;
        $update['docState'] = 4000;
      }
      $this->tableDocs->dbUpdateRec ($update);
      $this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
      $this->db()->query ('DELETE FROM [e10doc_core_taxes] WHERE [document] = %i', $docNdx);
      $this->db()->query ('DELETE FROM [e10doc_debs_journal] WHERE [document] = %i', $docNdx);
    }
    else
    {
      $docNdx = $this->tableDocs->dbInsertRec ($this->docHead);
      $this->docHead['ndx'] = $docNdx;
    }

		$f = $this->tableDocs->getTableForm ('edit', $docNdx);

		forEach ($this->docRows as $r)
		{
			$r['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
		{
			if ($saveDocState == self::sdsConcept)
			{
				$f->recData['docStateMain'] = 0;
				$f->recData['docState'] = 1000;
			}
			elseif ($saveDocState == self::sdsConfirmed)
			{
				$f->recData['docStateMain'] = 1;
				$f->recData['docState'] = 1200;
			}
			elseif ($saveDocState == self::sdsDone)
			{
				$f->recData['docStateMain'] = 2;
				$f->recData['docState'] = 4000;
			}

			$this->tableDocs->checkDocumentState ($f->recData);
			$this->tableDocs->dbUpdateRec ($f->recData);
			$this->tableDocs->checkAfterSave2 ($f->recData);

			$this->saveInbox($f->recData['ndx']);

			$this->tableDocs->docsLog ($f->recData['ndx']);

			$docStates = $this->tableDocs->documentStates ($f->recData);
			$ds = $docStates['states'][$f->recData['docState']];

			$printCfg = [];
			$this->tableDocs->printAfterConfirm($printCfg, $f->recData, $ds);

      return $docNdx;
		}
	}

	protected function saveInbox($docNdx)
	{
		if (!count($this->inboxIssues))
			return;

		if ($docNdx)
		{
			$this->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [srcTableId] = %s', 'e10doc.core.heads',
													' AND [srcRecId] = %i', $docNdx,
													' AND [linkId] = %s', 'e10docs-inbox'
												);
		}

		foreach ($this->inboxIssues as $issueNdx)
		{
			$newLink = [
				'linkId' => 'e10docs-inbox',
				'srcTableId' => 'e10doc.core.heads', 'srcRecId' => $docNdx,
				'dstTableId' => 'wkf.core.issues', 'dstRecId' => $issueNdx,
			];
			$this->db()->query('INSERT INTO [e10_base_doclinks] ', $newLink);
		}
	}
}

