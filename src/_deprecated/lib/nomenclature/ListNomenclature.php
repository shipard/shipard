<?php

namespace lib\nomenclature;


class ListNomenclature extends \e10\base\ListRows
{
	function loadDataQry (&$q)
	{
		array_push($q, ' AND [tableId] = %s', $this->headTable->tableId());
	}

	protected function checkSavedRow (&$row)
	{
		$row['tableId'] = $this->headTable->tableId();
	}
}
