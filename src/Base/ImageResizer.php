<?php

namespace Shipard\Base;



class ImageResizer
{
	private $app;
	private $params = [];
	private $srcFileName;
	private $cachePath = '';
	private $requestPath;
	private $convertFormat = FALSE;
	private $quality = FALSE;
	private $badge = FALSE;
	private $backgroundColor = FALSE;

	private $width = 0;
	private $height = 0;
	private $icon = 0;
	private $pageNumber = 0;

	private $convertParams = array ();
	public $cacheFullFileName;

	public function __construct ($app)
	{
		$this->app = $app;
	}

	public function run ()
	{
		$this->createSrcFileName ();
		if (!file_exists ($this->srcFileName))
		{
			error_log ("file not found: " . $this->srcFileName);
			header('HTTP/1.1 404 Not Found');
			echo 'Error: image does not exist! ';
			return;
		}

		$this->resize ();
		$this->send();
	}

	public function createSrcFileName ($fn = FALSE)
	{
		$cpi = 2;
		$this->srcFileName = __APP_DIR__;
		$fileName = ($fn !== FALSE) ? $fn : $this->app->requestPath ();
		$this->requestPath = $fileName;
		$fileNameParts = explode ('/', substr($fileName, 1));
		unset($fileNameParts[0]);

		if (substr($fileName, -4) === '.pdf')
			$this->cachePath .= '/pdf';

		$inParamsBlock = 1;
		foreach ($fileNameParts as $p)
		{
			if ($p [0] === '-' && $inParamsBlock)
			{
				$this->params[] = $p;
				continue;
			}
			$inParamsBlock = 0;
			$this->srcFileName .= '/' . $p;
			if ($cpi)
			{
				$this->cachePath .= '/' . $p;
				$cpi--;
			}
		}
		$this->srcFileName = urldecode ($this->srcFileName);
	} // createSrcFileName

	public function parseParams ($util, $fileSuffix)
	{
		foreach ($this->params as $p)
		{
			switch ($p [1])
			{
				case 'w': $this->width = (int) substr($p, 2); break;
				case 'h': $this->height = (int) substr($p, 2); break;
				case 'x': $this->convertFormat = TRUE; ;break;
				case 'q': $this->quality = intval(substr($p, 2)); break;
				case 'b': $this->badge = substr($p, 2); break;
				case 'c': $this->backgroundColor = substr($p, 2); break;
				case 'i': $this->icon = (int) substr($p, 2); break;
				case 'p': $this->pageNumber = intval(substr($p, 2)); break;
				case 'v': break;
			}
		}

		// convert -resize
		if (($util == "convert") && (($this->width) || ($this->height)))
		{
			$prm = '-resize ';
			if ($this->width)
				$prm .= $this->width;
			$prm .= 'x';
			if ($this->height)
				$prm .= $this->height;
			$prm .= '\>';
			$this->convertParams [] = $prm;

			if ($fileSuffix === '.jpg' || $fileSuffix === '.jpeg')
			{
				if ($this->quality === FALSE)
					$this->convertParams [] = '-quality 90 -interlace Plane -strip';
				else
					$this->convertParams [] = '-quality ' . $this->quality . ' -interlace Plane -strip';
				$this->convertParams[] = '-auto-orient';
			}

			if ($this->backgroundColor !== FALSE)
			{
				$bgColors = [];
				if (preg_match( '/[a-fA-F0-9]{6}/', $this->backgroundColor, $bgColors) === 1)
					$this->convertParams[] = '-background \'#' . $bgColors[0].'\' -alpha remove';
			}
		}

		// rsvg-convert -resize´
		if (($util == "rsvg-convert") && (($this->width) || ($this->height)))
		{
			$prm = '';
			if ($this->width)
				$prm .= ' -w ' . $this->width;
			if ($this->height)
				$prm .= ' -h ' . $this->height;

			if ($this->backgroundColor !== FALSE)
			{
				$bgColors = [];
				if (preg_match( '/[a-fA-F0-9]{6}/', $this->backgroundColor, $bgColors) === 1)
					$prm .= ' -b ' . $bgColors[0];
			}

			$this->convertParams [] = $prm;
		}

		// convert -extent
		/*if (($util == "convert") && ($this->width) && ($this->height))
		{
			$this->convertParams [] = '-background transparent';
			$this->convertParams [] = '-gravity center';
			$this->convertParams [] = '-extent '. $this->width . 'x' . $this->height;
		}*/

	} // parseParams


	public function resize ()
	{
		$fileTypes = array ();
		$fileTypes['default'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/undefined.svg');
		$fileTypes['.txt'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/generic.svg');
		$fileTypes['.xls'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/spreadsheet.svg');
		$fileTypes['.ods'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/spreadsheet.svg');
		$fileTypes['.doc'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/document.svg');
		$fileTypes['.odt'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/document.svg');
		$fileTypes['.jpg'] = array ('util' => 'convert');
		$fileTypes['.jpeg'] = array ('util' => 'convert');
		$fileTypes['.webp'] = array ('util' => 'convert');
		$fileTypes['.png'] = array ('util' => 'convert', 'destFileType' => 'png');
		$fileTypes['.gif'] = array ('util' => 'convert', 'destFileType' => 'gif');
		$fileTypes['.tif'] = array ('util' => 'convert');
		$fileTypes['.tiff'] = array ('util' => 'convert');
		$fileTypes['.svg'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'destFileType' => 'png');
		$fileTypes['.pdf'] = array ('util' => 'convert', 'extraSourceNameParam' => '.jpg', 'extraParam' => '-quality 90');

		$srcType = strtolower (substr ($this->srcFileName, strrpos ($this->srcFileName, ".")));
		if (isset ($fileTypes[$srcType]))
		    $fileType = $fileTypes[$srcType];
		else
		    $fileType = $fileTypes ['default'];

		$extraParam = "";
		if (isset ($fileType['extraParam']))
		    $extraParam = $fileType['extraParam']." ";
		$extraSourceNameParam = "";
		if (isset ($fileType['extraSourceNameParam']))
		    $extraSourceNameParam = $fileType['extraSourceNameParam'];
		$outputFileParam = "";
		if (isset ($fileType['outputFileParam']))
		    $outputFileParam = $fileType['outputFileParam']." ";

		// -- cache file name
		$destFileType = isset($fileType['destFileType']) ? $fileType['destFileType'] : 'jpg';
		$cacheBaseFileName = md5 ($this->requestPath) . '.' . $destFileType;
		$cacheDir = __APP_DIR__ . '/imgcache' . $this->cachePath;
		$this->cacheFullFileName = $cacheDir . '/' . $cacheBaseFileName;

		if (file_exists ($this->cacheFullFileName))
			return;

		if (!is_dir($cacheDir))
			mkdir ($cacheDir, 0775, TRUE);

		$this->parseParams ($fileType['util'], $srcType);
		if ($this->convertFormat)
		{
			$suffixPos = strrpos($this->srcFileName, '.');
			$this->srcFileName = substr($this->srcFileName, 0, $suffixPos);
		}
		$srcFileName = $this->srcFileName;

		if ($srcType == '.pdf')
		{
			$prm = '';
			if ($this->height)
				$prm .= ' -scale-to ' . $this->height;
			elseif ($this->width)
				$prm .= ' -scale-to ' . $this->width;

			if ($this->pageNumber)
				$prm .= ' -f '.$this->pageNumber.' -l '.$this->pageNumber;

			$cmd = "pdftocairo -jpeg -singlefile{$prm} $srcFileName " . substr($this->cacheFullFileName, 0, -4);
		}
		else
		if ($this->icon)
		{
			$imgWidth = $this->icon;
			$imgHeight = $imgWidth;

			$badgeLen = ($this->badge) ? strlen($this->badge) : 0;

			$cmd = "convert -background none ";

			if ($badgeLen)
				$cmd .= "-gravity NorthWest ";
			else
				$cmd .= "-gravity center ";

			$cmd .= "\"{$srcFileName}\" ";
			$cmd .= "-resize {$imgWidth}x{$imgWidth} ";
			$cmd .= "-extent {$imgWidth}x{$imgWidth} ";
			if ($badgeLen)
			{
				$cmd .= "-gravity none ";

				if ($this->badge === '.')
				{
					$circleDiameter = intval(($imgWidth / 7));
					$circlePosX = intval($imgWidth - $circleDiameter * 1.6);
					$circlePosY = intval($circleDiameter * 1.6);
					$circlePosX2 = $circlePosX + $circleDiameter;
					$circlePosY2 = $circlePosY + $circleDiameter;
					$strokeWidth = intval($circleDiameter / 4);

					$cmd .= "-stroke \"rgba(0,0,0,.25)\" -strokewidth $strokeWidth -draw \"fill orange circle $circlePosX,$circlePosY $circlePosX2,$circlePosY2 \" ";
				}
				else
				{
					$badgePointSize = intval(($imgWidth / 2) * 1.1);
					$badgeLetterSize = intval($badgePointSize / 2);

					$badgeTextPadding = intval($badgeLetterSize / 3);
					$badgeWidth = intval($badgeLen * $badgeLetterSize) + $badgeTextPadding * 2;
					$badgeHeight = intval($badgePointSize + $badgeTextPadding / 2);
					$badgePosX = $imgWidth - $badgeWidth - 7;
					$badgePosXEnd = $imgWidth - 7;
					$textPosXEnd = $imgWidth - $badgeTextPadding - 7;
					$badgePosY = $imgHeight - $badgeHeight - 7;
					$badgePosYEnd = $imgWidth - 7;

					$textPosX = intval ($imgWidth - $badgeWidth + $badgeTextPadding * 0.8);

					$cmd .= "-stroke white -strokewidth 7 -draw \"fill rgb(194,24,7) roundRectangle {$badgePosX},{$badgePosY} {$badgePosXEnd},{$badgePosYEnd} 5,5\" ";
					$cmd .= "-font helvetica -stroke white -strokewidth 7 ";
					$cmd .= "-pointsize {$badgePointSize} ";
					$cmd .= "-draw \"fill white text {$textPosX},{$textPosXEnd} '{$this->badge}' \" ";
				}
			}
			$cmd .= "\"{$this->cacheFullFileName}\" ";
		}
		else
		{
			$cmd = $fileType['util'] . " " . $extraParam . implode(" ", $this->convertParams) .
				" \"" . $srcFileName . $extraSourceNameParam . "\"" .
				' ' . $outputFileParam . "\"" . $this->cacheFullFileName . "\"";
		}

		exec ($cmd);
	}

	public function send ()
	{
		$httpServer = $this->app->cfgItem ('serverInfo.httpServer', 0);
		$mime = mime_content_type ($this->cacheFullFileName);
		header ("Content-type: $mime");
		header ("Cache-control: max-age=10368000");
		header ('Expires: '.gmdate('D, d M Y H:i:s', time()+10368000).'GMT'); // 120 days
		header ('Content-Disposition: inline; filename=' . basename ($this->cacheFullFileName));
		if ($httpServer === 0)
			header ('X-SendFile: ' . $this->cacheFullFileName);
		else
			header ('X-Accel-Redirect: ' . $this->app->urlRoot.substr($this->cacheFullFileName, strlen(__APP_DIR__)));
		die();
	}

	function resizeLocalImage ($filePath)
	{
		$this->width = 1024;
		$this->srcFileName = $filePath;
		$this->requestPath = $filePath.'/'.$this->width;
		$this->resize();
		return $this->cacheFullFileName;
	}
} // Resizer
