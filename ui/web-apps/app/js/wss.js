function wssInit()
{
	if (g_useMqtt)
		return;

	for (var i in webSocketServers)
	{
		wssStartServer (i);
	}
}

function wssStartServer (serverIndex, disableMessage)
{
	var ws = webSocketServers[serverIndex];

	if (ws.wsUrl === '')
		return;

	ws.server = null;
	ws.retryTimer = 0;

	wssSetState (serverIndex, 'connect');

	if ("WebSocket" in window)
		ws.server = new WebSocket(ws.wsUrl);
	else
	if ("MozWebSocket" in window)
		ws.server = new MozWebSocket(ws.wsUrl);

	ws.server.onopen = function() {wssSetState (serverIndex, 'open');};
	ws.server.onerror = function(evt) {
		wssSetState (serverIndex, 'error');
	};
	ws.server.onclose = function() {
		wssSetState (serverIndex, 'error');
		webSocketServers[serverIndex].retryTimer = setTimeout (function (){wssStartServer(serverIndex, 1);}, 3000);
	};
	ws.server.onmessage = function(e) {wssOnMessage (serverIndex, e.data);}
}

function wssSetState (serverIndex, socketState)
{
	var ws = webSocketServers[serverIndex];
	var serverIcon = $('#wss-'+ws.id);
	serverIcon.attr('class','e10-wss e10-wss-'+socketState);

	if (socketState === 'open')
	{
		ws.connected = 1;
		if (ws.sendData !== null)
		{
			for (let i = 0; i < ws.sendData.length; i++) 
			{
				mqttPublish(0, ws.sendData[i].topic, ws.sendData[i].payload);
			}
			ws.sendData = null;
		}
	}
	else
		ws.connected = 0;
}

function wssOnMessage (serverIndex, stringData)
{
	var data =  JSON.parse(stringData);
	console.log (data);
	if (!data)
		return;

	if (data.cmd)
	{
		if (data.cmd === 'reload-remote-element')
		{
			for (var i in data.elements)
			{
				var elementId = data.elements[i];
				webActionReloadElement($('#'+elementId));
			}
		}
		return;
	}
}

function wssSendMessage(serverIndex, data)
{
	var ws = webSocketServers[serverIndex];

	var options = {
		type: 'POST',
		crossDomain: true,
		url: ws.postUrl,
		success: function () {console.log ('sent....');},
		data: JSON.stringify(data),
		dataType: 'json',

		error: function (data) {
			console.log("========================ERROR: ");
		}
	};
	$.ajax(options);
}


// -- mqtt clients
function initMQTT ()
{
	if (!g_useMqtt)
		return;
	for (var i in webSocketServers)
	{
		mqttStartClient (i);
	}
}

function mqttStartClient (serverIndex, disableMessage)
{
	var ws = webSocketServers[serverIndex];

	if (ws.fqdn === '')
		return;

	ws.retryTimer = 0;
	ws.connected = 0;
	if (ws.sendData === undefined)
		ws.sendData = null;

	ws.mqttClient = new Paho.MQTT.Client(ws.fqdn, ws.port, deviceId+"-"+Math.random().toString(36));

	ws.mqttClient.onConnectionLost = function() {
		wssSetState (serverIndex, 'error');
		webSocketServers[serverIndex].retryTimer = setTimeout (function (){mqttStartClient(serverIndex, 1);}, 3000);
	};
	ws.mqttClient.onMessageArrived = function(message) {mqttOnMessage (serverIndex, message);};


	ws.mqttClient.connect({
				onSuccess:function(){wssSetState (serverIndex, 'open'); mqttSubscribeAll (serverIndex);},
				onFailure:function(){wssSetState (serverIndex, 'error'); webSocketServers[serverIndex].retryTimer = setTimeout (function (){mqttStartClient(serverIndex, 1);}, 3000);},
				useSSL: true
			}
	);
}

function mqttSubscribeAll (serverIndex)
{
	var ws = webSocketServers[serverIndex];
	if (ws.topics === undefined)
		return;

	for (var topic in ws.topics)
	{
		//var si = ws.topics[i];
		ws.mqttClient.subscribe(topic);
	}
}

function mqttSubscribe (serverIndex, topic)
{
	if (!serverIndex)
		serverIndex = 1;
	var ws = webSocketServers[serverIndex];
	
	ws.mqttClient.subscribe(topic);
}

function mqttOnMessage (serverIndex, data)
{
	//console.log("onMessageArrived: `"+data.destinationName+"` "+data.payloadString);

	var ws = webSocketServers[serverIndex];

	if (data.payloadString[0] === '{')	
	{ // JSON DATA
		var payloadData = JSON.parse(data.payloadString);

		if (payloadData.cmd)
		{
			if (payloadData.cmd === 'reload-remote-element')
			{
				for (var i in payloadData.elements)
				{
					var elementId = payloadData.elements[i];
					webActionReloadElement($('#'+elementId));
				}
			}
			return;
		}	
	}

	if (ws.topics === undefined)
		return;
	if (ws.topics[data.destinationName] === undefined)
		return;

	let sensorInfo = ws.topics[data.destinationName];

	let mainMenuElementId = 'mqtt-sensor-' + sensorInfo['sensorId'];
	let mainMenuElement = $('#' + mainMenuElementId);
	if (mainMenuElement.length)
	{
		mainMenuElement.find('span.value').text(data.payloadString);
	}

	if (sensorInfo.flags['login'] !== undefined && sensorInfo.flags['login'])
	{
		let keyInput = $('#e10-lf-authKey #authKey');
		if (keyInput.length)
		{
			keyInput.val(data.payloadString);
			let form = $('#e10-lf-authKey');
			form.submit();
		}
	}

	if (sensorInfo.flags['kbd'] !== undefined && sensorInfo.flags['kbd'])
	{
		let currentInput = $(':focus');

		if (!currentInput.length)
			currentInput = $('#e10-main-mqtt-input');

		if (currentInput.length) {
			currentInput.val(data.payloadString);
			currentInput.trigger('change');
			if (currentInput.hasClass('e10-viewer-search'))
				viewerIncSearch(currentInput, null, 1);
			if (currentInput.hasClass('e10-mqtt-submit-form'))
				currentInput.parent().submit();
		}
	}
}

function mqttPublish (serverIndex, topic, payload)
{
	if (!serverIndex)
		serverIndex = 1;

	var ws = webSocketServers[serverIndex];
	if (ws.connected === 1)
	{
		ws.mqttClient.send(topic, payload);
	}
	else
	{
		if (ws.sendData === undefined || ws.sendData === null)
			ws.sendData = [];

		ws.sendData.push({'topic': topic, 'payload': payload});
	}
}

function mqttPublishData (serverIndex, topic, payloadData)
{
	mqttPublish(serverIndex, topic, JSON.stringify (payloadData));
}
