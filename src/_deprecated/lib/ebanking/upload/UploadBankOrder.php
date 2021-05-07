<?php

namespace lib\ebanking\upload;


/**
 * Class UploadBankOrder
 * @package lib\ebanking\upload
 */
class UploadBankOrder
{
	static function upload ($app, $orderNdx)
	{
		$orderRecData = $app->loadItem ($orderNdx, 'e10doc.core.heads');
		if (!$orderRecData)
			return FALSE;

		$bankAccountRecData = $app->loadItem ($orderRecData['myBankAccount'], 'e10doc.base.bankaccounts');
		if (!$bankAccountRecData)
			return FALSE;

		$uploadSettings = $app->cfgItem('ebanking.uploads.'.$bankAccountRecData['uploadStatements'], FALSE);
		if ($uploadSettings === FALSE || !isset($uploadSettings['class']))
			return FALSE;

		$generator = $app->createObject($uploadSettings['class']);
		if (!$generator)
			return FALSE;

		$generator->init ();
		$generator->setBankOrder ($orderNdx);
		$generator->run ();

		return TRUE;
	}
}
