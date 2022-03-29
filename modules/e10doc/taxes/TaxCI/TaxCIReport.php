<?php

namespace e10doc\taxes\TaxCI;

use e10\utils, e10\uiutils, e10\json, e10doc\core\e10utils;


/**
 * Class TaxCIReport
 * @package e10doc\taxes\VatReturn
 */
class TaxCIReport extends \e10doc\taxes\TaxReportReport
{
	var $reportVersionId;
	var $reportVersion;

	function init()
	{
		$this->taxReportTypeId = 'cz-tax-ci';
		$this->previewReportTemplate = 'reports.default.e10doc.taxes.tax-ci-tr/cz';

		parent::init();

		$this->paperMargin = '7mm';
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'preview', 'icon' => 'icon-eye', 'title' => 'Náhled'];

		return $d;
	}

	function createContent ()
	{
		$this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'preview': $this->createContent_Preview (); break;
		}
		$this->setInfo('saveFileName', $this->taxReportRecData['title']);

		$this->createContentXml();
	}

	public function createContent_All ()
	{
		$this->createContent_Preview();
	}

	public function createContent_Preview ()
	{
		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div style='text-align: center;'>"]);

		$this->data['currentPageNumber'] = 1;
		$this->data['cntPagesTotal'] = 2;

		$c = $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-ci-tr/cz', 'header');
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		$c = $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-ci-tr/cz', 'content');
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</div>"]);
	}

	public function createContentXml_Begin ()
	{
		$this->xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$this->xml .= "<Pisemnost nazevSW=\"Shipard\" verzeSW=\"".__E10_VERSION__."\">\n";
		$this->xml .= "<".$this->reportVersion['docVerId']." verzePis=\"02.01\">\n";
	}

	public function createContentXml_End ()
	{
		$this->xml .= "</".$this->reportVersion['docVerId'].">\n</Pisemnost>\n";
	}

	public function createContentXml_V_D()
	{
		$v = [
			'dokument' => $this->reportVersion['docVerIdShort'], 'k_uladis' => 'DPP',
			'd_uv' => $this->taxReportRecData['datePeriodEnd'],
			'zdobd_od' => $this->taxReportRecData['datePeriodBegin'],
			'zdobd_do' => $this->taxReportRecData['datePeriodEnd'],
		];

		$this->addXmlVItem('part1', 'c_nace', $v);
		$this->addXmlVItem('part1', 'c_ufo_cil', $v);
		$this->addXmlVItem('part1', 'dan_por', $v);
		$this->addXmlVItem('part1', 'dapdpp_forma', $v);
		$this->addXmlVItem('part1', 'hl_cin_2', $v);

		$this->addXmlVItem('part1', 'uv_vyhl', $v);
		$this->addXmlVItem('part1', 'kat_uj', $v);
		$this->addXmlVItem('part1', 'typ_dapdpp', $v);
		$this->addXmlVItem('part1', 'typ_popldpp', $v);
		$this->addXmlVItem('part1', 'spoj_zahr', $v);
		$this->addXmlVItem('part1', 'typ_zo', $v);

		$this->addXmlVItem('part1', 'uc_zav', $v);
		$this->addXmlVItem('part1', 'uv_rozsah', $v);

		$this->partsData['part1']['uv_rozsah'] ??= 0;
		$this->partsData['part1']['uv_rozsah_rozv'] ??= 0;
		if ($this->partsData['part1']['uv_rozsah'] !== $this->partsData['part1']['uv_rozsah_rozv'])
			$this->addXmlVItem('part1', 'uv_rozsah_rozv', $v);

		$this->partsData['part1']['uv_rozsah_vzz'] ??= 0;
		if ($this->partsData['part1']['uv_rozsah'] !== $this->partsData['part1']['uv_rozsah_vzz'])
			$this->addXmlVItem('part1', 'uv_rozsah_vzz', $v);

		$this->addXmlVItem('part1', 'd_zjist', $v);

		$this->addXmlRow('VetaD', $v);
	}

	public function createContentXml_V_P()
	{
		$v = [];

		$this->addXmlVItem('part1', 'c_orient', $v);
		$this->addXmlVItem('part1', 'c_pop', $v);
		$this->addXmlVItem('part1', 'c_pracufo', $v);
		$this->addXmlVItem('part1', 'c_telef', $v);
		$this->addXmlVItem('part1', 'dic', $v);

		if (isset($this->partsData['part1']['k_stat']) && $this->partsData['part1']['k_stat'] !== 'cz')
			$this->addXmlVItem('part1', 'k_stat', $v);

		$this->addXmlVItem('part1', 'naz_obce', $v);
		$this->addXmlVItem('part1', 'psc', $v);
		$this->addXmlVItem('part1', 'ulice', $v);
		$this->addXmlVItem('part1', 'zkrobchjm', $v);

		$zast_typ = utils::cfgItem($this->partsData, 'part1.zast_typ', '');
		if ($zast_typ === '')
		{
			$this->addXmlVItem('part1', 'opr_jmeno', $v);
			$this->addXmlVItem('part1', 'opr_prijmeni', $v);
			$this->addXmlVItem('part1', 'opr_postaveni', $v);
		}

		if ($zast_typ === 'P')
		{
			$this->addXmlVItem('part1', 'zast_typ', $v);
			$this->addXmlVItem('part1', 'zast_kod', $v);
			$this->addXmlVItem('part1', 'zast_nazev', $v);
			$this->addXmlVItem('part1', 'zast_ic', $v);
		}

		if ($zast_typ === 'F')
		{
			$this->addXmlVItem('part1', 'zast_typ', $v);
			$this->addXmlVItem('part1', 'zast_kod', $v);
			$this->addXmlVItem('part1', 'zast_dat_nar', $v);
			$this->addXmlVItem('part1', 'zast_ev_cislo	', $v);
			$this->addXmlVItem('part1', 'rod_c', $v);
			$this->addXmlVItem('part1', 'zast_jmeno', $v);
			$this->addXmlVItem('part1', 'zast_prijmeni', $v);
		}

		$this->addXmlRow('VetaP', $v);
	}

	public function createContentXml_V_O()
	{
		$v = [];

		$this->addXmlVItem('part2', 'd_hospvysl', $v);
		$this->addXmlVItem('part2', 'kc_ii10_10', $v);
		$this->addXmlVItem('part2', 'kc_ii110_100', $v);
		$this->addXmlVItem('part2', 'kc_ii111_101', $v);
		$this->addXmlVItem('part2', 'kc_ii120_110', $v);
		$this->addXmlVItem('part2', 'kc_ii130_120', $v);
		$this->addXmlVItem('part2', 'kc_ii140_130', $v);
		$this->addXmlVItem('part2', 'kc_ii150_140', $v);
		$this->addXmlVItem('part2', 'kc_ii170_150', $v);
		$this->addXmlVItem('part2', 'kc_ii180_160', $v);
		$this->addXmlVItem('part2', 'kc_ii181_161', $v);
		$this->addXmlVItem('part2', 'kc_ii182_162', $v);
		$this->addXmlVItem('part2', 'kc_ii190_170', $v);
		$this->addXmlVItem('part2', 'kc_ii200_200', $v);
		$this->addXmlVItem('part2', 'kc_ii201_201', $v);
		$this->addXmlVItem('part2', 'kc_ii210_230', $v);
		$this->addXmlVItem('part2', 'kc_ii220_240', $v);
		$this->addXmlVItem('part2', 'kc_ii221_241', $v);
		$this->addXmlVItem('part2', 'kc_ii230_250', $v);
		$this->addXmlVItem('part2', 'kc_ii231_251', $v);
		$this->addXmlVItem('part2', 'kc_ii240_260', $v);
		$this->addXmlVItem('part2', 'kc_ii250_210', $v);
		$this->addXmlVItem('part2', 'kc_ii260_270', $v);
		$this->addXmlVItem('part2', 'kc_ii270_280', $v);
		$this->addXmlVItem('part2', 'kc_ii280_290', $v);
		$this->addXmlVItem('part2', 'kc_ii290_300', $v);
		$this->addXmlVItem('part2', 'kc_ii291_301', $v);
		$this->addXmlVItem('part2', 'kc_ii300_310', $v);
		$this->addXmlVItem('part2', 'kc_ii30_20', $v);
		$this->addXmlVItem('part2', 'kc_ii310_320', $v);
		$this->addXmlVItem('part2', 'kc_ii320_330', $v);
		$this->addXmlVItem('part2', 'kc_ii40_30', $v);
		$this->addXmlVItem('part2', 'kc_ii50_40', $v);
		$this->addXmlVItem('part2', 'kc_ii60_50', $v);
		$this->addXmlVItem('part2', 'kc_ii71_61', $v);
		$this->addXmlVItem('part2', 'kc_ii72_62', $v);
		$this->addXmlVItem('part2', 'kc_ii80_70', $v);
		$this->addXmlVItem('part2', 'kc_ii_109', $v);
		$this->addXmlVItem('part2', 'kc_ii_111', $v);
		$this->addXmlVItem('part2', 'kc_ii_112', $v);
		$this->addXmlVItem('part2', 'kc_ii_220', $v);
		$this->addXmlVItem('part2', 'kc_ii_242', $v);
		$this->addXmlVItem('part2', 'kc_ii_243', $v);
		$this->addXmlVItem('part2', 'kc_ii_331', $v);
		$this->addXmlVItem('part2', 'kc_ii_332', $v);
		$this->addXmlVItem('part2', 'kc_ii_333', $v);
		$this->addXmlVItem('part2', 'kc_ii_334', $v);
		$this->addXmlVItem('part2', 'kc_ii_335', $v);
		$this->addXmlVItem('part2', 'kc_ii_340', $v);
		$this->addXmlVItem('part2', 'kc_ii_360', $v);
		$this->addXmlVItem('part2', 'text_ii182_162', $v);
		$this->addXmlVItem('part2', 'text_ii221_241', $v);
		$this->addXmlVItem('part2', 'text_ii291_301', $v);
		$this->addXmlVItem('part2', 'text_ii72_62', $v);

		$this->addXmlRow('VetaO', $v);
	}

	public function createContentXml_V_UE()
	{
		$cnt = 0;
		$total = 0;
		$rows = $this->partsData['part2_att1_a']['table_P1A'];
		foreach ($rows as $r)
		{
			if ($r['kc_1a'] == 0 || $r['naz_uc_skup'] == '')
				continue;

			$v = ['kc_1a' => $r['kc_1a'], 'naz_uc_skup' => $r['naz_uc_skup']];
			$this->addXmlRow('VetaU', $v);
			$cnt++;
			$total += $r['kc_1a'];
		}

		if ($cnt)
		{
			$v = ['kc_dpp_a12' => $total];
			$this->addXmlRow('VetaE', $v);
		}
	}

	public function createContentXml_V_F()
	{
		$v = [];

		$this->addXmlVItem('part2_att1_b', 'kc_dpp_b10', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dpp_b6', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dpp_b_6odsk', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dpp_b_ohm_30_6', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dpp_b_onm', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dppb1', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dppb2', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dppb3', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dppb4', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dppb5', $v);
		$this->addXmlVItem('part2_att1_b', 'kc_dppb6_b8', $v);

		$this->addXmlRow('VetaF', $v);
	}

	public function createContentXml_V_G()
	{
		$v = [];

		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c10', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c11', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c12', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c13', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c14', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c16', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c17', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c18', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c19', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c20', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c21', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c22', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c3', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c30', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c31', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c4', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c5', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c6', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c7', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c8', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c9', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c_5a1', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c_5a2', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c_5a3', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_dpp_c_5a4', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_op8b', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_op8c', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_sop8b', $v);
		$this->addXmlVItem('part2_att1_c', 'kc_sop8c', $v);

		$this->addXmlRow('VetaG', $v);
	}

	public function createContentXml_V_VI()
	{
		$cnt = 0;
		$total_pr1e_sl_4 = 0;
		$total_pr1e_sl_5 = 0;
		$rows = $this->partsData['part2_att1_e']['table_P1E'];
		foreach ($rows as $r)
		{
			if ($r['pr1e_sl_2'] == 0 && $r['pr1e_sl_3'] == 0 && $r['pr1e_sl_4'] == 0 && $r['pr1e_sl_5'] == 0)
				continue;

			$v = [
				'pr1e_sl_1_do' => utils::createDateTime($r['pr1e_sl_1_do']),
				'pr1e_sl_1_od' => utils::createDateTime($r['pr1e_sl_1_od']),
				'pr1e_sl_2' => $r['pr1e_sl_2'],
				'pr1e_sl_3' => $r['pr1e_sl_3'],
				'pr1e_sl_4' => $r['pr1e_sl_4'],
				'pr1e_sl_5' => $r['pr1e_sl_5'],
				];
			$this->addXmlRow('VetaV', $v);
			$cnt++;
			$total_pr1e_sl_4 += intval($r['pr1e_sl_4']);
			$total_pr1e_sl_5 += intval($r['pr1e_sl_5']);
		}

		if ($cnt)
		{
			$v = ['kc_dppc65_d85' => $total_pr1e_sl_4, 'pr1e_sl_4_celk' => $total_pr1e_sl_5];
			$this->addXmlRow('VetaI', $v);
		}
	}

	public function createContentXml_V_J()
	{
		$v = [];

		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_1_r1_do', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_1_r1_od', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_1_r2_do', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_1_r2_od', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_1_r3_do', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_1_r3_od', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_1_r4_do', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_1_r4_od', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_2_r1', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_2_r2', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_2_r3', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_2_r4', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_3_r1', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_3_r2', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_3_r3', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_3_r4', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_4_r1', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_4_r2', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_4_r3', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_4_r4', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_4_r5', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_5_r1', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_5_r2', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_5_r3', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_5_r4', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1f_sl_5_r5', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_1_r1_do', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_1_r1_od', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_1_r2_do', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_1_r2_od', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_1_r3_do', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_1_r3_od', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_1_r4_do', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_1_r4_od', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_2_r1', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_2_r2', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_2_r3', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_2_r4', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_3_r1', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_3_r2', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_3_r3', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_3_r4', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_4_r1', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_4_r2', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_4_r3', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_4_r4', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_4_r5', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_5_r1', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_5_r2', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_5_r3', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_5_r4', $v);
		$this->addXmlVItem('part2_att1_f', 'pr1fc_sl_5_r5', $v);

		$this->addXmlRow('VetaJ', $v);
	}

	public function createContentXml_V_L()
	{
		$v = [];

		$this->addXmlVItem('part2_att1_g', 'pr1g_1', $v);
		//$this->addXmlVItem('part2_att1_g', 'pr1g_2', $v); // valid to 2016

		$this->addXmlRow('VetaL', $v);
	}

	public function createContentXml_V_M()
	{
		$v = [];

		$this->addXmlVItem('part2_att1_h', 'kc_dpp_f1', $v);
		$this->addXmlVItem('part2_att1_h', 'kc_dpp_f2', $v);
		$this->addXmlVItem('part2_att1_h', 'kc_dpp_f4', $v);
		$this->addXmlVItem('part2_att1_h', 'kc_dpp_h1_35ab', $v);

		$this->addXmlRow('VetaM', $v);
	}

	public function createContentXml_V_N()
	{
		$v = [];

		$this->addXmlVItem('part2_att1_i', 'kc_dppd17_g17', $v);
		$this->addXmlVItem('part2_att1_i', 'poc_zvl_pr_i', $v);
		$this->addXmlVItem('part2_att1_i', 'pr1i_1', $v);
		$this->addXmlVItem('part2_att1_i', 'pr1i_2', $v);
		$this->addXmlVItem('part2_att1_i', 'pr1i_3', $v);
		$this->addXmlVItem('part2_att1_i', 'pr1i_4', $v);

		$this->addXmlRow('VetaN', $v);
	}

	public function createContentXml_V_Q()
	{
		$v = [];

		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_2_r1', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_2_r2', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_2_r3', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_2_r4', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_2_r5', $v);
		//$this->addXmlVItem('part2_att1_j', 'pr1j_sl_2_r6', $v); // valid to 2016
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_2_r7', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_2_r9', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_3_r1', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_3_r2', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_3_r3', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_3_r4', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_3_r5', $v);
		//$this->addXmlVItem('part2_att1_j', 'pr1j_sl_3_r6', $v); // valid to 2016
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_3_r7', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_3_r9', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_4_r1', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_4_r2', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_4_r3', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_4_r4', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_4_r5', $v);
		//$this->addXmlVItem('part2_att1_j', 'pr1j_sl_4_r6', $v); // valid to 2016
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_4_r7', $v);
		$this->addXmlVItem('part2_att1_j', 'pr1j_sl_4_r9', $v);

		$this->addXmlRow('VetaQ', $v);
	}

	public function createContentXml_V_S()
	{
		$v = [];

		$this->addXmlVItem('part2_att1_k', 'kc_dpp_i1', $v);
		$this->addXmlVItem('part2_att1_k', 'poc_zam', $v);

		$this->addXmlRow('VetaS', $v);
	}

	public function createContentXml_V_UA()
	{
		$bsType = $this->partsData['att_balanceSheet']['VARIANT'];
		$bsDef = $this->reportVersion['balanceSheets'][$bsType];

		$fc = intval($bsDef['AKTIVA']['firstColumn']);
		$firstRowNumber = isset($bsDef['AKTIVA']['firstRowNumber']) ? $bsDef['AKTIVA']['firstRowNumber'] : 1;
		$rowNumber = intval($bsDef['AKTIVA']['firstRow']);
		$tbl = intval($bsDef['AKTIVA']['table']);
		$cntRows = intval($bsDef['AKTIVA']['cntRows']);

		// -- new mode
		if (isset($bsDef['AKTIVA']['rows']))
		{
			foreach ($bsDef['AKTIVA']['rows'] as $tax_report_rn => $table_row_number)
			{
				$trn = intval($table_row_number) - $rowNumber - $firstRowNumber + 1;
				$colBase = 'C_'.$tbl.'_'.$trn.'_';
				$v = ['c_radku' => strval($tax_report_rn)];

				$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+0), 'kc_brutto', $v, FALSE);
				$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+1), 'kc_korekce', $v, FALSE);
				$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+2), 'kc_netto', $v, FALSE);
				$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+3), 'kc_netto_min', $v, FALSE);

				$this->addXmlRow('VetaUA', $v);
			}
			return;
		}

		// -- old mode
		for ($rn = 1; $rn <= $cntRows; $rn++)
		{
			if (isset($bsDef['AKTIVA']['enabledRows']) && !in_array($rn, $bsDef['AKTIVA']['enabledRows']))
				continue;

			$trn = $rowNumber - 1;
			$colBase = 'C_'.$tbl.'_'.$trn.'_';

			$v = ['c_radku' => strval($rn)];

			$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+0), 'kc_brutto', $v, FALSE);
			$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+1), 'kc_korekce', $v, FALSE);
			$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+2), 'kc_netto', $v, FALSE);
			$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+3), 'kc_netto_min', $v, FALSE);

			$this->addXmlRow('VetaUA', $v);

			$rowNumber++;
		}
	}

	public function createContentXml_V_UD()
	{
		$bsType = $this->partsData['att_balanceSheet']['VARIANT'];
		$bsDef = $this->reportVersion['balanceSheets'][$bsType];

		$fc = intval($bsDef['PASIVA']['firstColumn']);
		$firstRowNumber = isset($bsDef['PASIVA']['firstRowNumber']) ? $bsDef['PASIVA']['firstRowNumber'] : 1;
		$rowNumber = intval($bsDef['PASIVA']['firstRow']);
		$tbl = intval($bsDef['PASIVA']['table']);
		$cntRows = intval($bsDef['PASIVA']['cntRows'] ?? 0);

		// -- new mode
		if (isset($bsDef['PASIVA']['rows']))
		{
			foreach ($bsDef['PASIVA']['rows'] as $tax_report_rn => $table_row_number)
			{
				$trn = intval($table_row_number) - $rowNumber - $firstRowNumber + 1;
				$colBase = 'C_'.$tbl.'_'.$trn.'_';
				$v = ['c_radku' => strval($tax_report_rn)];

				$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+0), 'kc_sled', $v, FALSE);
				$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+1), 'kc_min', $v, FALSE);

				$this->addXmlRow('VetaUD', $v);
			}
			return;
		}

		// -- old mode
		for ($rn = 1; $rn <= $cntRows; $rn++)
		{
			if (isset($bsDef['PASIVA']['enabledRows']) && !in_array($rn, $bsDef['PASIVA']['enabledRows']))
				continue;

			$trn = $rowNumber - 1;
			$colBase = 'C_'.$tbl.'_'.$trn.'_';

			$v = ['c_radku' => strval($rn)];

			$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+0), 'kc_sled', $v, FALSE);
			$this->addXmlVItemZero('att_balanceSheet', $colBase.($fc+1), 'kc_min', $v, FALSE);

			$this->addXmlRow('VetaUD', $v);

			$rowNumber++;
		}
	}

	public function createContentXml_V_UB()
	{
		$stType = $this->partsData['att_statement']['VARIANT'];
		$stDef = $this->reportVersion['statements'][$stType];

		$fc = intval($stDef['VZZ']['firstColumn']);
		$firstRowNumber = isset($stDef['VZZ']['firstRowNumber']) ? $stDef['VZZ']['firstRowNumber'] : 1;
		$rowNumber = intval($stDef['VZZ']['firstRow']);
		$tbl = intval($stDef['VZZ']['table']);
		$cntRows = intval($stDef['VZZ']['cntRows']);

		// -- new mode
		if (isset($stDef['VZZ']['rows']))
		{
			foreach ($stDef['VZZ']['rows'] as $tax_report_rn => $table_row_number)
			{
				$trn = intval($table_row_number) - $rowNumber - $firstRowNumber + 1;
				$colBase = 'C_'.$tbl.'_'.$trn.'_';
				$v = ['c_radku' => strval($tax_report_rn)];

				$this->addXmlVItemZero('att_statement', $colBase.($fc+0), 'kc_sled', $v, FALSE);
				$this->addXmlVItemZero('att_statement', $colBase.($fc+1), 'kc_min', $v, FALSE);

				$this->addXmlRow('VetaUB', $v);
			}
			return;
		}

		// -- old mode
		for ($rn = 1; $rn <= $cntRows; $rn++)
		{
			if (isset($stDef['VZZ']['enabledRows']) && !in_array($rn, $stDef['VZZ']['enabledRows']))
				continue;

			$trn = $rowNumber - 1;
			$colBase = 'C_'.$tbl.'_'.$trn.'_';

			$v = ['c_radku' => strval($rn)];

			$this->addXmlVItemZero('att_statement', $colBase.($fc+0), 'kc_sled', $v, FALSE);
			$this->addXmlVItemZero('att_statement', $colBase.($fc+1), 'kc_min', $v, FALSE);

			$this->addXmlRow('VetaUB', $v);

			$rowNumber++;
		}
	}

	function addXmlVItem2 ($partId, $partKey, $destKey, &$dest, $excludeBlank = TRUE)
	{
		$value = NULL;

		$value = $this->partColumnXmlValue ($partId, $partKey, $excludeBlank);

		if ($value === NULL)
			return;


		$dest[$destKey] = $value;
	}

	function addXmlVItemZero ($partId, $partKey, $destKey, &$dest, $excludeBlank = TRUE)
	{
		$value = NULL;

		$value = $this->partColumnXmlValue ($partId, $partKey, $excludeBlank);

		if ($value === NULL)
			$value = 0;

		$dest[$destKey] = $value;
	}

	public function createContentXml ()
	{
		$this->createContentXml_Begin();

		$this->createContentXml_V_D();
		$this->createContentXml_V_P();
		$this->createContentXml_V_O();
		$this->createContentXml_V_UE();
		$this->createContentXml_V_F();
		$this->createContentXml_V_G();
		$this->createContentXml_V_VI();
		$this->createContentXml_V_J();
		$this->createContentXml_V_L();
		$this->createContentXml_V_M();
		$this->createContentXml_V_N();
		$this->createContentXml_V_Q();
		$this->createContentXml_V_S();

		$this->createContentXml_V_UA();
		$this->createContentXml_V_UB();
		$this->createContentXml_V_UD();

		$this->createContentXml_End();

		$fn = utils::tmpFileName('xml', 'dppo');
		file_put_contents($fn, $this->xml);

		return $fn;
	}

	public function loadData ()
	{
		$this->reportVersionId = $this->tableTaxReports->reportVersion($this->taxReportRecData);
		$this->reportVersion = $this->tableTaxReports->reportVersion($this->taxReportRecData, TRUE);

		$this->loadData_Parts();
	}

	public function loadData_Parts()
	{
		$this->partsData = [];
		$this->partsDefs = [];

		$q = [];
		array_push ($q, 'SELECT * FROM [e10doc_taxes_reportsParts] AS parts ');
		array_push ($q, ' WHERE parts.[report] = %i', $this->taxReportNdx);
		array_push ($q, ' ORDER BY [order]');

		$rows = $this->app()->db()->query($q);

		foreach ($rows as $r)
		{
			$partData = ($r['data'] != '') ? json_decode($r['data'], TRUE) : [];
			$this->checkPartData($r['partId'], $partData);
			$this->partsData[$r['partId']] = $partData;

			$partDef = $this->tableReportsParts->partDefinition ($this->taxReportRecData, $this->reportVersionId, $r['partId']);
			$this->partsDefs[$r['partId']] = $partDef;

			foreach ($partData as $key => $value)
			{
				$colDef = utils::searchArray($partDef['fields']['columns'], 'id', $key);
				$pv = $this->app()->subColumnValue($colDef, $value);
				$this->data['parts'][$r['partId']][$key] = $pv;
			}

			$this->data['partsDef'][$r['partId']] = $partDef;
			$partTable = uiutils::renderSubColumns ($this->app(), $partData, $partDef['fields']);
			$this->data['partsTables'][$r['partId']] = $partTable;
		}

		$this->data['partsData'] = $this->partsData;
	}

	protected function checkPartData ($partId, &$partData)
	{
		if ($partId === 'part1')
		{
			$partData['zdobd_od'] = $this->taxReportRecData['datePeriodBegin'];
			$partData['zdobd_do'] = $this->taxReportRecData['datePeriodEnd'];
			$partData['uc_zav'] = 'A';

			$this->data['flags']['forma'][$partData['dapdpp_forma']] = 'X';

			$vatReg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$this->taxReportRecData['taxReg'], NULL);
			if ($vatReg)
				$partData['dic'] = substr($vatReg['taxId'], 2);
		}
	}

	function addXmlVItem ($partId, $partKey, &$dest, $excludeBlank = TRUE)
	{
		$value = NULL;

		$value = $this->partColumnXmlValue ($partId, $partKey, $excludeBlank);

		if ($value === NULL)
			return;


		$dest[$partKey] = $value;
	}

	function partColumnXmlValue ($partId, $partKey, $excludeBlank = TRUE)
	{
		if (!$this->partsDefs)
			return NULL;
		$value = $this->partsData[$partId][$partKey] ?? NULL;

		$colDef = utils::searchArray($this->partsDefs[$partId]['fields']['columns'], 'id', $partKey);

		$colType = ($colDef) ? $colDef['type'] : '';

		//if ($colType === 'long' && $excludeBlank && $value == 0)
		//	return NULL;
		if ($colType === 'long')
			return intval($value);

		if (!isset($this->partsData[$partId][$partKey]))
			return NULL;

		if ($colType === 'logical')
			return ($value) ? 'A' : 'N';

		if ($colType === 'date')
		{
			$d = utils::createDateTime($value);
			if ($d)
				return $d->format('d.m.Y');

			return NULL;
		}

		return $value;
	}

	function addXmlRow ($itemId, $row)
	{
		if (!count($row))
			return;

		$xml = '<'.$itemId;
		foreach ($row as $k => $v)
		{
			if ($v instanceof \DateTimeInterface)
				$xml .= ' ' . $k . '="' . $v->format('d.m.Y') . '"';
			else
				$xml .= ' '.$k.'="'.utils::es($v).'"';
		}
		$xml .= " />\n";

		$this->xml .= $xml;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
			'text' => 'Uložit jako XML soubor pro elektronické podání', 'icon' => 'system/actionDownload',
			'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'xml',
			'data-filename' => $this->saveAsFileName('xml')
		];
	}

	public function saveAsFileName ($type)
	{
		$fn = $this->taxReportRecData['title'];
		$fn .= '.xml';
		return $fn;
	}

	public function saveReportAs ()
	{
		$this->createContent_All();

		$this->fullFileName = $this->createContentXml();
		$this->saveFileName = $this->saveAsFileName ('xml');
		$this->mimeType = 'application/xml';
	}
}
