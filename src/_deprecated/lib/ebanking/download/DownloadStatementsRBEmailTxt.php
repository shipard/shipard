<?php

namespace lib\ebanking\download;

/**
 * Class DownloadStatementsRBEmailTxt
 * @package lib\ebanking\download
 */
class DownloadStatementsRBEmailTxt extends \lib\ebanking\download\DownloadStatementsInbox
{
	public function init ()
	{
		parent::init();

		$this->inboxQueryParams['subject'] = 'Výpis z účtu';
		$this->inboxQueryParams['emailFrom'] = 'info@rb.cz';
	}

	protected function doOneInbox ($recData)
	{
		$this->inboxNdx = $recData['ndx'];
		$attachments = \E10\Base\getAttachments ($this->app, 'wkf.core.issues', $this->inboxNdx);
		foreach ($attachments as $a)
		{
			if (mb_substr($a, -4) === '.ZIP')
			{
				$fullFileName = __APP_DIR__.'/att/'.$a;
				$extractedFileName = '';

				$fileTypes = ['.GPC', '.XML'];
				foreach ($fileTypes as $fileType)
				{
					$bsFullFileName = strstr($fullFileName, $fileType, TRUE);
					if (!$bsFullFileName)
						continue;
					$bsParts = explode('/', $bsFullFileName);
					if (!count($bsParts))
						continue;
					$baseFileName = array_pop($bsParts);
					$bsShortFileName = $baseFileName.$fileType;
					$extractedFileName = __APP_DIR__.'/tmp/'.$bsShortFileName;

					$zip = new \ZipArchive();
					$res = $zip->open($fullFileName);
					if ($res === TRUE)
					{
						$zip->extractTo(__APP_DIR__.'/tmp/', [$bsShortFileName]);
						$zip->close();
					}

					if (!is_readable($extractedFileName))
					{
						$extractedFileName = '';
						continue;
					}

					break;
				}

				if ($extractedFileName === '')
					return;

				$data = file_get_contents($extractedFileName);
				if ($data === FALSE)
					continue;
				$this->statementTextData = $data;
				break;
			}
			elseif (mb_substr($a, -4) === '.PDF')
			{ // attached PDF statement: Vypis_6041046424_CZK_2019_38.PDF
				$coreFileName = substr($a, 0, -4);
				$fnParts = explode('_', $coreFileName);
				if (count($fnParts) < 5)
					continue;
				$bsNumber = intval(strstr(array_pop($fnParts), '-', TRUE));
				$bsYear = intval(array_pop($fnParts));
				$bsCurrency = array_pop($fnParts);
				$bsAccount = array_pop($fnParts);

				$thisAccountNumberParts = explode('/', $this->bankAccountCfg['bankAccount']);
				if (!isset($thisAccountNumberParts[0]) || $thisAccountNumberParts[0] !== $bsAccount)
					continue;

				// -- search bank account document
				$bdq[] = 'SELECT ndx, docNumber FROM [e10doc_core_heads] WHERE 1';
				array_push($bdq, ' AND [myBankAccount] = %i', $this->bankAccountCfg['ndx']);
				array_push($bdq, ' AND [docOrderNumber] = %i', $bsNumber);
				array_push($bdq, ' AND ([dateAccounting] >= %d', $bsYear.'-01-01', ' AND [dateAccounting] <= %d)', $bsYear.'-12-31');
				array_push($bdq, ' AND [docState] NOT IN %in', [4100, 9800]);
				array_push($bdq, ' ORDER BY [ndx] DESC');
				$exist = $this->db()->query($bdq)->fetch();
				if (!$exist)
					return;

				$this->inboxNdx = $recData['ndx'];

				if ($this->inboxNdx)
				{
					/** @var \wkf\core\TableIssues $tableIssues */
					$tableIssues = $this->app->table ('wkf.core.issues');
					$issueKindNdx = $tableIssues->defaultSystemKind(52); // bank statement
					$sectionNdx = $tableIssues->defaultSection(54); // bank
					if (!$sectionNdx)
						$sectionNdx = $tableIssues->defaultSection(51); // documents
					if (!$sectionNdx)
						$sectionNdx = $tableIssues->defaultSection(20); // secretariat

					$newLink = [
						'linkId' => 'e10docs-inbox',
						'srcTableId' => 'e10doc.core.heads', 'srcRecId' => $exist['ndx'],
						'dstTableId' => 'wkf.core.issues', 'dstRecId' => $this->inboxNdx,
					];
					$this->db()->query('INSERT INTO [e10_base_doclinks] ', $newLink);

					$this->updateInbox(['issueKind' => $issueKindNdx, 'section' => $sectionNdx, 'docState' => 1200, 'docStateMain' => 1]);
				}

				return;
			}
		}

		if ($this->statementTextData === FALSE)
			return;

		$this->saveToInbox_addNotify();
		$this->createBankDocument ();

		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app->table ('wkf.core.issues');
		$issueKindNdx = $tableIssues->defaultSystemKind(52); // bank statement
		$sectionNdx = $tableIssues->defaultSection(54); // bank
		if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(51); // documents
		if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(20); // secretariat

		$this->updateInbox(['issueKind' => $issueKindNdx, 'section' => $sectionNdx, 'docState' => 1200, 'docStateMain' => 1]);
	}
}
