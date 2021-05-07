<?php

namespace mac\lan\dataView;

use \lib\dataView\DataView, e10\utils;


/**
 * Class LanSchema
 * @package mac\lan\dataView
 */
class LanSchema extends DataView
{
	var $tablePlaces;
	var $devicesKinds;
	var $sd = [];

	protected function init()
	{
		$this->tablePlaces = $this->app()->table('e10.base.places');

		if (isset($this->requestParams['mainPlace']))
		{
			$list = [];
			$this->tablePlaces->loadParentsPlaces(intval($this->requestParams['mainPlace']), $list);
			$this->requestParams['places'] = $list;
		}

		$this->checkRequestParamsList('deviceKinds');
		$this->checkRequestParamsList('rack');
		$this->checkRequestParamsList('lan');

		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
	}

	protected function loadData()
	{
		$this->loadDataRacks();
		$this->loadDataDevices();
		$this->loadDataDevicesConnections();
	}

	protected function loadDataRacks()
	{
		$q [] = 'SELECT racks.*, lans.shortName as lanShortName FROM [mac_lan_racks] AS racks';
		//array_push ($q, ' LEFT JOIN e10_base_places AS places ON racks.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON racks.lan = lans.ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND racks.docStateMain = %i', 2);

		if (isset($this->requestParams['rack']))
			array_push ($q, ' AND racks.[ndx] IN %in', $this->requestParams['rack']);

		if (isset($this->requestParams['lan']))
			array_push ($q, ' AND racks.[lan] IN %in', $this->requestParams['lan']);

		array_push ($q, ' ORDER BY racks.[fullName], racks.[ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['ndx' => $r['ndx'], 'name' => $r['fullName'], 'id' => $r['id'], 'devices' => []];
			$this->sd['racks'][$r['ndx']] = $item;
		}
	}

	protected function loadDataDevices()
	{
		$q [] = 'SELECT devices.*, lans.shortName as lanShortName, racks.fullName AS rackName';
		array_push ($q, ' FROM [mac_lan_devices] as devices');
		//array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_racks AS racks ON devices.rack = racks.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND devices.docStateMain = %i', 2);

		array_push ($q, ' AND devices.[rack] != %i', 0);

		if (isset($this->requestParams['lan']))
			array_push ($q, ' AND devices.[lan] IN %in', $this->requestParams['lan']);

		//if (isset($this->requestParams['deviceKinds']))
		//	array_push ($q, ' AND devices.[deviceKind] IN %in', $this->requestParams['deviceKinds']);

		array_push ($q, ' ORDER BY devices.[fullName], devices.[ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['id' => $r['id'], 'name' => $r['fullName'], 'typeName' => $r['deviceTypeName']];

			$this->sd['devices'][$r['ndx']] = $item;
			$this->sd['racks'][$r['rack']]['devices'][$r['ndx']] = $item;
		}
	}

	protected function loadDataDevicesConnections()
	{
		$q [] = 'SELECT ports.*, devices.fullName as deviceName, devices.id as deviceId, ';
		array_push ($q, ' devices.rack as srcRack, srcRacks.fullName as srcRackName, connectedDevices.rack as dstRack, connectedDevices.fullName AS dstDeviceName, ');
		array_push ($q, ' ports.device as srcDevice, connectedPorts.device as dstDevice, connectedPorts.portId AS connectedPortId ');
		array_push ($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON ports.connectedToPort = connectedPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS connectedDevices ON connectedPorts.device = connectedDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS srcRacks ON devices.rack = srcRacks.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND devices.docStateMain < 4');
		array_push ($q, ' AND ports.[portKind] IN %in', [5, 6]);
		array_push ($q, ' AND connectedPorts.[portKind] IN %in', [5, 6]);
		array_push ($q, ' AND ports.[connectedTo] = 2');
		array_push ($q, ' AND ports.[connectedToPort] != 0');
		array_push ($q, ' AND connectedPorts.[connectedToPort] != 0');
		array_push ($q, ' ORDER BY srcRacks.fullName, devices.fullName, ports.rowOrder, connectedPorts.portNumber, devices.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!isset($this->sd['racks'][$r['srcRack']]) || !isset($this->sd['racks'][$r['dstRack']]))
				continue;
			if (!isset($this->sd['devices'][$r['srcDevice']]) || !isset($this->sd['devices'][$r['dstDevice']]))
				continue;

			$srcRack = 'R'.$r['srcRack'];
			$dstRack = 'R'.$r['dstRack'];

			$srcDevice = 'N'.$r['srcDevice'];
			$dstDevice = 'N'.$r['dstDevice'];

			$srcPortItem = ['ndx' => $r['ndx'], 'name' => $r['portId'], 'deviceNdx' => $r['device'], 'deviceName' => $r['deviceName']];
			$dstPortItem = ['ndx' => $r['connectedToPort'], 'name' => $r['connectedPortId'], 'deviceNdx' => $r['connectedToDevice'], 'deviceName' => $r['dstDeviceName']];

			$this->sd['devices'][$r['srcDevice']]['ports'][$r['ndx']] = ['order' => $r['rowOrder'], 'from' => $srcPortItem, 'to' => $dstPortItem];
			//$this->sd['devices'][$r['dstDevice']]['ports'][$r['connectedToPort']] = ['to' => $srcPortItem, 'from' => $dstPortItem];

			$linkId = "{$r['srcDevice']}:{$r['ndx']}-{$r['dstDevice']}:{$r['connectedToPort']}";
			$reverseLinkId = "{$r['dstDevice']}:{$r['connectedToPort']}-{$r['srcDevice']}:{$r['ndx']}";

			if (!isset($this->sd['connections'][$linkId]) && !isset($this->sd['connections'][$reverseLinkId]))
			{
				$this->sd['connections'][$linkId] = ['from' => $srcPortItem, 'to' => $dstPortItem];
				//$headLabel = utils::es($r['connectedPortId']);
				//$listLinks[$link] = "\t".$link . " [dir=none,fontname=Arial,color=navy,taillabel=\"" . utils::es($r['portId']) . "\", headlabel=\"$headLabel\",fontsize=8];\n";
			}
		}
	}

	protected function renderData()
	{
		$this->renderDataTables();
		$this->renderDataGraph();
	}

	protected function renderDataTables()
	{
		$this->data['html'] = '';

		foreach ($this->sd['racks'] as $rackNdx => $rackDef)
		{
			$t = [];
			$h = ['srcDevice' => 'Odkud', 'srcPort' => 'Port', 'dstDevice' => 'Kam', 'dstPort' => 'Port',];
			foreach ($rackDef['devices'] as $deviceNdx => $deviceDef)
			{
				if (!isset($this->sd['devices'][$deviceNdx]['ports']))
					continue;

				$rowNdx = 0;
				$ports = \e10\sortByOneKey($this->sd['devices'][$deviceNdx]['ports'], 'order', TRUE);
				$cntPorts = count($ports);
				foreach ($ports as $portNdx => $portDef)
				{
					$item = ['srcPort' => $portDef['from']['name'], 'dstPort' => $portDef['to']['name']];
					$item['dstDevice'] = $portDef['to']['deviceName'];

					if (!$rowNdx)
					{
						$item['srcDevice'] = $deviceDef['name'];
						if ($cntPorts > 1)
							$item['_options']['rowSpan'] = ['srcDevice' => $cntPorts];
					}

					$t[] = $item;
					$rowNdx++;
				}
			}

			if (!count($t))
				continue;

			$this->data['html'] .= "<h3>".utils::es($rackDef['name']).'</h3>';
			$this->data['html'] .= \e10\renderTableFromArray ($t, $h, [], $this->app());
		}

		//$this->checkTableHeader();
		//$this->data['html'] = \e10\renderTableFromArray ($this->data['table'], $this->data['header'], [], $this->app());
	}

	protected function renderDataGraph()
	{
		$srcCode = $this->graphSrcCode();
		$gvFileName = utils::tmpFileName('dot');
		file_put_contents($gvFileName, $srcCode);
		$svgFileName = 'imgcache/lan-'.time() . '-' . mt_rand (1000000, 999999999) . '.svg';
		$cmd = "dot -Tsvg $gvFileName -o ".__APP_DIR__.'/'.$svgFileName;
		exec ($cmd);

		$this->data['schemaFileName'] = $svgFileName;
	}

	protected function graphSrcCode()
	{
		$s = '';
		$deviceSrcCode = '';
		$devices = [];

		$rankDir = 'LR';
		if (isset($this->requestParams['graphOrientation']) && $this->requestParams['graphOrientation'] === 'landscape')
			$rankDir = 'TB';

		$s .= "digraph lansite  {";
		$s .= "\tgraph [rankdir={$rankDir},splines=ortho, nodesep=1.5] node[width=1.8,height=.225,fontsize=12]\n";


		foreach ($this->sd['racks'] as $rackNdx => $rackDef)
		{
			$rackLabel = utils::es($rackDef['name']);

			$s .= "\tsubgraph cluster_R{$rackNdx} {\n";
			$s .= "\t\tnode [style=filled];fontname=Arial;label=\"$rackLabel\"; style=filled; shape=box3d; color=gray;fillcolor=\"aliceblue\"\n";

			foreach ($rackDef['devices'] as $deviceNdx => $deviceDef)
			{
				if (!isset($this->sd['devices'][$deviceNdx]['ports']))
					continue;

				$s .= "\t\tnode [] D{$deviceNdx};\n";

				$deviceLabel = utils::es($deviceDef['name']);

				$deviceSrcCode .= "\tD{$deviceNdx} [label=\"$deviceLabel\",dir=none,height=.7,shape=box3d,fontname=Arial,color=gray,fillcolor=\"#fafafa\"];\n";
			}

			$s .= "\t}\n"; // -- rack end
		}

		//$this->sd['connections'][$linkId] = ['from' => $srcPortItem, 'to' => $dstPortItem];
		if (isset($this->sd['connections']))
		{
			foreach ($this->sd['connections'] as $linkId => $linkDef)
			{
				$linkCode = '';
				$linkCode .= "D{$linkDef['from']['deviceNdx']} -> D{$linkDef['to']['deviceNdx']}";

				$labelFrom = utils::es($linkDef['from']['name']);
				$labelTo = utils::es($linkDef['to']['name']);

				$linkCode .= "[dir=none,fontname=Arial,color=navy,taillabel=\"{$labelFrom}\", headlabel=\"{$labelTo}\",fontsize=8];";


				$s .= "\t" . $linkCode . "\n";
			}
		}

		$s .= $deviceSrcCode;
		$s .= "}\n";

		return $s;
	}
}




/*
digraph lansite  {
	graph [rankdir=TB,splines=ortho, nodesep=1.5] node[width=1.8,height=.225,fontsize=12]
	subgraph cluster_R6 {
		node [style=filled];fontname=Arial;label="H1-A"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N214;
		node [] N223;
	}

	subgraph cluster_R7 {
		node [style=filled];fontname=Arial;label="H1-B"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N215;
		node [] N224;
	}

	subgraph cluster_R8 {
		node [style=filled];fontname=Arial;label="H2-A"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N216;
		node [] N225;
	}

	subgraph cluster_R4 {
		node [style=filled];fontname=Arial;label="H3-A1"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N217;
		node [] N233;
	}

	subgraph cluster_R5 {
		node [style=filled];fontname=Arial;label="H3-B"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N218;
		node [] N226;
	}

	subgraph cluster_R9 {
		node [style=filled];fontname=Arial;label="H4-A"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N211;
		node [] N212;
		node [] N213;
		node [] N221;
		node [] N222;
		node [] N210;
	}

	subgraph cluster_R10 {
		node [style=filled];fontname=Arial;label="H4-B"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N219;
		node [] N227;
	}

	subgraph cluster_R11 {
		node [style=filled];fontname=Arial;label="H5-A"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N220;
		node [] N228;
	}

	subgraph cluster_R12 {
		node [style=filled];fontname=Arial;label="HS-A"; style=filled; shape=box3d; color=gray;fillcolor="aliceblue"
		node [] N229;
	}
	N214 -> N211 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp2",fontsize=8];
	N223 -> N221 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp1",fontsize=8];
	N215 -> N211 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp3",fontsize=8];
	N224 -> N221 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp2",fontsize=8];
	N216 -> N211 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp4",fontsize=8];
	N225 -> N221 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp3",fontsize=8];
	N217 -> N212 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp3",fontsize=8];
	N233 -> N221 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp4",fontsize=8];
	N218 -> N212 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp4",fontsize=8];
	N226 -> N222 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp1",fontsize=8];
	N211 -> N213 [dir=none,fontname=Arial,color=navy,taillabel="eth24", headlabel="eth24",fontsize=8];
	N211 -> N210 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp1",fontsize=8];
	N212 -> N210 [dir=none,fontname=Arial,color=navy,taillabel="sfp1", headlabel="sfp2",fontsize=8];
	N212 -> N213 [dir=none,fontname=Arial,color=navy,taillabel="sfp2", headlabel="sfp1",fontsize=8];
	N213 -> N219 [dir=none,fontname=Arial,color=navy,taillabel="sfp2", headlabel="sfp1",fontsize=8];
	N213 -> N220 [dir=none,fontname=Arial,color=navy,taillabel="sfp3", headlabel="sfp1",fontsize=8];
	N221 -> N222 [dir=none,fontname=Arial,color=navy,taillabel="eth24", headlabel="eth24",fontsize=8];
	N222 -> N210 [dir=none,fontname=Arial,color=navy,taillabel="eth23", headlabel="eth2",fontsize=8];
	N222 -> N227 [dir=none,fontname=Arial,color=navy,taillabel="sfp2", headlabel="sfp1",fontsize=8];
	N222 -> N228 [dir=none,fontname=Arial,color=navy,taillabel="sfp3", headlabel="sfp1",fontsize=8];
	N222 -> N229 [dir=none,fontname=Arial,color=navy,taillabel="sfp4", headlabel="sfp1",fontsize=8];
	N214 [label="H1-A-1-U\lIT0308\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N223 [label="H1-A-2-K\lIT0299\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N215 [label="H1-B-1-U\lIT0326\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N224 [label="H1-B-2-K\lIT0327\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N216 [label="H2-A-1-U\lIT0307\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N225 [label="H2-A-2-K\lIT0305\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N217 [label="H3-A-1-U\lIT0286\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N233 [label="H3-A-2-K\lIT0325\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N218 [label="H3-B-1-U\lIT0303\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N226 [label="H3-B-2-K\lIT0304\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N211 [label="H4-A-1-U\lIT0311\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N212 [label="H4-A-2-U\lIT0312\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N213 [label="H4-A-3-U\lIT0314\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N221 [label="H4-A-4-K\lIT0294\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N222 [label="H4-A-5-K\lIT0293\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N210 [label="H4-A-5-R\lIT0315\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N219 [label="H4-B-1-U\lIT0298\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N227 [label="H4-B-2-K\lIT0296\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N220 [label="H5-A-1-U\lIT0278\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N228 [label="H5-A-2-K\lIT0321\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
	N229 [label="HS-A-1-K\lIT0328\r",dir=none,height=.9,shape=box3d,fontname=Arial,color=gray,fillcolor="#fafafa"];
}
 */
