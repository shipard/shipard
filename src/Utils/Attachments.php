<?php

namespace Shipard\Utils;


class Attachments
{
	static function copyAttachments ($app, $srcTableId, $srcRecId, $dstTableId, $dstRecId)
	{
		$sql = 'SELECT * FROM [e10_attachments_files] where [tableid] = %s AND [recid] = %i AND [deleted] = 0 ORDER BY defaultImage DESC, [order], name';
		$rows = $app->db->query ($sql, $srcTableId, $srcRecId);
		foreach ($rows as $r)
		{
			$newAtt = [
				'name' => $r['name'], 'perex' => $r['perex'], 'path' => $r['path'], 'filename' => $r['filename'],
				'filetype' => $r['filetype'], 'atttype' => $r['atttype'], 'defaultImage' => $r['defaultImage'],
				'order' => $r['order'], 'created' => new \DateTime(), 'deleted' => 0,
				'tableid' => $dstTableId, 'recid' => $dstRecId
			];

			if ($r['symlinkTo'])
				$newAtt['symlinkTo'] = $r['symlinkTo'];
			else
				$newAtt['symlinkTo'] = $r['ndx'];

			$app->db->query ('INSERT INTO [e10_attachments_files]', $newAtt);
		}
	}
}
