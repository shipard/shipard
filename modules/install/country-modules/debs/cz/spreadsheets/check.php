<?php

$dir = '.';
$files = scandir($dir);
$id = $_GET['id'];

if (!isset($id))
{
	echo "není definováno 'id' výkazu! použijte např: 'check.php?id=spdBalanceSheetFull'";
	exit();
}

$fileId = 0;
$result = array();
$sources = array();

foreach ($files as $file)
{
	if (!in_array( $file, ['.', '..', 'check.php']))
	{
		if ($id == substr ($file, 0, strlen ($id)))
		{
			$data = file_get_contents($file);
			$json = json_decode($data, true);
			foreach ($json['pattern']['tables'] as $table)
			{
				$firstRow = 1;
				if (isset ($table['firstRowNumber']))
					$firstRow = $table['firstRowNumber'];
				if (!isset($sources[$file]['fileName']))
				{
					$sources[$file]['fileName'] = $json['name'];
					$fileId++;
					$sources[$file]['fileId'] = $fileId;
				}
				foreach ($table['rows'] as $krow => $row)
				{
					foreach ($row as $kcell => $cell)
					{
						if ($table['columns'][$kcell]['autoEval'] === 1)
							if (strlen($cell) && $cell[0] != '=')
							{
								$values = explode(' ', $cell);
								foreach ($values as $value)
								{
									$result[$value][$fileId][] = ['row' => $krow+$firstRow-1, 'column' => $kcell];
								}
							}
					}
				}
			}
		}
	}
}

ksort($result);

echo '<table border="1">';
echo '<tr>';

foreach ($sources as $s)
{
	echo '<td colspan="2">';
	echo $s['fileName'];
	echo '</td>';
}
echo '</tr>';

foreach ($result as $k => $r)
{
	$warning = FALSE;
	echo '<tr>';
	foreach ($sources as $s)
	{
		echo '<td>';
		if (isset($r[$s['fileId']]))
			echo $k;
		echo '</td>';

		echo '<td>';
		$counter = 0;
		if (isset($r[$s['fileId']]))
		{
			foreach ($r[$s['fileId']] as $cell)
			{
				echo chr($cell['column']+65).($cell['row']+1).' ';
				$counter++;
			}
		}
		if ($counter != 1)
			$warning = TRUE;
		echo '</td>';
	}
	if ($warning)
	{
		echo '<td>!!! PROBLÉM !!!</td>';
	}
	echo '</tr>';
}

echo '</table>';

