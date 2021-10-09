<?php

namespace lib\ebanking\download;


/**
 * Class DownloadStatementsCSOBEmailXml
 * @package lib\ebanking\download
 */
class DownloadStatementsCSOBEmailXml extends \lib\ebanking\download\DownloadStatementsInbox
{
	public function init ()
	{
		parent::init();

		//$this->inboxQueryParams['subject'] = 'Era oznámení: Výpis z účtu';
		//$this->inboxQueryParams['emailFrom'] = 'era.info@erasvet.cz';
		
		//$this->inboxQueryParams['subject'] = 'Moje info: Výpis z účtu';
		//$this->inboxQueryParams['emailFrom'] = 'moje.info@postovnisporitelna.cz';

		$this->inboxQueryParams['subject'] = 'Moje info - Výpis z účtu';
		$this->inboxQueryParams['emailFrom'] = 'noreply@csob.cz';

		$this->inboxQueryParams['attachmentSuffix'] = '.XML';
	}
}
