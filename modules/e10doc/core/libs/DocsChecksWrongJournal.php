<?php

namespace e10doc\core\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/debs/debs.php';


/**
 * Class DocsChecksWrongJournal
 * @package e10doc\core\libs
 */
class DocsChecksWrongJournal extends DocsChecks
{
	protected function doAllQuery(&$q)
	{
		array_push($q, ' AND [dateAccounting] >= %d', $this->dateFrom, ' AND [dateAccounting] <= %d', $this->dateTo);

		array_push($q, ' AND heads.docState != %i', 4000);
		array_push($q, ' AND EXISTS (SELECT ndx FROM e10doc_debs_journal WHERE heads.ndx = e10doc_debs_journal.document)');
	}

	function doOne($doc)
	{
		echo $doc['docNumber'];
		if ($this->repair)
		{
			$this->db()->query('DELETE FROM [e10doc_debs_journal] WHERE [document] = %i', $doc['ndx']);
			echo " ...\n";
		}
	}
}
