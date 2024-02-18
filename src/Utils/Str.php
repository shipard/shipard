<?php

namespace Shipard\Utils;


class Str
{
	static function cmpwws ($s1, $s2, $otherStrings = NULL)
	{
		$string1 = preg_replace('~\x{00a0}~', ' ', $s1);
		$string1 = preg_replace('/\s+/', '', $string1);
		$string2 = preg_replace('~\x{00a0}~', ' ', $s2);
		$string2 = preg_replace('/\s+/', '', $string2);

		if ($otherStrings)
		{
			$string1 = str_replace($otherStrings, '', $string1);
			$string2 = str_replace($otherStrings, '', $string2);
		}

		return self::strcasecmp ($string1, $string2);
	}

	static function strcasecmp ($target1, $target2)
	{
		return strcmp(mb_strtoupper($target1, 'UTF-8'), mb_strtoupper($target2, 'UTF-8'));
	}

	static function strlen ($string)
	{
		return mb_strlen($string, 'UTF-8');
	}

	static function tolower ($string)
	{
		return mb_strtolower($string, 'UTF-8');
	}

	static function toupper ($string)
	{
		return mb_strtoupper($string, 'UTF-8');
	}

	static function substr ($string, $start, $length = null)
	{
		return mb_substr($string, $start, $length, 'UTF-8');
	}

	static function upToLen ($str, $maxLen)
	{
		$len = mb_strlen($str, 'UTF-8');
		if ($len <= $maxLen)
			return $str;
		return mb_substr($str, 0, $maxLen, 'UTF-8');
	}

	static function setWidth ($str, $maxLen, $ch = ' ')
	{
		$len = mb_strlen($str, 'UTF-8');
		if ($len <= $maxLen)
			return $str.str_repeat ($ch, $maxLen - $len);
		return mb_substr($str, 0, $maxLen, 'UTF-8');
	}

	static function str_replace($needle, $replacement, $haystack)
	{
		return implode($replacement, mb_split($needle, $haystack));
	}

	static function strstr ($haystack, $needle)
	{
		return mb_strstr($haystack, $needle, NULL, 'UTF-8');
	}

	static function stristr ($haystack, $needle)
	{
		return mb_stristr($haystack, $needle, NULL, 'UTF-8');
	}

  static function strBetween ($string, $start, $end = '')
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

	static function strEnds ($haystack, $needle)
	{
		return (self::substr($haystack, -self::strlen($needle)) === $needle);
	}

	static function strEndsI ($haystack, $needle)
	{
		return (self::strcasecmp(self::substr($haystack, -self::strlen($needle)), $needle) === 0);
	}

	static function strStarts ($haystack, $needle)
	{
		return (self::substr($haystack, 0, self::strlen($needle)) === $needle);
	}

	static function strStartsI ($haystack, $needle)
	{
		return (self::strcasecmp(self::substr($haystack, 0, self::strlen($needle)), $needle) === 0);
	}

	static function trim ($string, $trim_chars = '\s')
	{
		return preg_replace('/^['.$trim_chars.']*(?U)(.*)['.$trim_chars.']*$/u', '\\1',$string);
	}

	static function toDb ($str, $maxLen = 0)
	{
		$s = self::str_replace(" ", ' ', $str);
		$s = self::trim($s);
		if ($maxLen)
			return self::upToLen($s, $maxLen);
		return $s;
	}

	static function scannerString ($s)
	{
		$transDiacritic = ['+'=>'1','ě'=>'2','š'=>'3','č'=>'4','ř'=>'5','ž'=>'6','ý'=>'7','á'=>'8','í'=>'9','é'=>'0'];
		$kv = strtr($s, $transDiacritic);
		return $kv;
	}
}

