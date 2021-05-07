<?php

namespace wkf\core;


/**
 * @param $app
 * @return \e10\Response
 */
function issuePreview ($app)
{
	$data = NULL;

	$pk = intval ($app->requestPath(3));
	if ($pk)
	{
		$tableIssues = $app->table ('wkf.core.issues');
		$item = $tableIssues->loadItem ($pk);
		if ($item)
		{
			$accessLevel = $tableIssues->checkAccessToDocument ($item);
			if ($accessLevel != 0)
				$data = ($item['body']) ? $item['body'] : $item['text'];
		}
	}

	if (!$data)
		$data = 'Náhled zprávy nelze zobrazit.';

	$response = new \e10\Response ($app);

	$response->setRawData($data);
	return $response;
}

