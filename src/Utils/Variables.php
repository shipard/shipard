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
 *
 *
 * <[docHead.dateBegin:dateFormat;Y-m-d]>
 * <[wo.text:substr;1-9]>
 * <[wo.text:between;textBegin;textEnd]>
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
    $formatParams = [];
    $coreId = substr ($varId, 2, -2);
    $varId = strchr($coreId, ':', TRUE);
    if ($varId === FALSE)
    {
      $varId = $coreId;
    }
    else
    {
      $formatStr = substr($coreId, strlen($varId) + 1);
      $formatParams = explode(';', $formatStr);
    }

    $value = $this->getItem($varId);
    if ($value === NULL)
      return 'NULL';

    if (count($formatParams) > 1)
    {
      $result = $value;
      while (count($formatParams))
      {
        $cmd = array_shift($formatParams);
        if ($cmd === 'dateFormat')
        {
          $dateFormat = array_shift($formatParams);
          if (Utils::dateIsValid($result))
          {
            $d = Utils::createDateTime($result);
            $result = $d->format($dateFormat);
          }
        }
        elseif ($cmd === 'between')
        {
          $startStr = array_shift($formatParams);
          $endStr = array_shift($formatParams);
          $result = trim($this->getStrBetween($result, $startStr, $endStr));
        }
      }
      return $result;
    }

    $format = array_pop($formatParams);

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

  function getStrBetween ($string, $start, $end = '')
  {
    if (strpos($string, $start) !== FALSE)
    {
      $startCharCount = strpos($string, $start) + strlen($start);
      $firstSubStr = substr($string, $startCharCount, strlen($string));
      $endCharCount = strpos($firstSubStr, $end);
      if ($endCharCount == 0)
      {
          $endCharCount = strlen($firstSubStr);
      }
      return substr($firstSubStr, 0, $endCharCount);
    }
    else
    {
      return '';
    }
  }
}