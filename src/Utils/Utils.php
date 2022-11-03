<?php

namespace Shipard\Utils;
use \Shipard\Utils\Str;
use \Shipard\Application\DataModel;


/**
 * class Utils
 */
class Utils
{
	static $dayShortcuts = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
	static $dayNames = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];
	static $monthNames = ['leden','únor','březen','duben','květen','červen', 'červenec','srpen','září','říjen','listopad','prosinec'];
	static $monthNamesForDate = ['ledna','února','března','dubna','května','června', 'července','srpna','září','října','listopadu','prosince'];
	static $monthSc2 = ['le','ún','bř','du','kv','čr', 'čc','sr','zá','ří','li','pr'];
	static $monthSc3 = ['led','úno','bře','dub','kvě','čer', 'čec','srp','zář','říj','lis','pro'];
	static $counter = 0;

	static $transDiacritic = [
		'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Ç'=>'C','È'=>'E',
		'É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ñ'=>'N',
		'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Ù'=>'U','Ú'=>'U',
		'Û'=>'U','Ü'=>'U','Ý'=>'Y','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
		'å'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i',
		'î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
		'ø'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','Ā'=>'A',
		'ā'=>'a','Ă'=>'A','ă'=>'a','Ą'=>'A','ą'=>'a','Ć'=>'C','ć'=>'c','Ĉ'=>'C',
		'ĉ'=>'c','Ċ'=>'C','ċ'=>'c','Č'=>'C','č'=>'c','Ď'=>'D','ď'=>'d','Đ'=>'D',
		'đ'=>'d','Ē'=>'E','ē'=>'e','Ĕ'=>'E','ĕ'=>'e','Ė'=>'E','ė'=>'e','Ę'=>'E',
		'ę'=>'e','Ě'=>'E','ě'=>'e','Ĝ'=>'G','ĝ'=>'g','Ğ'=>'G','ğ'=>'g','Ġ'=>'G',
		'ġ'=>'g','Ģ'=>'G','ģ'=>'g','Ĥ'=>'H','ĥ'=>'h','Ħ'=>'H','ħ'=>'h','Ĩ'=>'I',
		'ĩ'=>'i','Ī'=>'I','ī'=>'i','Ĭ'=>'I','ĭ'=>'i','Į'=>'I','į'=>'i','İ'=>'I',
		'ı'=>'i','Ĵ'=>'J','ĵ'=>'j','Ķ'=>'K','ķ'=>'k','Ĺ'=>'L','ĺ'=>'l','Ļ'=>'L',
		'ļ'=>'l','Ľ'=>'L','ľ'=>'l','Ŀ'=>'L','ŀ'=>'l','Ł'=>'L','ł'=>'l','Ń'=>'N',
		'ń'=>'n','Ņ'=>'N','ņ'=>'n','Ň'=>'N','ň'=>'n','ŉ'=>'n','Ō'=>'O','ō'=>'o',
		'Ŏ'=>'O','ŏ'=>'o','Ő'=>'O','ő'=>'o','Ŕ'=>'R','ŕ'=>'r','Ŗ'=>'R','ŗ'=>'r',
		'Ř'=>'R','ř'=>'r','Ś'=>'S','ś'=>'s','Ŝ'=>'S','ŝ'=>'s','Ş'=>'S','ş'=>'s',
		'Š'=>'S','š'=>'s','Ţ'=>'T','ţ'=>'t','Ť'=>'T','ť'=>'t','Ŧ'=>'T','ŧ'=>'t',
		'Ũ'=>'U','ũ'=>'u','Ū'=>'U','ū'=>'u','Ŭ'=>'U','ŭ'=>'u','Ů'=>'U','ů'=>'u',
		'Ű'=>'U','ű'=>'u','Ų'=>'U','ų'=>'u','Ŵ'=>'W','ŵ'=>'w','Ŷ'=>'Y','ŷ'=>'y',
		'Ÿ'=>'Y','Ź'=>'Z','ź'=>'z','Ż'=>'Z','ż'=>'z','Ž'=>'Z','ž'=>'z','ƀ'=>'b',
		'Ɓ'=>'B','Ƃ'=>'B','ƃ'=>'b','Ƈ'=>'C','ƈ'=>'c','Ɗ'=>'D','Ƌ'=>'D','ƌ'=>'d',
		'Ƒ'=>'F','ƒ'=>'f','Ɠ'=>'G','Ɨ'=>'I','Ƙ'=>'K','ƙ'=>'k','ƚ'=>'l','Ɲ'=>'N',
		'ƞ'=>'n','Ɵ'=>'O','Ơ'=>'O','ơ'=>'o','Ƥ'=>'P','ƥ'=>'p','ƫ'=>'t','Ƭ'=>'T',
		'ƭ'=>'t','Ʈ'=>'T','Ư'=>'U','ư'=>'u','Ʋ'=>'V','Ƴ'=>'Y','ƴ'=>'y','Ƶ'=>'Z',
		'ƶ'=>'z','ǅ'=>'D','ǈ'=>'L','ǋ'=>'N','Ǎ'=>'A','ǎ'=>'a','Ǐ'=>'I','ǐ'=>'i',
		'Ǒ'=>'O','ǒ'=>'o','Ǔ'=>'U','ǔ'=>'u','Ǖ'=>'U','ǖ'=>'u','Ǘ'=>'U','ǘ'=>'u',
		'Ǚ'=>'U','ǚ'=>'u','Ǜ'=>'U','ǜ'=>'u','Ǟ'=>'A','ǟ'=>'a','Ǡ'=>'A','ǡ'=>'a',
		'Ǥ'=>'G','ǥ'=>'g','Ǧ'=>'G','ǧ'=>'g','Ǩ'=>'K','ǩ'=>'k','Ǫ'=>'O','ǫ'=>'o',
		'Ǭ'=>'O','ǭ'=>'o','ǰ'=>'j','ǲ'=>'D','Ǵ'=>'G','ǵ'=>'g','Ǹ'=>'N','ǹ'=>'n',
		'Ǻ'=>'A','ǻ'=>'a','Ǿ'=>'O','ǿ'=>'o','Ȁ'=>'A','ȁ'=>'a','Ȃ'=>'A','ȃ'=>'a',
		'Ȅ'=>'E','ȅ'=>'e','Ȇ'=>'E','ȇ'=>'e','Ȉ'=>'I','ȉ'=>'i','Ȋ'=>'I','ȋ'=>'i',
		'Ȍ'=>'O','ȍ'=>'o','Ȏ'=>'O','ȏ'=>'o','Ȑ'=>'R','ȑ'=>'r','Ȓ'=>'R','ȓ'=>'r',
		'Ȕ'=>'U','ȕ'=>'u','Ȗ'=>'U','ȗ'=>'u','Ș'=>'S','ș'=>'s','Ț'=>'T','ț'=>'t',
		'Ȟ'=>'H','ȟ'=>'h','Ƞ'=>'N','ȡ'=>'d','Ȥ'=>'Z','ȥ'=>'z','Ȧ'=>'A','ȧ'=>'a',
		'Ȩ'=>'E','ȩ'=>'e','Ȫ'=>'O','ȫ'=>'o','Ȭ'=>'O','ȭ'=>'o','Ȯ'=>'O','ȯ'=>'o',
		'Ȱ'=>'O','ȱ'=>'o','Ȳ'=>'Y','ȳ'=>'y','ȴ'=>'l','ȵ'=>'n','ȶ'=>'t','ȷ'=>'j',
		'Ⱥ'=>'A','Ȼ'=>'C','ȼ'=>'c','Ƚ'=>'L','Ⱦ'=>'T','ȿ'=>'s','ɀ'=>'z','Ƀ'=>'B',
		'Ʉ'=>'U','Ɇ'=>'E','ɇ'=>'e','Ɉ'=>'J','ɉ'=>'j','ɋ'=>'q','Ɍ'=>'R','ɍ'=>'r',
		'Ɏ'=>'Y','ɏ'=>'y','ɓ'=>'b','ɕ'=>'c','ɖ'=>'d','ɗ'=>'d','ɟ'=>'j','ɠ'=>'g',
		'ɦ'=>'h','ɨ'=>'i','ɫ'=>'l','ɬ'=>'l','ɭ'=>'l','ɱ'=>'m','ɲ'=>'n','ɳ'=>'n',
		'ɵ'=>'o','ɼ'=>'r','ɽ'=>'r','ɾ'=>'r','ʂ'=>'s','ʄ'=>'j','ʈ'=>'t','ʉ'=>'u',
		'ʋ'=>'v','ʐ'=>'z','ʑ'=>'z','ʝ'=>'j','ʠ'=>'q','ͣ'=>'a','ͤ'=>'e','ͥ'=>'i',
		'ͦ'=>'o','ͧ'=>'u','ͨ'=>'c','ͩ'=>'d','ͪ'=>'h','ͫ'=>'m','ͬ'=>'r','ͭ'=>'t',
		'ͮ'=>'v','ͯ'=>'x','ᵢ'=>'i','ᵣ'=>'r','ᵤ'=>'u','ᵥ'=>'v','ᵬ'=>'b','ᵭ'=>'d',
		'ᵮ'=>'f','ᵯ'=>'m','ᵰ'=>'n','ᵱ'=>'p','ᵲ'=>'r','ᵳ'=>'r','ᵴ'=>'s','ᵵ'=>'t',
		'ᵶ'=>'z','ᵻ'=>'i','ᵽ'=>'p','ᵾ'=>'u','ᶀ'=>'b','ᶁ'=>'d','ᶂ'=>'f','ᶃ'=>'g',
		'ᶄ'=>'k','ᶅ'=>'l','ᶆ'=>'m','ᶇ'=>'n','ᶈ'=>'p','ᶉ'=>'r','ᶊ'=>'s','ᶌ'=>'v',
		'ᶍ'=>'x','ᶎ'=>'z','ᶏ'=>'a','ᶑ'=>'d','ᶒ'=>'e','ᶖ'=>'i','ᶙ'=>'u','᷊'=>'r',
		'ᷗ'=>'c','ᷚ'=>'g','ᷜ'=>'k','ᷝ'=>'l','ᷠ'=>'n','ᷣ'=>'r','ᷤ'=>'s','ᷦ'=>'z',
		'Ḁ'=>'A','ḁ'=>'a','Ḃ'=>'B','ḃ'=>'b','Ḅ'=>'B','ḅ'=>'b','Ḇ'=>'B','ḇ'=>'b',
		'Ḉ'=>'C','ḉ'=>'c','Ḋ'=>'D','ḋ'=>'d','Ḍ'=>'D','ḍ'=>'d','Ḏ'=>'D','ḏ'=>'d',
		'Ḑ'=>'D','ḑ'=>'d','Ḓ'=>'D','ḓ'=>'d','Ḕ'=>'E','ḕ'=>'e','Ḗ'=>'E','ḗ'=>'e',
		'Ḙ'=>'E','ḙ'=>'e','Ḛ'=>'E','ḛ'=>'e','Ḝ'=>'E','ḝ'=>'e','Ḟ'=>'F','ḟ'=>'f',
		'Ḡ'=>'G','ḡ'=>'g','Ḣ'=>'H','ḣ'=>'h','Ḥ'=>'H','ḥ'=>'h','Ḧ'=>'H','ḧ'=>'h',
		'Ḩ'=>'H','ḩ'=>'h','Ḫ'=>'H','ḫ'=>'h','Ḭ'=>'I','ḭ'=>'i','Ḯ'=>'I','ḯ'=>'i',
		'Ḱ'=>'K','ḱ'=>'k','Ḳ'=>'K','ḳ'=>'k','Ḵ'=>'K','ḵ'=>'k','Ḷ'=>'L','ḷ'=>'l',
		'Ḹ'=>'L','ḹ'=>'l','Ḻ'=>'L','ḻ'=>'l','Ḽ'=>'L','ḽ'=>'l','Ḿ'=>'M','ḿ'=>'m',
		'Ṁ'=>'M','ṁ'=>'m','Ṃ'=>'M','ṃ'=>'m','Ṅ'=>'N','ṅ'=>'n','Ṇ'=>'N','ṇ'=>'n',
		'Ṉ'=>'N','ṉ'=>'n','Ṋ'=>'N','ṋ'=>'n','Ṍ'=>'O','ṍ'=>'o','Ṏ'=>'O','ṏ'=>'o',
		'Ṑ'=>'O','ṑ'=>'o','Ṓ'=>'O','ṓ'=>'o','Ṕ'=>'P','ṕ'=>'p','Ṗ'=>'P','ṗ'=>'p',
		'Ṙ'=>'R','ṙ'=>'r','Ṛ'=>'R','ṛ'=>'r','Ṝ'=>'R','ṝ'=>'r','Ṟ'=>'R','ṟ'=>'r',
		'Ṡ'=>'S','ṡ'=>'s','Ṣ'=>'S','ṣ'=>'s','Ṥ'=>'S','ṥ'=>'s','Ṧ'=>'S','ṧ'=>'s',
		'Ṩ'=>'S','ṩ'=>'s','Ṫ'=>'T','ṫ'=>'t','Ṭ'=>'T','ṭ'=>'t','Ṯ'=>'T','ṯ'=>'t',
		'Ṱ'=>'T','ṱ'=>'t','Ṳ'=>'U','ṳ'=>'u','Ṵ'=>'U','ṵ'=>'u','Ṷ'=>'U','ṷ'=>'u',
		'Ṹ'=>'U','ṹ'=>'u','Ṻ'=>'U','ṻ'=>'u','Ṽ'=>'V','ṽ'=>'v','Ṿ'=>'V','ṿ'=>'v',
		'Ẁ'=>'W','ẁ'=>'w','Ẃ'=>'W','ẃ'=>'w','Ẅ'=>'W','ẅ'=>'w','Ẇ'=>'W','ẇ'=>'w',
		'Ẉ'=>'W','ẉ'=>'w','Ẋ'=>'X','ẋ'=>'x','Ẍ'=>'X','ẍ'=>'x','Ẏ'=>'Y','ẏ'=>'y',
		'Ẑ'=>'Z','ẑ'=>'z','Ẓ'=>'Z','ẓ'=>'z','Ẕ'=>'Z','ẕ'=>'z','ẖ'=>'h','ẗ'=>'t',
		'ẘ'=>'w','ẙ'=>'y','ẚ'=>'a','Ạ'=>'A','ạ'=>'a','Ả'=>'A','ả'=>'a','Ấ'=>'A',
		'ấ'=>'a','Ầ'=>'A','ầ'=>'a','Ẩ'=>'A','ẩ'=>'a','Ẫ'=>'A','ẫ'=>'a','Ậ'=>'A',
		'ậ'=>'a','Ắ'=>'A','ắ'=>'a','Ằ'=>'A','ằ'=>'a','Ẳ'=>'A','ẳ'=>'a','Ẵ'=>'A',
		'ẵ'=>'a','Ặ'=>'A','ặ'=>'a','Ẹ'=>'E','ẹ'=>'e','Ẻ'=>'E','ẻ'=>'e','Ẽ'=>'E',
		'ẽ'=>'e','Ế'=>'E','ế'=>'e','Ề'=>'E','ề'=>'e','Ể'=>'E','ể'=>'e','Ễ'=>'E',
		'ễ'=>'e','Ệ'=>'E','ệ'=>'e','Ỉ'=>'I','ỉ'=>'i','Ị'=>'I','ị'=>'i','Ọ'=>'O',
		'ọ'=>'o','Ỏ'=>'O','ỏ'=>'o','Ố'=>'O','ố'=>'o','Ồ'=>'O','ồ'=>'o','Ổ'=>'O',
		'ổ'=>'o','Ỗ'=>'O','ỗ'=>'o','Ộ'=>'O','ộ'=>'o','Ớ'=>'O','ớ'=>'o','Ờ'=>'O',
		'ờ'=>'o','Ở'=>'O','ở'=>'o','Ỡ'=>'O','ỡ'=>'o','Ợ'=>'O','ợ'=>'o','Ụ'=>'U',
		'ụ'=>'u','Ủ'=>'U','ủ'=>'u','Ứ'=>'U','ứ'=>'u','Ừ'=>'U','ừ'=>'u','Ử'=>'U',
		'ử'=>'u','Ữ'=>'U','ữ'=>'u','Ự'=>'U','ự'=>'u','Ỳ'=>'Y','ỳ'=>'y','Ỵ'=>'Y',
		'ỵ'=>'y','Ỷ'=>'Y','ỷ'=>'y','Ỹ'=>'Y','ỹ'=>'y','Ỿ'=>'Y','ỿ'=>'y','ⁱ'=>'i',
		'ⁿ'=>'n','ₐ'=>'a','ₑ'=>'e','ₒ'=>'o','ₓ'=>'x','⒜'=>'a','⒝'=>'b','⒞'=>'c',
		'⒟'=>'d','⒠'=>'e','⒡'=>'f','⒢'=>'g','⒣'=>'h','⒤'=>'i','⒥'=>'j','⒦'=>'k',
		'⒧'=>'l','⒨'=>'m','⒩'=>'n','⒪'=>'o','⒫'=>'p','⒬'=>'q','⒭'=>'r','⒮'=>'s',
		'⒯'=>'t','⒰'=>'u','⒱'=>'v','⒲'=>'w','⒳'=>'x','⒴'=>'y','⒵'=>'z','Ⓐ'=>'A',
		'Ⓑ'=>'B','Ⓒ'=>'C','Ⓓ'=>'D','Ⓔ'=>'E','Ⓕ'=>'F','Ⓖ'=>'G','Ⓗ'=>'H','Ⓘ'=>'I',
		'Ⓙ'=>'J','Ⓚ'=>'K','Ⓛ'=>'L','Ⓜ'=>'M','Ⓝ'=>'N','Ⓞ'=>'O','Ⓟ'=>'P','Ⓠ'=>'Q',
		'Ⓡ'=>'R','Ⓢ'=>'S','Ⓣ'=>'T','Ⓤ'=>'U','Ⓥ'=>'V','Ⓦ'=>'W','Ⓧ'=>'X','Ⓨ'=>'Y',
		'Ⓩ'=>'Z','ⓐ'=>'a','ⓑ'=>'b','ⓒ'=>'c','ⓓ'=>'d','ⓔ'=>'e','ⓕ'=>'f','ⓖ'=>'g',
		'ⓗ'=>'h','ⓘ'=>'i','ⓙ'=>'j','ⓚ'=>'k','ⓛ'=>'l','ⓜ'=>'m','ⓝ'=>'n','ⓞ'=>'o',
		'ⓟ'=>'p','ⓠ'=>'q','ⓡ'=>'r','ⓢ'=>'s','ⓣ'=>'t','ⓤ'=>'u','ⓥ'=>'v','ⓦ'=>'w',
		'ⓧ'=>'x','ⓨ'=>'y','ⓩ'=>'z','Ⱡ'=>'L','ⱡ'=>'l','Ɫ'=>'L','Ᵽ'=>'P','Ɽ'=>'R',
		'ⱥ'=>'a','ⱦ'=>'t','Ⱨ'=>'H','ⱨ'=>'h','Ⱪ'=>'K','ⱪ'=>'k','Ⱬ'=>'Z','ⱬ'=>'z',
		'Ɱ'=>'M','ⱱ'=>'v','Ⱳ'=>'W','ⱳ'=>'w','ⱴ'=>'v','ⱸ'=>'e','ⱺ'=>'o','ⱼ'=>'j',
		'Ꝁ'=>'K','ꝁ'=>'k','Ꝃ'=>'K','ꝃ'=>'k','Ꝅ'=>'K','ꝅ'=>'k','Ꝉ'=>'L','ꝉ'=>'l',
		'Ꝋ'=>'O','ꝋ'=>'o','Ꝍ'=>'O','ꝍ'=>'o','Ꝑ'=>'P','ꝑ'=>'p','Ꝓ'=>'P','ꝓ'=>'p',
		'Ꝕ'=>'P','ꝕ'=>'p','Ꝗ'=>'Q','ꝗ'=>'q','Ꝙ'=>'Q','ꝙ'=>'q','Ꝛ'=>'R','ꝛ'=>'r',
		'Ꝟ'=>'V','ꝟ'=>'v','Ａ'=>'A','Ｂ'=>'B','Ｃ'=>'C','Ｄ'=>'D','Ｅ'=>'E','Ｆ'=>'F',
		'Ｇ'=>'G','Ｈ'=>'H','Ｉ'=>'I','Ｊ'=>'J','Ｋ'=>'K','Ｌ'=>'L','Ｍ'=>'M','Ｎ'=>'N',
		'Ｏ'=>'O','Ｐ'=>'P','Ｑ'=>'Q','Ｒ'=>'R','Ｓ'=>'S','Ｔ'=>'T','Ｕ'=>'U','Ｖ'=>'V',
		'Ｗ'=>'W','Ｘ'=>'X','Ｙ'=>'Y','Ｚ'=>'Z','ａ'=>'a','ｂ'=>'b','ｃ'=>'c','ｄ'=>'d',
		'ｅ'=>'e','ｆ'=>'f','ｇ'=>'g','ｈ'=>'h','ｉ'=>'i','ｊ'=>'j','ｋ'=>'k','ｌ'=>'l',
		'ｍ'=>'m','ｎ'=>'n','ｏ'=>'o','ｐ'=>'p','ｑ'=>'q','ｒ'=>'r','ｓ'=>'s','ｔ'=>'t',
		'ｕ'=>'u','ｖ'=>'v','ｗ'=>'w','ｘ'=>'x','ｙ'=>'y','ｚ'=>'z',];

	static $todayClass = NULL;

	static function addToArray (&$dest, $source, $key, $defaultValue = NULL)
	{
		if (isset($source [$key]))
			$dest [$key] = $source [$key];
		else
		if ($defaultValue !== NULL)
			$dest [$key] = $defaultValue;
	}

	static function addToTree (&$dest, $key, $value)
	{
		$parts = explode ('.', $key);
		$lastPart = array_pop ($parts);
		$top = &$dest;
		forEach ($parts as $p)
		{
			if (isset ($top [$p]))
			{
				$top = &$top [$p];
				continue;
			}
			$top [$p] = array();
			$top = &$top [$p];
		}
		$top[$lastPart] = $value;
	}

	static function replaceAtTree (&$dest, $key, $value)
	{
		$parts = explode ('.', $key);

		$lastPart = str_replace ('-', '.', array_pop ($parts));
		$top = &$dest;
		forEach ($parts as $p)
		{
			$pp = str_replace ('-', '.', $p);
			if (!isset ($top [$pp]))
				return;
			$top = &$top [$pp];
		}
		$top[$lastPart] = $value;
	}


	static function cfgItem ($cfg, $key, $defaultValue = NULL)
	{
		if (isset ($cfg [$key]))
			return $cfg [$key];

		$parts = explode ('.', $key);
		if (!count ($parts))
			return $defaultValue;

		$value = NULL;
		$top = $cfg;
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

	static function cfgInfo ($app, $key, $defaultValue = '')
	{
		if (substr($key, 0, 8) === 'cfgItem.')
			return $app->cfgItem (substr($key, 8), $defaultValue);

		if (substr($key, 0, 6) === 'owner.')
		{
			$ownerNdx = intval($app->cfgItem ('options.core.ownerPerson', 0));
			if ($ownerNdx)
			{
				$tablePersons = $app->table ('e10.persons.persons');
				$ownerInfo = $tablePersons->loadDocument ($ownerNdx);
				return utils::cfgItem($ownerInfo, substr ($key, 6), $defaultValue);
			}
		}
		return $defaultValue;
	}

	static function enabledCfgItem ($app, $item, $defaultValue = 0)
	{
		if (isset ($item['enabledCfgItem']))
		{
			if (is_array($item['enabledCfgItem']))
			{
				$any = FALSE;
				foreach ($item['enabledCfgItem'] as $k => $v)
				{
					if ($app->cfgItem($k, $defaultValue) == $v)
						$any = TRUE;
				}
				if (!$any)
					return FALSE;
			}
			else
			{
				if ($app->cfgItem($item['enabledCfgItem'], $defaultValue) == 0)
					return FALSE;
			}
		}

		if (isset ($item['disabledCfgItem']))
		{
			if (is_array($item['disabledCfgItem']))
			{
				foreach ($item['disabledCfgItem'] as $k => $v)
					if ($app->cfgItem($k, $defaultValue) == $v)
						return FALSE;
			}
			else
			{
				if ($app->cfgItem($item['disabledCfgItem'], $defaultValue) == 1)
					return FALSE;
			}
		}

		if (isset($item['allowAccessClass']))
		{
			$o = $app->createObject($item['allowAccessClass']);
			if ($o)
			{
				if ($o->allowAccess($item))
					return TRUE;
			}
			return FALSE;
		}

		return TRUE;
	}

	static function createDateTime ($d, $isTimestamp = FALSE)
	{
		if ($d instanceof \DateTimeInterface)
			return new \DateTime ($isTimestamp ? $d->format('Y-m-d H:i:s') : $d->format('Y-m-d'));
		if (is_string($d))
		{
			if ($d == '0000-00-00' || $d == '')
				return NULL;
			return new \DateTime ($d);
		}
		if (isset ($d ['date']) && is_string($d ['date']))
		{
			if ($isTimestamp)
				return new \DateTime ($d['date']);
			return new \DateTime (substr ($d['date'], 0, 10));
		}
		return NULL;
	}

	static function createDateTimeFromTime ($day, $time)
	{
		if (is_string($day))
			$timeStampStr = utils::createDateTime($day)->format ('Y-m-d ');
		else
			$timeStampStr = $day->format ('Y-m-d ');

		$timeParts = explode (':', $time);
		$timePartsRemain = 3;
		if (isset ($timeParts[0]) && $timeParts[0] >= 0 && $timeParts[0] <= 23)
		{
			$timeStampStr .= sprintf ('%02d', intval ($timeParts[0]));
			$timePartsRemain--;
			if (isset ($timeParts[1]) && $timeParts[1] >= 0 && $timeParts[0] <= 59)
			{
				$timeStampStr .= sprintf (':%02d', intval ($timeParts[1]));
				$timePartsRemain--;
			}
		}

		for ($i = 0; $i < $timePartsRemain; $i++)
			$timeStampStr .= ':00';

		$timestamp = new \DateTime($timeStampStr);
		return $timestamp;
	}

	static function timeToMinutes ($time)
	{
		if ($time == '' || !$time)
			return 0;
		$timeParts = preg_split ('/[\:,\.,\-]/', $time);
		$len = intval ($timeParts[0]) * 60 + intval ($timeParts[1]);
		return $len;
	}

	static function minutesToTime ($len, $twoDigits = FALSE)
	{
		$h = intval($len / 60);
		$m = $len % 60;
		if ($twoDigits)
			$t = sprintf('%02d:%02d', $h, $m);
		else
			$t = sprintf('%d:%02d', $h, $m);
		return $t;
	}

	static function createRecId($recData, $formula)
	{
		$now = new \DateTime();
		$ndx = isset($recData['ndx']) ? $recData['ndx'] : 0;

		$year2 = $now->format ('y');
		$year4 = $now->format ('Y');
		$month = $now->format ('m');

		$id = '';

		if ($formula[0] == '!')
		{ // !06H
			$len = intval(substr($formula, 1, 2));
			if (!$len)
				$len = 6;
			$format = $formula[3];
			if ($format === 'D')
			{
				$id = base_convert($ndx, 10, 16);
				$id .= mt_rand(10, 9999);
				while(strlen($id) < $len)
					$id .= mt_rand(0, 9);
			}
			elseif ($format === 'H' || $format === 'h')
			{
				$id = base_convert($ndx, 10, 16);
				$id .= strval(base_convert(mt_rand(10, 9999), 10, 16));
				while(strlen($id) < $len)
					$id .= strval(base_convert(mt_rand(0, 15), 10, 16));
				if ($format === 'H')
					$id = strtoupper($id);
			}
			elseif ($format === 'Z' || $format === 'z')
			{
				$id = base_convert($ndx, 10, 36);
				$id .= strval(base_convert(mt_rand(1, 999), 10, 36));
				while(strlen($id) < $len)
					$id .= strval(base_convert(mt_rand(0, 35), 10, 36));

				$id = strtr($id, ['0' => '9', 'o' => 'w']);

				if ($format === 'Z')
					$id = strtoupper($id);
			}
		}
		else
		{
			$rep = [
				'%Q' => strval(base_convert(mt_rand(100000000, 9999999999), 10, 36)),
				'%q' => strval(base_convert(mt_rand(10000, 999999), 10, 36)),
				'%X' => strval(base_convert(mt_rand(100000000, 9999999999), 10, 16)),
				'%x' => strval(base_convert(mt_rand(10000, 999999), 10, 16)),
				'%n' => strval($ndx),
				'%N' => base_convert($ndx, 10, 16),
				'%Y' => $year4,
				'%y' => $year2,
				'%M' => $month,

				'%Z' => strtoupper(base_convert($ndx, 10, 36)),
				'%R' => strtoupper(strval(base_convert(mt_rand(10000, 99999), 10, 36))),

				'%2' => sprintf('%02d', $ndx), '%3' => sprintf('%03d', $ndx), '%4' => sprintf('%04d', $ndx), '%5' => sprintf('%05d', $ndx)
			];
			$id = strtr($formula, $rep);
		}

		return $id;
	}

	static function createToken($len, $passwdMode = FALSE, $safeMode = FALSE)
	{
		$passwdChars = '_.,%@^';
		$id = '';
		while (1)
		{
			$part = base_convert(strval(mt_rand (1000000, 40000000000)), 10, 36);
			for ($i = 0; $i < strlen($part); $i++)
			{
				if ($passwdMode)
				{
					$q = rand(0, 100);
					if ($q < 50)
						$id .= strtoupper(strval($part[$i]));
					elseif ($q > 80 && $i > 1)
						$id .= $passwdChars[mt_rand(0, 5)];
					else
						$id .= $part[$i];
				}
				else
				{
					if ($safeMode)
					{
					 	if ($part[$i] === '0' || $part[$i] === 'o' || $part[$i] === 'i' || $part[$i] === 'l' || $part[$i] === 'j')
							continue;
						$id .= $part[$i];
					}
					else
						$id .= $part[$i];
				}
				if (strlen ($id) === $len)
					return $id;
			}
		}

		return '';
	}

	static function guidv4()
	{
		$data = random_bytes(16);

		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	static function elementId($prefix)
	{
		if (!self::$counter)
			self::$counter = mt_rand(100000, 9876543);
		$id = $prefix.'-'.self::$counter;
		self::$counter++;
		return $id;
	}

	static function cutColumns (array $srcData, &$destData, array $srcHeader, &$destHeader, &$legend, &$cutedTotals, $firstCol, $maxCnt)
	{
		$totals = [];

		foreach ($srcData as $rowId => $row)
		{
			$destData[$rowId] = [];
			$totals[$rowId] = 0.0;
			$colIdx = 0;
			foreach ($srcHeader as $cellId => $headerValue)
			{
				if (!isset($cutedTotals[$cellId]))
					$cutedTotals[$cellId] = 0.0;

				if ($colIdx < $firstCol+$maxCnt)
				{
					if (isset($srcData[$rowId][$cellId]))
					{
						$destData[$rowId][$cellId] = $srcData[$rowId][$cellId];
						$cutedTotals[$cellId] += floatval($srcData[$rowId][$cellId]);
					}
				}
				else
				{
					if (isset($srcData[$rowId][$cellId]))
					{
						$totals[$rowId] += $srcData[$rowId][$cellId];
						if (isset($cutedTotals['cutTotal']))
							$cutedTotals['cutTotal'] += floatval($srcData[$rowId][$cellId]);
						else
							$cutedTotals['cutTotal'] = floatval($srcData[$rowId][$cellId]);
					}
				}
				$colIdx++;
			}
		}

		$colIdx = 0;
		foreach ($srcHeader as $colId => $colValue)
		{
			if ($colIdx < $firstCol+$maxCnt)
				$destHeader[$colId] = $colValue;
			else
			if (!isset ($destHeader['cutTotal']))
				$destHeader['cutTotal'] = '+Ostatní';

			$colIdx++;
		}

		$colIdx = 0;
		foreach ($destHeader as $colId => $colValue)
		{
			if ($colIdx >= $firstCol)
			{
				$legend[$colId] = $colValue;
			}

			$colIdx++;
		}

		foreach ($totals as $rowId => $sum)
		{
			$destData[$rowId]['cutTotal'] = $sum;
		}
	}

	static function cutRows (array $srcData, &$destData, $sumColumns, &$cutedSum, $maxRows)
	{
		$totals = [];

		$rowNdx = 0;
		foreach ($srcData as $rowId => $row)
		{
			if ($rowNdx < $maxRows)
			{
				$destData[$rowId] = array_merge ([], $row);
			}
			else
			{
				foreach ($sumColumns as $colId)
				{
					if (!isset ($cutedSum[$colId]))
						$cutedSum[$colId] = 0.0;
					$cutedSum[$colId] += $row[$colId];
				}
			}
			$rowNdx++;
		}
	}

	static function datef ($d, $format = '%D')
	{
		if (!$d || $d === "0000-00-00")
			return "";

		if (is_string($d))
			$d = new \DateTime ($d);

		$res = $format;
		$date = date_parse ($d->format ('Y-m-d H:i:s'));
		$today = getdate ();
		$yesterday = getdate (time() - 24 * 60 * 60);

		$txtTime = "";
		$txtDate = "";
		$txtDateShort = "";

		if (($today ['year'] == $date ['year']) && ($today ['mon'] == $date ['month']) && ($today ['mday'] == $date ['day']))
		{
			//$res = "dnes, " . $date ['hour'] . ":" . sprintf ("%02d", $date ['minute']);
			$txtDate = $txtDateShort = 'dnes';
			$txtTime = $date ['hour'] . ":" . sprintf ("%02d", $date ['minute']);
		}
		else
		if (($yesterday ['year'] == $date ['year']) && ($yesterday ['mon'] == $date ['month']) && ($yesterday ['mday'] == $date ['day']))
		{
			//$res = "včera, " . $date ['hour'] . ":" . sprintf ("%02d", $date ['minute']);
			$txtDate = $txtDateShort = 'včera';
			$txtTime = $date ['hour'] . ":" . sprintf ("%02d", $date ['minute']);
		}
		else
		{
			//$res = $date ['day'] . "." . $date ['month'] . "." . $date ['year'] . ", " . $date ['hour'] . ":" . sprintf ("%02d", $date ['minute']);
			$txtDate = $date ['day'] . "." . $date ['month'] . "." . $date ['year'];
			$txtTime = $date ['hour'] . ":" . sprintf ("%02d", $date ['minute']);

			if ($today ['year'] === $date ['year'])
				$txtDateShort = $date ['day'] . "." . $date ['month'].'.';
			else
				$txtDateShort = $date ['day'] . "." . $date ['month'] . "." . $date ['year'];
		}

		$smartTxtTime = ($txtTime === '0:00') ? '' : ', '.$txtTime;

		$res = str_replace ("%D", $txtDate, $res);
		$res = str_replace ("%S", $txtDateShort, $res);
		$res = str_replace ("%T", $txtTime, $res);
		$res = str_replace ("%t", $smartTxtTime, $res);
		$res = str_replace ("%f", $d->format ('H:i:s'), $res);
		$res = str_replace ("%d", $d->format ('d.m.Y'), $res);
		$res = str_replace ("%x", $d->format ('d.m.Y H:i:s'), $res);
		$res = str_replace ("%s", $d->format ('d.m.y'), $res);
		$res = str_replace ("%k", $d->format ('d.m'), $res);
		$res = str_replace ("%Y", $d->format ('Y'), $res);
		$res = str_replace ("%n", self::$dayShortcuts[$d->format ('N') - 1], $res);

		return $res;
	}

	static function dateage ($d, $format = '%A')
	{
		if (!$d)
			return '';

		$today = utils::today();
		$ds = $d->format('Ymd');
		$ii = $d->diff($today);
		$interval = $ii->days;

		if ($interval < 4)
		{
			if ($ds === date('Ymd'))
				$interval = 0;
			else
			if ($ds === date('Ymd', strtotime('-1 day')))
				$interval = 1;
		}

		$time = '';
		if ($interval < 2)
			$time = $d->format ('H:i');

		switch ($interval)
		{
			case 0: $age = 'dnes'.(($time === '00:00') ? '' : ', '.$time); break;
			case 1: $age = (($today < $d) ? 'zítra' : 'včera') . (($time === '00:00') ? '' : ', '.$time); break;
			default: $age = (($today < $d) ? 'za ': 'před ') .$interval.' dny'; break;
		}

		$res = str_replace ('%A', $age, $format);
		return $res;
	}

	static function dateage2 ($d)
	{
		if (!$d)
			return '';

		$today = utils::today();
		$ii = $d->diff($today);
		$days = $ii->days;

		if ($days < 2)
			$age = 'den';
		elseif ($days < 9)
			$age = 'týden';
		elseif ($days < 27)
			$age = intval($days / 7).' týdny';
		elseif ($days < 45)
			$age = 'měsíc';
		elseif ($days < 340)
			$age = $ii->m.' měsíc(ů)';
		elseif ($days < 390)
			$age = 'rok';
		else
			$age = ($ii->y).' roky a '.($ii->y).' měsíců';

		return $age;
	}

	static function dateBeginEnd ($begin, $end)
	{
		if (utils::dateIsBlank($begin) && utils::dateIsBlank($end))
			return '';

		$txt = '';
		$len = '';

		if ($begin)
			$txt .= utils::datef($begin, '%D, %T');
		if ($end)
			$txt .= ' - '.utils::datef($end, '%T');

		if ($begin && $end)
		{
			$ii = utils::createDateTime($begin, TRUE)->diff(utils::createDateTime($end, TRUE));

			if ($ii->m < 60 && $ii->h === 0)
				$len = $ii->format ('(%i min)');
			else
			if ($ii->d === 0)
				$len = $ii->format ('(%hh %I min)');
			else
			if ($ii->m === 0 )
				$len = $ii->format ('(%dd %hh %I min)');
			else
			if ($ii->y === 0 )
				$len = $ii->format ('(%M měs %dd %hh %I min)');
		}

		$res = ['text' => $txt];
		if ($len != '')
			$res['suffix'] = $len;

		return $res;
	}

	static function dateFromTo ($begin, $end, $date)
	{
		if (utils::dateIsBlank($begin) && utils::dateIsBlank($end))
			return '';

		$txt = '';

		$dayBegin = utils::dateIsBlank($begin) ? '' : $begin->format('Y-m-d');
		$dayEnd = utils::dateIsBlank($end) ? '' :$end->format('Y-m-d');

		if (!$date)
		{
			if ($dayBegin !== '')
			$txt .= utils::datef($begin, '%D%t');

			$txt .= ' ►';

			if ($dayEnd === $dayBegin)
				$txt .= utils::datef($end, '%T');
			elseif ($dayEnd !== '')
				$txt .= ' '.utils::datef($end, '%D%t');
			return $txt;
		}

		$dayThis = $date->format('Y-m-d');

		if ($dayEnd > $dayBegin && $dayThis !== $dayBegin && $dayThis !== $dayEnd)
			return '⇋';

		if ($begin)
		{
			$txt .= utils::datef($begin, '%T');
		}
		if ($end)
		{
			if ($dayEnd > $dayBegin && $dayThis === $dayEnd)
				$txt = '► ' . utils::datef($end, '%T');
			else
			{
				if ($dayEnd > $dayBegin && $dayThis === $dayBegin)
					$txt .= ' ►';
				else
					$txt .= ' - ' . utils::datef($end, '%T');
			}
		}

		return $txt;
	}

	static function dateDiff ($dateBegin, $dateEnd)
	{
		if (utils::dateIsBlank($dateBegin) || utils::dateIsBlank($dateEnd))
			return 0;
		$diff = $dateBegin->diff($dateEnd);
		$days = (int)$diff->format("%r%a");
		return $days;
	}

	static function dateDiffMinutes ($dateBegin, $dateEnd)
	{
		$diff = $dateBegin->diff($dateEnd);
		$minutes = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;
		return $minutes;
	}

	static function dateDiffSeconds ($dateBegin, $dateEnd)
	{
		$diff = $dateBegin->diff($dateEnd);
		$minutes = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;
		return $minutes * 60;
	}

	static function dateDiffShort ($dateBegin, $dateEnd)
	{
		$ii = $dateBegin->diff($dateEnd);
		$len = '';

		if ($ii->m < 60 && $ii->h === 0)
			$len = $ii->format ('%i min');
		elseif ($ii->d === 0)
			$len = $ii->format ('%hh %I min');
		elseif ($ii->m === 0 )
			$len = $ii->format ('%dd %hh %I min');
		elseif ($ii->y === 0 )
			$len = $ii->format ('%M měs %dd %hh %I min');

		return $len;
	}

	static function datePeriodQuery ($column, &$q, $value, $tablePrefix = '')
	{
		if (isset ($value[$column]['from']) && $value[$column]['from'] != '')
			array_push ($q, " AND cast({$tablePrefix}[$column] as date) >= %d", date_create_from_format ('d.m.Y', $value[$column]['from']));
		if (isset ($value[$column]['to']) && $value[$column]['to'] != '')
			array_push ($q, " AND cast({$tablePrefix}[$column] as date) <= %d", date_create_from_format ('d.m.Y', $value[$column]['to']));
	}

	static function timef ($minutes)
	{
		$h = intval ($minutes / 60);
		$m = $minutes % 60;
		return sprintf ('%d:%02d', $h, $m);
	}

	static function getPrintValues ($table, $item)
	{
		$pi = array ();
		forEach ($item as $key => $value)
		{
			$col = $table->column ($key);
			if (!$col)
			{
				$pi [$key] = $value;
				continue;
			}

			switch ($col ['type'])
			{
				case DataModel::ctEnumString:
				case DataModel::ctEnumInt:
					$values = $table->columnInfoEnum ($key, 'cfgPrint');
					if (isset ($values [$value]))
						$pi [$key] = $values [$value];
					else
						$pi [$key] = '';
					break;
				case DataModel::ctMoney:
					$pi [$key] = \E10\nf ($value, 2);
					break;
				default:
					$pi [$key] = $value;
					break;
			}
		}
		return $pi;
	}

	static function es ($s)
	{
		return htmlspecialchars ($s);
	}

	static function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return FALSE;
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return FALSE;
			return $cfg;
		}
		return FALSE;
	}

	static function loadApiFile ($url, $apiKey)
	{
		$opts = array(
				'http'=>array(
						'timeout' => 30, 'method'=>"GET",
						'header'=>
								"e10-api-key: " . $apiKey . "\r\n".
								"e10-device-id: " . utils::machineDeviceId (). "\r\n".
								"Connection: close\r\n"
				)
		);
		$context = stream_context_create($opts);
		$resultCode = file_get_contents ($url, FALSE, $context);
		$resultData = json_decode ($resultCode, TRUE);
		return $resultData;
	}

	static function http_post ($url, $data)
	{
		$data_len = strlen ($data);
		$context = stream_context_create (
			[
				'http'=> [
					'method'=>'POST',
					'header'=>"Content-type: text/plain\r\nConnection: close\r\nContent-Length: $data_len\r\n",
					'content'=>$data,
					'timeout' => 30
				]
			]
		);

		$result = @file_get_contents ($url, FALSE, $context);
		$responseHeaders = $http_response_header ?? [];
		return ['content'=> $result, 'headers'=> $responseHeaders];
	}

	static function http_get ($url)
	{
		$context = stream_context_create (
			[
				'http'=> [
					'method'=>'GET',
					'timeout' => 30
				]
			]
		);

		$result = @file_get_contents ($url, FALSE, $context);
		$responseHeaders = $http_response_header;
		return ['content'=> $result, 'headers'=> $responseHeaders];
	}

	static function elementActionParams ($p, &$class)
	{
		$t = '';
		if (isset ($p['addParams']))
			$t .= " data-addparams='{$p['addParams']}'";
		if (isset ($p['pk']))
			$t .= " data-pk='{$p['pk']}'";
		if (isset ($p['data-srcobjecttype']))
			$t .= " data-srcobjecttype='{$p['data-srcobjecttype']}'";
		if (isset ($p['data-srcobjectid']))
			$t .= " data-srcobjectid='{$p['data-srcobjectid']}'";

		$linkClass = 'e10-document-trigger';
		if (isset ($p['actionClass']))
			$linkClass .= ' '.$p['actionClass'];

		$class .= ' '.$linkClass;
		$t .= " data-action='{$p['docAction']}'";
		if (isset($p['table']))
			$t .= " data-table='{$p['table']}'";
		if (isset($p['data-table']))
			$t .= " data-table='{$p['data-table']}'";

		return $t;
	}

	static function dataAttrs ($item)
	{
		$c = '';
		if (isset($item['data']))
		{
			foreach ($item['data'] as $i => $v)
				$c .= ' data-'.$i.'=\''.utils::es($v).'\'';
		}
		return $c;
	}

	static function safeChars ($string, $lightMode = FALSE, $keepSpaces = FALSE)
	{
		if ($lightMode)
			return str_replace ([':', '?', '/', '*', '"', '\'', '[', ']', ',', '#'], '-', trim($string));

		$string = strtr( $string, self::$transDiacritic);
		$string = preg_replace(array('~[^0-9a-z_\-\.]~i', '~[ -]+~'), ' ', $string);

		if ($keepSpaces)
			return $string;

		return str_replace(' ', '-', $string);
	}

	static function searchArray ($array, $searchBy, $searchWhat)
	{
		forEach ($array as &$item)
		{
			if (isset ($item [$searchBy]) && $item [$searchBy] == $searchWhat)
				return $item;
		}

		return NULL;
	} // searchArray

	static function copy_r ($path, $dest, $useSymlinks = false)
	{
		if (is_dir ($path))
		{
			@mkdir ($dest);
			$objects = scandir($path);
			if (sizeof ($objects) > 0)
			{
				foreach( $objects as $file )
				{
					if( $file == "." || $file == ".." )
						continue;
					if (is_dir ($path.DIRECTORY_SEPARATOR.$file))
					{
						utils::copy_r ($path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file, $useSymlinks);
					}
					else
					{
						if ($useSymlinks)
							symlink ($path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file);
						else
							copy ($path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file);
					}
				}
			}
			return true;
		}
		elseif (is_file ($path))
		{
			if ($useSymlinks)
				return symlink ($path, $dest);
			return copy ($path, $dest);
		}
		else
		{
			return false;
		}
	}

	static function dateIsBlank ($d)
	{
		if ((!isset ($d)) || ($d == NULL) || ($d == '0000-00-00') || ($d === '0000-00-00 00:00:00'))
			return TRUE;
		return FALSE;
	}

	static function dateIsValid($date, $format = 'Y-m-d')
	{
		$d = \DateTime::createFromFormat($format, $date);
		return $d && $d->format($format) === $date;
	}

	static function nf($number, $decimals=0)
	{
		return number_format($number, $decimals, ',', ' ');
	}

	static function nfu($number, $minPrecision = 0, $maxPrecision = 0)
	{
		$np = explode ('.', strval($number));
		$n = '';
		if ($np[0] === '-0')
			$n .= '-';
		$n .= self::nf($np[0]);
		if (count($np) > 1)
		{
			$dp = $np[1];
			if ($minPrecision && strlen($dp) < $minPrecision)
				$dp .= str_repeat('0', $minPrecision - strlen($dp));
			$n .= ',' . $dp;
		}
		else
			if ($minPrecision)
				$n .= ','.str_repeat('0', $minPrecision);

		return $n;
	}

	static function memf($bytes, $precision = 1)
	{
		if ($bytes == 0)
			return '';
		$unit = ['B','KB','MB','GB','TB','PB','EB'];
		return @round($bytes / pow(1024, ($i = floor(log($bytes, 1024)))), $precision).' '.$unit[$i];
	}

	static function snf($bytes, $precision = 1)
	{
		if ($bytes == 0)
			return '0';
		$unit = ['','K','M','G','T','P','E'];
		return @round($bytes / pow(1000, ($i = floor(log($bytes, 1000)))), $precision).' '.$unit[$i];
	}

	static function icon ($i)
	{
		if (strstr ($i, 'icon-') !== FALSE)
			return 'fas fa-'.substr($i, 5);

		return 'appIcon-'.$i;
	}

	static function json_lint ($json) {

		$result      = '';
		$pos         = 0;
		$strLen      = strlen($json);
		$indentStr   = '  ';
		$newLine     = "\n";
		$prevChar    = '';
		$outOfQuotes = true;

		for ($i=0; $i<=$strLen; $i++) {

			// Grab the next character in the string.
			$char = substr($json, $i, 1);

			// Are we inside a quoted string?
			if ($char == '"' && $prevChar != '\\') {
				$outOfQuotes = !$outOfQuotes;

				// If this character is the end of an element,
				// output a new line and indent the next line.
			} else if(($char == '}' || $char == ']') && $outOfQuotes) {
				$result .= $newLine;
				$pos --;
				for ($j=0; $j<$pos; $j++) {
					$result .= $indentStr;
				}
			}

			// Add the character to the result string.
			$result .= $char;

			// If the last character was the beginning of an element,
			// output a new line and indent the next line.
			if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
				$result .= $newLine;
				if ($char == '{' || $char == '[') {
					$pos ++;
				}

				for ($j = 0; $j < $pos; $j++) {
					$result .= $indentStr;
				}
			}

			$prevChar = $char;
		}

		return $result;
	}

	static function parseMarkup ($markup, &$params)
	{
		$parts = explode (';', $markup);
		$m = array_shift ($parts);

		forEach ($parts as $param)
		{
			$prm = explode (':', $param);
			if (count($prm) === 1 && count($params) === 0)
			{
				$params['text'] = $prm[0];
				break;
			}
			if (count($prm) === 1)
				$params [trim($prm[0])] = 1;
			elseif (count($prm) === 2)
				$params [trim($prm[0])] = $prm[1];
			elseif (count($prm) > 2)
				$params [trim($prm[0])] = substr($param, strlen($prm[0]) + 1);
		}

		return $m;
	}

	static function debugBacktrace ()
	{
		$e = new \Exception;
		error_log ($e->getTraceAsString());
	}

	static function e10round ($number, $roundMethod)
	{
		return utils::round ($number, $roundMethod['precision'], $roundMethod['type']);
	}

	static function round ($number, $precision, $roundMethod)
	{
		if (is_string($precision))
		{ // '.5', '.2' etc
			$ratio = 1 / floatval($precision);
			$num = $number * $ratio;
			switch ($roundMethod)
			{
				case 0:
					return round ($num, 0) / $ratio;
				case 1:
					$coefficient = pow (10, 0);
					return (ceil($num*$coefficient)/$coefficient) / $ratio;
				case -1:
					$coefficient = pow (10, 0);
					return (floor($num*$coefficient)/$coefficient) / $ratio;
			}
		}

		switch ($roundMethod)
		{
			case 0:
				return round ($number, $precision);
			case 1:
				$coefficient = pow (10, $precision);
				return ceil($number*$coefficient)/$coefficient;
			case -1:
				$coefficient = pow (10, $precision);
				return floor($number*$coefficient)/$coefficient;
		}
	}

	static function weekDate ($weekNumber, $weekYear, $weekDay = 1, $format = 'Y-m-d')
	{
		$s = sprintf ('%04dW%02d%d', $weekYear, $weekNumber, $weekDay);
		$t = date ($format, strtotime($s));
		return $t;
	}

	static function renderTableFromArrayCsv ($rows, $columns, $params = array())
	{
		if (!count ($rows))
		{
			return '';
		}

		$lineNumber = 1;
		$c = '';

		$colSep = "\t";
		$lineSep = "\n";

		if (isset ($params ['colSeparator']))
			$colSep = $params ['colSeparator'];

		//$c .= "<table class='default fullWidht";
		//if (isset ($params ['tableClass']))
		//	$c .= ' ' . $params ['tableClass'];
		//$c .= "'>";

		$sums = array ();
		// -- header
		$colClasses = array ();
		foreach ($columns as $cn => $ch)
		{
			$colClasses [$cn] = '';
			if ($ch [0] == '+')
			{
				$ct = substr ($ch, 1);
				$sums [$cn] = 0;
				$colClasses [$cn] = 'number';
			}
			else if ($ch [0] == ' ')
			{
				$ct = substr ($ch, 1);
				$colClasses [$cn] = 'number';
			}
			else if ($ch [0] == '_')
			{
				$ct = substr ($ch, 1);
				$colClasses [$cn] = 'nowrap';
			}
			else if ($ch == '#')
			{
				$ct = $ch;
				$colClasses [$cn] = 'number';
			}
			else
				$ct = $ch;

			if (0)
				$c .= $ct;
		}
		if (0)
			$c .= $lineSep;

		// -- body
		foreach ($rows as $r)
		{
			$cntCols = count ($r);
			$colNumber = 1;
			foreach ($columns as $cn => $ch)
			{
				if ($cn == '#')
					$cv = "$lineNumber.";
				else
					$cv = isset ($r[$cn]) ? $r[$cn] : '';

				if ($cv instanceof \DateTimeInterface)
					$ct = $cv->format ('Y-m-d');
				else if (is_double ($cv) || is_int ($cv))
				{
					if (isset ($sums [$cn]))
						$sums [$cn] += $cv;
					if (is_int ($cv))
						$ct = $cv;
					else
						$ct = round ($cv, 2);
				}
				else if (is_array($cv))
					$ct = $cv['text'];
				else
					$ct = $cv;

				$c .= $ct;

				if ($colNumber < $cntCols)
					$c .= $colSep;

				$colNumber++;
			}
			$lineNumber++;
			$c .= $lineSep;
		}

		// -- footer
		if (count ($sums))
		{
			foreach ($columns as $cn => $ch)
			{
				$ct = "";
				if (isset ($sums [$cn]))
				{
					if (is_int ($sums [$cn]))
						$ct = nf ($sums [$cn], 0);
					else
						$ct = nf ($sums [$cn], 2);
				}
				$c .= $ct;
				$c .= $colSep;
			}
			$c .= $lineSep;
		}

		return $c;
	}

	static function param ($config, $key, $defaultValue)
	{
		if (isset ($config [$key]))
			return $config [$key];

		return $defaultValue;
	} // param

	static function today($format = '', $app = NULL)
	{
		if (self::$todayClass)
			return self::$todayClass->today($format, $app);
		$today = NULL;
		if ($app)
		{
			$wd = $app->getUserParam('wd');
			if ($wd)
				$today = utils::createDateTime($wd);
		}
		if ($today === NULL)
			$today = new \DateTime();
		$today->setTime (0,0);
		if ($format !== '')
			return $today->format($format);
		return $today;
	}

	static function now($format = '')
	{
		if (self::$todayClass)
			return self::$todayClass->now($format);
		$now = new \DateTime();
		if ($format !== '')
			return $now->format($format);
		return $now;
	}

	static function strToUtf8 ($string, $from = 'auto')
	{
		$detectedEncoding = '';

		if ($from === 'auto')
		{
			$test = iconv ('UTF-8', 'UTF-8', $string);
			if ($test === $string)
				return $string;

			$test = iconv ('WINDOWS-1250', 'WINDOWS-1250', $string);
			if ($test === $string)
				$detectedEncoding = 'WINDOWS-1250';
		}

		if ($detectedEncoding === '')
			return $string;

		return iconv ($detectedEncoding, 'utf-8', $string);
	}

	static $quartesMonths = ['Q1' => '1,2,3', 'Q2' => '4,5,6', 'Q3' => '7,8,9', 'Q4' => '10,11,12'];
	static $halfsMonths = ['H1' => '1,2,3,4,5,6', 'H2' => '7,8,9,10,11,12'];
	static function calendarMonthQuery ($column, &$q, $year, $month)
	{
		if (is_string($year) && $year[0] == 'Y')
		{
			array_push($q, ' AND YEAR(list.[begin]) = %i', substr($year, 1));
			return;
		}

		array_push($q, ' AND YEAR(list.[begin]) = %i', $year);
		if (is_string($month))
		{
			if ($month[0] === 'Q' && isset (self::$quartesMonths[$month]))
			{
				array_push($q, ' AND MONTH('.$column.') IN ('.self::$quartesMonths[$month].')');
				return;
			}
			if ($month[0] === 'H' && isset (self::$halfsMonths[$month]))
			{
				array_push($q, ' AND MONTH('.$column.') IN ('.self::$halfsMonths[$month].')');
				return;
			}
		}

		array_push($q, ' AND MONTH('.$column.') = %i', $month);
	}

	static function calendarMonthQuery2 ($column, &$q, $value)
	{
		if ($value == '0')
			return;

		if ($value[0] === 'Y')
		{
			array_push($q, " AND YEAR($column) = %i", substr($value, 1));
			return;
		}

		$year = substr($value, 0, 4);
		$month = substr($value, 4);

		array_push($q, ' AND YEAR('.$column.') = %i', $year);
		if ($month[0] === 'Q' && isset (self::$quartesMonths[$month]))
		{
			array_push($q, ' AND MONTH('.$column.') IN ('.self::$quartesMonths[$month].')');
			return;
		}
		if ($month[0] === 'H' && isset (self::$halfsMonths[$month]))
		{
			array_push($q, ' AND MONTH('.$column.') IN ('.self::$halfsMonths[$month].')');
			return;
		}

		array_push($q, ' AND MONTH('.$column.') = %i', $month);
	}

	static function calendarMonths($app)
	{
		$years = [];

		$periods = $app->cfgItem ('e10doc.acc.periods');
		foreach ($periods as $ap)
		{
			$y = intval(substr ($ap['begin'], 0, 4));
			if (!in_array($y, $years))
				$years[] = $y;
		}

		return array_reverse($years);
	}

	static function queryValues ()
	{
		$qv = array ();
		forEach ($_POST as $qryId => $qryValue)
		{
			$parts = explode ('_', $qryId);
			if (count($parts) >= 3 && $parts[0] === 'query')
			{
				if (isset($parts[3]))
					$qv[$parts[1]][$parts[2]][$parts[3]] = $qryValue;
				else
					$qv[$parts[1]][$parts[2]] = $qryValue;
			}
		}
		if (!count($qv))
		{
			forEach ($_GET as $qryId => $qryValue)
			{
				$parts = explode('_', $qryId);
				if (count($parts) >= 3 && $parts[0] === 'query')
				{
					if (isset($parts[3]))
						$qv[$parts[1]][$parts[2]][$parts[3]] = $qryValue;
					else
						$qv[$parts[1]][$parts[2]] = $qryValue;
				}
			}
		}
		return $qv;
	}

	static function hostingCfg ($requeredFields = NULL)
	{
		if (!is_file(__SHPD_ETC_DIR__.'/server.json'))
			return utils::err ("file `".__SHPD_ETC_DIR__.'/server.json'."` not found");

		$cfg = json_decode (file_get_contents(__SHPD_ETC_DIR__.'/server.json'), TRUE);
		if (!$cfg)
			return utils::err ("invalid `".__SHPD_ETC_DIR__.'/server.json'."` settings (syntax error?)");

		return $cfg;
	}

	static function machineDeviceId ()
	{
		if (!is_file('/etc/shipard/device-id.json'))
		{
			$deviceId = md5(json_encode(posix_uname()).mt_rand (1000000, 999999999).'-'.time().'-'.mt_rand (1000000, 999999999));
			file_put_contents('/etc/shipard/device-id.json', $deviceId);
		}
		else
		{
			$deviceId = file_get_contents('/etc/shipard/device-id.json');
		}

		return $deviceId;
	}

	static function dsCfg ()
	{
		if (!is_file(__APP_DIR__.'/config/dataSourceInfo.json'))
			return utils::err ("file 'config/dataSourceInfo.json' not found");

		$cfg = json_decode (file_get_contents(__APP_DIR__.'/config/dataSourceInfo.json'), TRUE);
		if (!$cfg)
			return utils::err ("invalid config/dataSourceInfo.json settings (syntax error?)");

		return $cfg;
	}

	static function tmpFileName ($fileExt, $baseName = 'x', $relative = 0)
	{
		if ($relative)
			$tmpFileName = 'tmp/'.$baseName.'-' . time() . '-' . mt_rand (1000000, 999999999) . '.' . $fileExt;
		else
			$tmpFileName = __APP_DIR__ .'/tmp/'.$baseName.'-' . time() . '-' . mt_rand (1000000, 999999999) . '.' . $fileExt;
		return $tmpFileName;
	}

	static function err ($msgText)
	{
		error_log($msgText);
		return FALSE;
	}

	static function wwwGroup ()
	{
		return 'shpd';
	}

	static function wwwUser ()
	{
		return 'shpd';
	}

	static function superuser ()
	{
		return (0 == posix_getuid());
	}

	static function checkFilePermissions ($fullFileName)
	{
		$fp = substr(sprintf('%o', fileperms($fullFileName)), -4);
		if ($fp !== '0660')
		{
			if (!chmod ($fullFileName, 0660))
				error_log("chmod failed on `$fullFileName` [$fp]");
		}

		$fg = posix_getgrgid(filegroup($fullFileName));
		if ($fg['name'] !== self::wwwGroup())
		{
			if (!chgrp ($fullFileName, self::wwwGroup()))
				error_log("chgrp failed on `$fullFileName` [{$fg['name']}]");
		}
	}

	static function mkDir($dir, $mode = 0770)
	{
		if (!is_dir($dir))
			mkdir($dir, $mode, TRUE);

		chmod($dir, $mode);
		if (self::superuser())
			chown($dir, self::wwwUser());
		chgrp($dir, self::wwwGroup());
	}

	static function tableHeaderColName ($colName)
	{
		if (strpos(' +_|', $colName[0]) === FALSE)
			return $colName;
		return substr($colName, 1);
	}

	static function homeCurrency ($app, $date)
	{
		$cd = utils::createDateTime($date)->format('Y-m-d');
		if ($cd === NULL)
			$cd = utils::today();
		foreach ($app->cfgItem ('e10doc.acc.periods', []) as $ap)
		{
			if ($ap['begin'] > $cd || $ap['end'] < $cd)
				continue;
			return $ap['currency'];
		}

		return 'czk';
	}

	static function dsCmd ($app, $cmd, $params = FALSE)
	{
		$dsid = $app->cfgItem ('dsid', 0);
		$cfg = ['dsid' => $dsid, 'cmd' => $cmd];
		if ($params !== FALSE)
			$cfg ['params'] = $params;

		$tmpFileName = __SHPD_VAR_DIR__.'dscmd/' . $dsid . '-' . time() . '-' . mt_rand (1000000, 999999999) . '.json';

		file_put_contents($tmpFileName, json_encode($cfg));
	}

	static function setAppStatus ($status)
	{
		$fn = __APP_DIR__.'/config/status.data';
		if ($status === '')
		{
			if (is_file($fn))
				unlink($fn);
		}
		else
		{
			file_put_contents($fn, $status);
		}
	}

	static function appStatus ()
	{
		$fn = __APP_DIR__.'/config/status.data';
		if (is_file($fn))
			return file_get_contents($fn);

		return TRUE;
	}

	static function is_uint ($val)
	{
		if (!preg_match('/[^0-9]/', $val))
			return TRUE;
		return FALSE;
	}

	static function getAllHeaders()
	{
		$headers = [];
		foreach ($_SERVER as $name => $value)
		{
			if (substr($name, 0, 5) == 'HTTP_')
				$headers[str_replace(' ', '-', strtolower(str_replace('_', ' ', substr($name, 5))))] = $value;
		}
		return $headers;
	}

	static function serverCounter ($key, $inc = FALSE)
	{
		$tmpDir = '/var/lib/shipard/tmp';
		if (!is_dir($tmpDir))
			mkdir($tmpDir, 0770, TRUE);

		$tmpFileName =  $tmpDir.'/counter-' . $key . '.txt';
		$value = 0;
		if (is_file($tmpFileName))
		{
			$value = intval(file_get_contents($tmpFileName));
		}
		if ($inc)
		{
			$value++;
			$str = strval($value);
			file_put_contents($tmpFileName, $str);
		}

		return $value;
	}

	static function userImage ($app, $ndx, $recData)
	{
		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE [tableid] = %s', 'e10.persons.persons', ' AND [recid] = %i', $ndx, ' AND [deleted] = 0');
		array_push($q, ' ORDER BY defaultImage DESC, [order], name LIMIT 0, 1');
		$attachment = $app->db->query ($q)->fetch ();
		if ($attachment)
		{
			return $app->dsRoot . '/imgs'.'/-w265'.'/att/' . $attachment ['path'] . urlencode($attachment ['filename']);
		}

		$dirName = __APP_DIR__.'/imgcache/users/';
		if (!is_dir($dirName))
			mkdir($dirName, 0770, true);

		$sn = Str::substr($recData['firstName'], 0, 1).Str::substr($recData['lastName'], 0, 1);
		$colorId = substr($recData['ndx'], 0, 1);
		$baseFileName = bin2hex ($sn).'_'.$colorId.'.svg';
		$fullFileName = $dirName.$baseFileName;
		if (!is_file($fullFileName))
		{
			$colors = [
					['b' => '8D230F', 't' => 'FFFFFF'],
					['b' => '1E434C', 't' => 'FFFFFF'],
					['b' => '1995AD', 't' => 'FFFFFF'],
					['b' => '4B7447', 't' => 'FFFFFF'],
					['b' => '2D4262', 't' => 'FFFFFF'],
					['b' => '6E6702', 't' => 'FFFFFF'],
					['b' => '3F6C45', 't' => 'FFFFFF'],
					['b' => '5C821A', 't' => 'FFFFFF'],
					['b' => '1E656D', 't' => 'FFFFFF'],
					['b' => '626D71', 't' => 'FFFFFF'],
			];
			$colorText = $colors[$colorId]['t'];
			$colorBackground = $colors[$colorId]['b'];


			$data = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0" y="0" width="256" height="256" viewBox="0, 0, 256, 256">
	<style>text { font-family:Arial, sans-serif; font-weight: 500;}</style>
	<g id="Layer_1">
		<path d="M0,0 L256,0 L256,256 L0,256 L0,0 z" fill="#'.$colorBackground.'"/>
		<text transform="matrix(1, 0, 0, 1, 128, 128)">
			<tspan x="-100.02" y="50" font-size="144" fill="#'.$colorText.'">'.$sn.'</tspan>
		</text>
	</g>
</svg>
';
			file_put_contents($fullFileName, $data);
		}


		return $app->dsRoot . '/imgcache/users/'.$baseFileName;
	}
}
