class ShipardClientIoT {
	init ()
  {
    shc.on ('change', 'input.mac-shp-triggger', function () {shc.iot.mainTrigger(this);});
  }

  mainTrigger(element)
  {
    let payload = {};

    // -- switch setup scene
    if (element.classList.contains('shp-iot-scene-switch'))
    {
      let attrSetupSID = element.getAttribute('data-shp-iot-setup');
      if (attrSetupSID === undefined)
      {
        console.log("unknown iot setup");
        return;
      }
      let attrSetupSceneId = element.getAttribute('data-shp-scene-id');
      if (attrSetupSceneId === undefined)
      {
        console.log("unknown iot setup scene id");
        return;
      }
      payload['scene'] = attrSetupSceneId;
      let setTopic = uiData['iotSubjects'][attrSetupSID]['topic'] + '/set';
      //console.log(setTopic, payload);
      shc.mqtt.publish(uiData['iotSubjects'][attrSetupSID]['wss'], setTopic, JSON.stringify(payload));
      return;
    }

    // -- other controls
    if (element.classList.contains('shp-iot-primary-switch') || element.classList.contains('shp-iot-group-switch'))
      payload['state'] = element.checked ? 'ON' : 'OFF';
    else if (element.classList.contains('shp-iot-br-range'))
      payload['brightness'] = element.value;
    else if (element.classList.contains('shp-iot-ct-range'))
      payload['color_temp'] = element.value;

    if (Object.keys(payload).length === 0 && payload.constructor === Object)
      return;

    let attrDeviceSID = element.getAttribute('data-shp-iot-device');
    if (attrDeviceSID === undefined)
    {
      console.log("unknown iot device");
      return;
    }

    let deviceSIDs = attrDeviceSID.split(',');
    for (const deviceSID of deviceSIDs) {
      if (uiData['iotSubjects'] === undefined || uiData['iotSubjects'][deviceSID] === undefined)
      {
        console.log("Invalid device SID: ", deviceSID);
        continue;
      }

      let setTopic = uiData['iotSubjects'][deviceSID]['topic'] + '/set';

      //console.log(setTopic, payload);
      shc.mqtt.publish(uiData['iotSubjects'][deviceSID]['wss'], setTopic, JSON.stringify(payload));
    }
  }

  initIoT ()
  {
    // console.log('initIoT');
    shc.mqtt.init();
    shc.iot.init();
  }
}
