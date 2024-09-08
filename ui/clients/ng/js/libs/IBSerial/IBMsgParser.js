class IBMsgParser {

  itemTypes = {
    unknown: 0,
    deviceCfg: 1,
    deviceInfo: 2,
  };

  itemType = this.itemTypes.unknown;

  deviceId = null;
  deviceNdx = 0;

  logItem = null;
  deviceCfg = null;

  parseMsg (msg)
  {
    this.logItem = null;
    this.deviceCfg = null;
    this.deviceId = null;
    this.deviceNdx = 0;

    console.log("parseMsg: ", msg);

    let parts = msg.split(';');
    console.log(parts);
    if (parts[0] === '[<<<]')
      this.parsePublishedPayload(parts);
  }

  parsePublishedPayload(parts)
  {
    parts.shift(); // remove [<<<]
    var topic = parts.shift();
    var payload = parts.join(';');
    if (topic === 'shp/iot-boxes-cfg')
      this.parsePublishedPayload_deviceCfg(payload);
  }

  parsePublishedPayload_deviceCfg(payload)
  {
    this.itemType = this.itemTypes.deviceCfg;
    this.deviceCfg = JSON.parse(payload);

    let linted = JSON.stringify(this.deviceCfg, null, 2);
    this.logItem = "<div style='border-bottom: 1px solid red;'><pre>" + this.escapeHtml(linted) + '</pre></div>';

    console.log("DEVICE-CFG: ", payload);
  }

  escapeHtml (str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }
}
