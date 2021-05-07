<?php

namespace e10doc\core\libs;


class DocsModes
{
	const dmScanToDocument = 'e10-base-scantodocument';

	static function on ($app, $mode, $docTableId, $docNdx, $key1, $key2)
	{
		$q[] = 'SELECT * FROM [e10_base_docsmodes] WHERE';
		array_push($q, ' [tableId] = %s', $docTableId, ' AND [recId] = %i', $docNdx);
		array_push($q, ' AND [mode] = %s', $mode);

		$row = $app->db()->query($q)->fetch ();
		if ($row)
		{
			$err = ['text' => 'error'];
			return $err;
		}

		$modeRec = [
			'tableId' => $docTableId, 'recId' => $docNdx, 'mode' => $mode, 'key1' => $key1, 'key2' => $key2,
			'user' => $app->user()->data ('id'), 'created' => new \DateTime()
		];

		$app->db()->query('INSERT INTO [e10_base_docsmodes]', $modeRec);

		return TRUE;
	}

	static function off ($app, $mode, $docTableId, $docNdx)
	{
		$q[] = 'DELETE FROM [e10_base_docsmodes] WHERE';
		array_push($q, ' [tableId] = %s', $docTableId, ' AND [recId] = %i', $docNdx);
		array_push($q, ' AND [mode] = %s', $mode);

		$app->db()->query($q);
	}

	static function get ($app, $mode, $key1 = FALSE, $key2 = FALSE)
	{
		$q[] = 'SELECT * FROM [e10_base_docsmodes] WHERE';
		array_push($q, ' [mode] = %s', $mode);

		$list = [];

		$rows = $app->db()->query($q);
		foreach ($rows as $r)
		{
			$list[] = $r->toArray();
		}

		if (count($list) === 0)
			return FALSE;

		return $list;
	}
}
