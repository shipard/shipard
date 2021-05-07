<?php

namespace Shipard\Utils;


class Json
{
	static function lint ($data)
	{
		return json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	}

	static function decode ($data)
	{
		return json_decode($data, TRUE);
	}

	static function encode ($data)
	{
		return json_encode($data);
	}

	static function polish (&$data)
	{
		foreach ($data as $rowKey => $rowValue)
		{
			if ($rowValue instanceof \DateTime)
			{
				$date = $rowValue->format('Y-m-d');
				$time = $rowValue->format('H:i:s');
				if ($time === '00:00:00')
					$data[$rowKey] = $date;
				else
					$data[$rowKey] = $date.' '.$time;
			}
		}
	}
}
