<?php

namespace e10\reports;
use \Shipard\Utils\Str;


class ModuleServices extends \e10\cli\ModuleServices
{
	public function onAppUpgrade ()
	{
		$this->checkSendReportsTable();
	}

	function checkSendReportsTable()
	{
		$sendReports = $this->app()->cfgItem('e10.reports.sendReports', []);
		foreach ($sendReports as $srNdx => $sr)
		{
			$existedReport = $this->db()->query('SELECT * FROM [e10_reports_reports] WHERE [ndx] = %i', $srNdx)->fetch();
			if ($existedReport)
			{
				$update = [];
				if ($existedReport['fullName'] !== $sr['fn'])
					$update['fullName'] = Str::upToLen($sr['fn'], 120);
				if ($existedReport['shortName'] !== $sr['cn'])
					$update['shortName'] = Str::upToLen($sr['cn'], 80);

				if (count($update))
					$this->db()->query('UPDATE [e10_reports_reports] SET ', $update, ' WHERE [ndx] = %i', $srNdx);
			}
			else
			{
				$item = [
					'ndx' => $srNdx,
					'fullName' => Str::upToLen($sr['fn'], 120),
					'shortName' => Str::upToLen($sr['cn'], 80),
				];
				$this->db()->query('INSERT INTO [e10_reports_reports] ', $item);
			}
		}
	}
}
