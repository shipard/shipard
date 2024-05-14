<?php

namespace mac\iot\libs;
use \Shipard\Base\Utility;


/**
 * class EPDImageCreator
 */
class EPDImageCreator extends Utility
{
  var $displayInfo = NULL;

  var \GDImage $srcImage;

  var $cntColors = 3;
  var $bitsColor = 2;
  var $bitsCount = 6;

  var $maxOneColorCount = 0;

  var $colors = [0 => 0, 0xFFFFFF => 1, 0xFF0000 => 2];

  var $data = [];
  var $totalBytes = 0;
  var $destFileName = '';

  public function setDisplayInfo($displayInfo)
  {
    $this->displayInfo = $displayInfo;

    $this->cntColors = $displayInfo['cntColors'];
    $this->colors = [];
    $colorIndex = 0;
    foreach ($this->displayInfo['colors'] as $hexC)
      $this->colors[hexdec($hexC)] = $colorIndex++;

    if ($this->cntColors <= 2)
    {
      $this->bitsColor = 1;
      $this->bitsCount = 7;
    }
    elseif ($this->cntColors <= 4)
    {
      $this->bitsColor = 2;
      $this->bitsCount = 6;
    }
    elseif ($this->cntColors <= 8)
    {
      $this->bitsColor = 3;
      $this->bitsCount = 5;
    }
  }

  public function setSrcImage($fileName)
  {
    $this->srcImage = @imagecreatefrompng($fileName);
    $this->destFileName = $fileName.'.sbef';
  }

  public function doIt()
  {
    $this->maxOneColorCount = (2 ** $this->bitsCount) - 1;

    $this->doIt2();
  }

  public function doIt2()
  {
    $width = imagesx($this->srcImage);
    $height = imagesy($this->srcImage);

    $maxOneCount = (2 ** $this->bitsCount) - 1;

    $lastColor = 254;
    $cnt = 0;

    for($y = 0; $y < $height; $y++)
    {
      $colIdx = 0;
      for($x = 0; $x < $width; $x++)
      {
        $colorRGB = imagecolorat($this->srcImage, $x, $y);
        $color = $this->colors[$colorRGB] ?? 0;

        //echo $color."\n";
        //if ($color > 1)
        //  $color = 1;

        if ($lastColor === 254)
        {
          $lastColor = $color;
          $cnt = 1;
          continue;
        }

        $cnt++;

        if ($lastColor !== $color)
        {
          //echo sprintf("RGB: %x / color: %d \n", $colorRGB, $color);
          $this->addByte($lastColor, $cnt - 1);
          $lastColor = $color;
          $cnt = 1;
        }

        if ($cnt === $maxOneCount)
        {
          //echo sprintf("RGB: %x / color: %d \n", $colorRGB, $color);

          $this->addByte($color, $cnt);
          $cnt = 0;

          continue;
        }

      }
    }

    if ($cnt)
      $this->addByte($lastColor, $cnt);

    //echo "TOTAL bytes: ".$this->totalBytes."\n";

    $this->saveData();
  }

  function addByte($color, $count)
  {
    $this->totalBytes++;

    $byte = ($color << $this->bitsCount)  | ($count & $this->maxOneColorCount);

    $this->data[] = $byte;

    //$binary = sprintf('%08b / %02x',  $byte, $byte);
    //echo "--> $count / ".$color."`$binary`"."\n";
  }

  protected function saveData()
  {
    $ptr = fopen($this->destFileName, 'wb');

    // -- header
    fwrite($ptr, pack('C', ord('S')));                              // 1
    fwrite($ptr, pack('C', ord('H')));                              // 2
    fwrite($ptr, pack('C', 1));                                     // 3
    fwrite($ptr, pack('C', $this->displayInfo['orientation']));     // 4
    fwrite($ptr, pack('n', $this->displayInfo['width']));           // 6
    fwrite($ptr, pack('n', $this->displayInfo['height']));          // 8

    for ($i = 9; $i <= 64; $i++)
      fwrite($ptr, pack('C', 0));

    // -- image
    for ($i = 0; $i < $this->totalBytes; $i++)
    {
      fwrite($ptr, pack('C', $this->data[$i]));
    }
    fclose($ptr);
  }

  // $img = imagecreatetruecolor(300, 200);
  // imagepng ($im, 'mypic.png');
}
