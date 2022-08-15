<?php

namespace Shipard\Utils;
use Shipard\Base\Utility;


/**
 * <[wo.text]> <[docHead.dateBegin:Y-m-d]>
 * <[wo.text:1-9]>
 * <[wo.text:'ahoj'..;U;L]>
 * <[wo.text:U|L'ahoj'..]>
 *
 * <[wo.number:(+10);(*2)]>
 * <[wo.number:(+`wo.anotherNumber`)]>
 */


 /**
  * class Variables
  */
class Variables extends Utility
{
  var $srcData = NULL;

  public function setData(array $srcData)
  {
    $this->srcData = $srcData;
  }

  public function setDataItem($key, array $values)
  {
    $this->srcData[$key] = $values;
  }

  function resolve($str)
	{
    $res = preg_replace_callback('/<\[(.*?)]>/', function ($m) {
        return $this->resolveVariable($m[0]);
      }, $str);

		return $res;
	}

  protected function resolveVariable($varId)
  {
    $format = '';
    $coreId = substr ($varId, 2, -2);
    $varId = strchr($coreId, ':', TRUE);
    if ($varId === FALSE)
    {
      $varId = $coreId;
    }
    else
    {
      $format = substr($coreId, strlen($varId) + 1);
    }

    $value = $this->getItem($varId);
    if ($value === NULL)
      return 'NULL';

    if ($value instanceof \DateTimeInterface)
    {
      if ($format === '')
        return Utils::datef($value);

      return $value->format($format);
    }

    return strval($value);
  }

	private function getItem ($key, $defaultValue = NULL)
	{
		if (isset ($this->srcData [$key]))
			return $this->srcData [$key];

		$parts = explode ('.', $key);

		if (!count ($parts))
			return $defaultValue;

		$value = NULL;
		$top = $this->srcData;

    forEach ($parts as $p)
		{
			if (isset ($top [$p]))
			{
				$value = &$top [$p];
				$top = &$top [$p];
				continue;
			}
			return $defaultValue;
		}

		return $value;
	}
}