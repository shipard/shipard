<?php

namespace lib;

use PhpOffice\PhpSpreadsheet\Cell\DataType, PhpOffice\PhpSpreadsheet\Style\NumberFormat, PhpOffice\PhpSpreadsheet\IOFactory;


/**
 * Class E10ExcelUtils
 * @package lib
 */
class E10Excel extends \E10\Utility
{
	public function load ($fileName)
	{
		return IOFactory::load($fileName);
	}

	public function create ()
	{
		return new \PhpOffice\PhpSpreadsheet\Spreadsheet();
	}

	public function save ($obj, $fileName = '', $format = 'xlsx')
	{
		$baseFileName = time().'-'.mt_rand(1000000, 9999999).'.'.$format;
		$fn = ($fileName === '') ? __APP_DIR__ . '/tmp/'.$baseFileName : $fileName;
		$objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($obj, 'Xlsx');
		$objWriter->save($fn);
		return $baseFileName;
	}

	public function putTable ($spreadsheet, $sheetNumber, $firstColumn, $firstRow, $table)
	{
		$sheet = $spreadsheet->getSheet($sheetNumber);
		$cellY = $firstRow;
		foreach ($table as $rows)
		{
			$cellX = ord($firstColumn);
			foreach ($rows as $cell)
			{
				$cellId = chr($cellX).$cellY;

				if (is_array($cell))
				{
					$sheet->setCellValueExplicit($cellId, $cell['text'], DataType::TYPE_STRING);
				}
				else
				{
					if (is_string($cell))
						$sheet->setCellValueExplicit($cellId, $cell, DataType::TYPE_STRING);
					elseif (is_double($cell))
						$sheet->setCellValueExplicit($cellId, $cell, DataType::TYPE_NUMERIC);
					elseif ($cell instanceof \DateTimeInterface)
					{
						$sheet->setCellValue($cellId, \PHPExcel_Shared_Date::PHPToExcel($cell));
						$sheet->getStyle($cellId)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
					}
					else
						$sheet->setCellValue($cellId, $cell);
				}
				$cellX++;
			}
			$cellY++;
		}
	}

	public function appendTable ($spreadsheet, $sheetNumber, $firstColumn, $firstRow, $table, $header, $params = [])
	{
		$tr = new \lib\E10Excel\TableRenderer($table, $header, $params);
		$tr->render($this, $spreadsheet, $sheetNumber, $firstColumn, $firstRow);

		return $tr->cellY;
	}

	public function setAutoSize ($spreadsheet)
	{
		foreach (range('A', $spreadsheet->getActiveSheet()->getHighestDataColumn()) as $col)
		{
			$spreadsheet->getActiveSheet()->getColumnDimension($col)->setAutoSize(true);
		}
	}

	public function putColumn ($spreadsheet, $sheetNumber, $firstColumn, $firstRow, $table, $tableColumn, $firstDataRow = 0, $maxDataRows = -1, $format = FALSE)
	{
		$sheet = $spreadsheet->getSheet($sheetNumber);
		$cellY = $firstRow;

		$dataPosY = 0;
		$dataUsedRows = 0;

		foreach ($table as $row)
		{
			if ($dataPosY < $firstDataRow)
			{
				$dataPosY++;
				continue;
			}
			if ($maxDataRows != -1 && $dataUsedRows >= $maxDataRows)
				break;

			$cell = $row[$tableColumn];
			$cellId = $firstColumn.$cellY;
			if ($format !== FALSE)
				$sheet->getStyle($cellId)->getNumberFormat()->setFormatCode($format);
			$sheet->setCellValue($cellId, $cell);
			$cellY++;

			$dataPosY++;
			$dataUsedRows++;
		}
	}

	public function putCell ($spreadsheet, $sheetNumber, $firstColumn, $firstRow, $value, $format = FALSE, $classes = '')
	{
		$sheet = $spreadsheet->getSheet($sheetNumber);

		$cellId = $firstColumn.$firstRow;
		if ($format !== FALSE)
		{
			$sheet->getStyle($cellId)->getNumberFormat()->setFormatCode($format);
		}
		$sheet->setCellValue($cellId, $value);

		if ($classes !== '')
			$this->formatCell($spreadsheet, $sheetNumber, $firstColumn.$firstRow, $classes);
	}

	public function formatCell ($spreadsheet, $sheetNumber, $cellId, $cellClasses)
	{
		$styles = [
				'e10-row-minus' => ['bgColor' => 'FFEEEE'],
				'e10-row-plus' => ['bgColor' => 'EEFFEE'],
				'e10-row-info' => ['bgColor' => 'FFFFEE'],
				'e10-row-this' => ['bgColor' => 'EEEEFF'],
				'xls-table-header' => ['bgColor' => 'E0E0E0', 'bold' => TRUE],
				'xls-table-title' => ['bold' => TRUE, 'fontSize' => 16],
				'xls-report-title' => ['bold' => TRUE, 'fontSize' => 18],
				'sumtotal' => ['bgColor' => 'E0E0E0', 'bold' => TRUE],
				'subtotal' => ['bgColor' => 'F0F0F0', 'bold' => TRUE],
				'subheader' => ['bgColor' => 'E5E5E5', 'bold' => TRUE],
				'header' => ['bgColor' => 'E0E0E0', 'bold' => TRUE],

				'e10-bg-t1' => ['bgColor' => 'e1f7d5'],
				'e10-bg-t2' => ['bgColor' => 'ffcfab'],
				'e10-bg-t3' => ['bgColor' => 'ffe0e5'],
				'e10-bg-t4' => ['bgColor' => 'fbffb5'],
				'e10-bg-t5' => ['bgColor' => 'e0fffa'],
				'e10-bg-t6' => ['bgColor' => 'c5e0d6'],
				'e10-bg-t7' => ['bgColor' => '9fe28f'],
				'e10-bg-t8' => ['bgColor' => 'ffefd5'],

				'number' => ['align' => 'right'],
				'center' => ['align' => 'center']
		];

		$c = explode (' ', $cellClasses);
		foreach ($c as $classId)
		{
			if (isset($styles[$classId]))
				$this->setCellStyle($spreadsheet, $sheetNumber, $cellId, $styles[$classId]);
		}
	}

	public function setCellStyle($spreadsheet, $sheetNumber, $cells, $style)
	{
		$sheet = $spreadsheet->getSheet($sheetNumber);

		if (isset ($style['bgColor']))
		{
			$sheet->getStyle($cells)->getFill()->applyFromArray(
					[
							'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
							'color' => ['rgb' => $style['bgColor']]
					]
			);
		}

		if (isset ($style['bold']) || isset ($style['fontSize']))
		{
			$es = ['font' => []];
			if (isset ($style['bold']))
				$es['font']['bold'] = true;

			if (isset ($style['fontSize']))
				$es['font']['size']  = $style['fontSize'];

			$sheet->getStyle($cells)->applyFromArray($es);
		}

		if (isset ($style['align']) && $style['align'] === 'right')
		{
			$sheet->getStyle($cells)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
		}
		if (isset ($style['align']) && $style['align'] === 'center')
		{
			$sheet->getStyle($cells)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
		}
	}

	public function getSheetAsTable ($spreadsheet, $sheetNumber, &$table, $maxX = 0)
	{
		$tableY = 0;
		$tableX = 0;
		$sheet = $spreadsheet->getSheet($sheetNumber);

		foreach ($sheet->getRowIterator() as $row)
		{
			$cellIterator = $row->getCellIterator();
			$cellIterator->setIterateOnlyExistingCells(FALSE);

			$tableX = 0;
			foreach ($cellIterator as $cell)
			{
				$cv = $cell->getValue();
				$table[$tableY][$tableX] = $cv;
				$tableX++;

				if ($maxX && $tableX > $maxX)
					break;
			}
			$tableY++;
		}
	}
}

