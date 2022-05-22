<?php

namespace e10doc\debs;

class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$this->checkSpdAccounts ();
	}

	public function checkSpdAccounts ()
	{
    $rows = $this->db()->query('SELECT * FROM [e10doc_debs_spdAccounts] WHERE [spreadsheetId] LIKE %s', 'pkgs.accounting.debs.%');
		forEach ($rows as $r)
		{
      $newSpreadsheetId = 'install.country-modules.debs.cz.'.substr($r['spreadsheetId'], 21);

      $this->db()->query('UPDATE [e10doc_debs_spdAccounts] SET [spreadsheetId] = %s', $newSpreadsheetId, ' WHERE [ndx] = %i', $r['ndx']);
		}
	}
}
