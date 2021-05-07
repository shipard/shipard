<?php

namespace lib\core\attachments\extractors;
use \lib\core\attachments\MetaData;


/**
 * Class Base
 * @package lib\core\attachments\extractors
 */
class Base extends MetaData
{
	function saveData ($metaDataType, $data)
	{
		$exist = $this->db()->query ('SELECT [ndx] FROM [e10_attachments_metaData]',
			' WHERE [attachment] = %i', $this->attRecData['ndx'], ' AND [metaDataType] = %i', $metaDataType)->fetch();
		if ($exist)
		{
			$item = ['data' => $data, 'created' => new \DateTime()];
			$this->db()->query ('UPDATE [e10_attachments_metaData] SET ', $item, ' WHERE [ndx] = %i', $exist['ndx']);
		}
		else
		{
			$item = [
				'attachment' => $this->attRecData['ndx'],
				'metaDataType' => $metaDataType,
				'data' => $data,
				'created' => new \DateTime()
			];
			$this->db()->query ('INSERT INTO [e10_attachments_metaData] ', $item);
		}
	}

	protected function applyUpdateValues($values)
	{
		if (!count($values))
			return;

		$this->db()->query('UPDATE [e10_attachments_files] SET ', $values, ' WHERE ndx = %i', $this->attRecData['ndx']);
		foreach ($values as $k => $v)
			$this->attRecData[$k] = $v;
	}

	public function run()
	{
	}
}
