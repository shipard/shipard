class ShipardMqtt {
	init ()
  {
    //console.log("ShipardMqtt.init");

	  if (typeof Paho == 'undefined')
		  return;

    for (var i in webSocketServers)
      this.startClient (i);
  }

  startClient (serverIndex)
  {
    var ws = webSocketServers[serverIndex];

    if (ws.fqdn === null || ws.fqdn === '')
      return;
    let portNumber = parseInt(ws.port);
    if (portNumber === 0)
      return;

    ws.retryTimer = 0;

    ws.mqttClient = new Paho.MQTT.Client(ws.fqdn, portNumber, deviceId+'-'+Math.random().toString(36));
    ws.mqttClient.onConnectionLost = function (xxxx) { console.log(xxxx),
      setTimeout (() => shc.mqtt.setState (serverIndex, 'cnlst'), 200);
      webSocketServers[serverIndex].retryTimer = setTimeout (() => shc.mqtt.startClient(serverIndex, 1), 3000);
    };
    ws.mqttClient.onMessageArrived = (message) => shc.mqtt.onMessage (serverIndex, message);

    ws.mqttClient.connect({
        onSuccess:() => {shc.mqtt.setState (serverIndex, 'open'); shc.mqtt.subscribeAll (serverIndex);},
        onFailure: () => {shc.mqtt.setState (serverIndex, 'error'); webSocketServers[serverIndex].retryTimer = setTimeout (() => shc.mqtt.startClient(serverIndex, 1), 3000);},
        useSSL: true
      }
    );
  }

  subscribeAll (serverIndex)
  {
    //console.log ("SUBSCRIBE-ALL", JSON.stringify(serverIndex));

    var ws = webSocketServers[serverIndex];
    //ws.mqttClient.subscribe('#');


    for (const oneTopic in uiData['iotTopicsMap']) {
      //console.log("SUBS: ", oneTopic);
      ws.mqttClient.subscribe(oneTopic);
    }

    for (const oneTopic in uiData['iotTopicsMap']) {
      //console.log("GET: ", oneTopic);

      if (uiData['iotTopicsMap'][oneTopic]['type'] === 'device' || uiData['iotTopicsMap'][oneTopic]['type'] === 'scene')
      {
        let message = new Paho.MQTT.Message('{"state": ""}');
        if (oneTopic.endsWith('/'))
          message.destinationName = oneTopic+'get';
        else
          message.destinationName = oneTopic+'/get';
        console.log("GET: ", message.destinationName);
        ws.mqttClient.send(message);
      }
    }
  }

  onMessage (serverIndex, data)
  {
    var ws = webSocketServers[serverIndex];
    console.log("mqtt#"+ws.id+": `"+data.destinationName+"` `"+data.payloadString+"`");

    shc.mqtt.setElementValue(serverIndex, data);

    return;
  }

  setElementValue(serverIndex, data)
  {
    let payload = null;
    if (data.payloadString[0] === '{' || data.payloadString[0] === '[')
    {
      payload = JSON.parse(data.payloadString);
      if ('_states' in payload)
        payload = payload['_states'];
    }
    else
      payload = {value: data.payloadString};

    console.log("__PAYLOAD: ", payload);

    if (uiData['iotTopicsMap'] === undefined)
    {
      console.log("Missing uiData topics map");
      return;
    }

    let topicInfo = uiData['iotTopicsMap'][data.destinationName];
    if (topicInfo === undefined)
    {
      console.log("Missing topic info in uiData");
      return;
    }

    for (let i = 0; i < topicInfo['elids'].length; i++)
    {
      let elid = topicInfo['elids'][i];
      let mqttItem = document.getElementById(elid);
      if (!mqttItem)
      {
        console.log("NOT EXIST", elid);
        continue;
      }

      let family = mqttItem.getAttribute('data-shp-family');
      if (family === 'iot-sensor')
      {
        let valueElement = mqttItem.querySelector('span.value');
        valueElement.textContent = payload.value;
        //console.log('setElementValue / sensor: ', payload.value);
      }
      else if (family === 'iot-light')
      {
        let switchElement = mqttItem.getElementsByClassName('shp-iot-primary-switch');
        if (switchElement.length > 0)
        {
          let propertyId = switchElement[0].getAttribute('data-shp-iot-state-id');
          if (propertyId === null)
            propertyId = 'state';
          if (payload[propertyId] !== undefined)
          {
            if (switchElement[0].disabled)
              switchElement[0].disabled = false;

            let valueOn = switchElement[0].getAttribute('data-shp-value-on');
            if (!valueOn)
              valueOn = 'ON';

            switchElement[0].checked = payload[propertyId] === valueOn;
          }
        }
        if (payload['brightness'] !== undefined)
        {
          let brElement = mqttItem.getElementsByClassName('shp-iot-br-range');
          if (brElement.length > 0)
          {
            if (brElement[0].disabled)
              brElement[0].disabled = false;
            brElement[0].value = payload['brightness'].toString();
          }
        }
        if (payload['color_temp'] !== undefined)
        {
          //console.log ('### COLOR_TEMP ###', payload);
          let ctElement = mqttItem.getElementsByClassName('shp-iot-ct-range');
          if (ctElement.length > 0)
          {
            if (ctElement[0].disabled)
              ctElement[0].disabled = false;
            ctElement[0].value = payload['color_temp'].toString();
          }
        }

        setTimeout(function (){shc.mqtt.checkGroups()}, 100);
      }
      else if (family === 'iot-setup-scene')
      {
        //console.log ('### SETUP SCENE ###', data.destinationName, payload);
        if (payload['scene'] !== undefined)
        {
          let scElement = mqttItem.querySelectorAll("[data-shp-scene-id='"+payload['scene']+"']");
          if (scElement.length > 0)
            scElement[0].checked = true;
        }
      }
    }
  }

  checkGroups()
  {
    for (const groupId in uiData['iotElementsGroups']) {
      this.checkGroup(groupId);
    }
  }

  checkGroup(groupId)
  {
    let groupMainElement = document.getElementById(groupId);
    if (!groupMainElement)
    {
      console.log('Invalid element for group: ', groupId);
      return;
    }

    let stateOnOff = 0;

    for (const groupElementNdx in uiData['iotElementsGroups'][groupId]) {
      let sid = uiData['iotElementsGroups'][groupId][groupElementNdx];

      //console.log(" --> check sid ", sid);

      let topicId = uiData['iotSubjects'][sid]['topic'];
      let elid = uiData['iotTopicsMap'][topicId]['elids'][0];
      let mqttItem = document.getElementById(elid);
      if (!mqttItem)
      {
        console.log('element not exist: ', elid);
        continue;
      }

      let switchElement = mqttItem.getElementsByClassName('shp-iot-primary-switch');
      if (switchElement.length > 0)
      {
        //console.log('test2');
        if (switchElement[0].checked)
          stateOnOff = 1;
      }
    }

    let switchElement = groupMainElement.getElementsByClassName('shp-iot-group-switch');
    //console.log("set to "+stateOnOff+": ", switchElement);
    if (switchElement.length > 0)
    {
      if (switchElement[0].disabled)
        switchElement[0].disabled = false;

      if (stateOnOff && !switchElement[0].checked)
        switchElement[0].checked = true;
      else if (!stateOnOff && switchElement[0].checked)
        switchElement[0].checked = false;
    }
  }


  setState (serverIndex, socketState)
  {
    var ws = webSocketServers[serverIndex];
    /*
    var serverIcon = $('#wss-'+ws.id);
    serverIcon.attr('class','e10-wss e10-wss-'+socketState);
    */
    //console.log(socketState, ws);
  }

  publish (serverIndex, topic, payload)
  {
    var ws = webSocketServers[serverIndex];
    let message = new Paho.MQTT.Message(payload);
    message.destinationName = topic;
    ws.mqttClient.send(message);
  }

	applyUIData (responseUIData)
	{
		console.log("ShipardMqtt - apply uiData: ", responseUIData);
	}
}
