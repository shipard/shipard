var EOT = '\u0004\u0003';

class ShipardWidgetIBSerialTerm extends ShipardWidgetBoard
{
  port = null;
  reader = null;
  writer = null;
  encoder = new TextEncoder();
  decoder = new TextDecoder();

  elmLog = null;
  elmSendCmdInput = null;
  elmSendCmdButton = null;
  elmDeviceCfgInput = null;
  deviceCfgEditor = null;

  init (rootElm)
  {
    super.init(rootElm);
    //this.getReader();
    this.elmLog = this.rootElm.querySelector('div.log');

    this.elmSendCmdInput = this.rootElm.querySelector('input.input-cmd');
    this.elmSendCmdButton = this.rootElm.querySelector('button.button-send-cmd');
    this.elmDeviceCfgInput = this.rootElm.querySelector('div.device-cfg-input');

    console.log("INIT MONACO....");
    this.deviceCfgEditor = monaco.editor.create(this.elmDeviceCfgInput, {
            language: 'json',
            theme: "vs-dark",
            scrollBeyondLastLine: false,
            minimap: {
              enabled: false
            },
            automaticLayout: true
        });

    this.elmSendCmdInput.addEventListener("keypress", function(event) {
      if (event.key === "Enter") {
        event.preventDefault();
        this.elmSendCmdButton.click();
      }
    }.bind(this));
  }

  doAction (actionId, e)
  {
    console.log("ACTION-SERIAL: ", actionId);

    switch (actionId)
    {
      case 'connectSerialPort': return this.doConnect(e);
      case 'sendCmd': return this.doSendCmd(e);
      case 'getDeviceCfg': return this.doSendCmd(e, 'cmd:getCfg');
      case 'setDeviceCfg': return this.doSetDeviceCfg(e);
    }

    return super.doAction (actionId, e);
  }

  doWidgetResponse(data)
  {
    //console.log("VS-WIDGET-RESPONSE");
    super.doWidgetResponse(data);
  }

  doConnect(e)
  {
    this.getReader();
    return 1;
  }

  doSendCmd(e, command)
  {
    if (command !== undefined)
    {
      this.write(">>>" + command + "\n");
      return 1;
    }
    console.log("SEND...");
    //let input = this.rootElm.querySelector('input');
    let cmd = this.elmSendCmdInput.value;
    this.write(cmd + "\n");

    return 1;
  }

  doSetDeviceCfg(e)
  {
    const strSrcCfg = this.deviceCfgEditor.getValue();

    var dataCfg = null;
    try {
      dataCfg = JSON.parse(strSrcCfg);
    } catch {
      alert ("syntax error");
      return;
    }


    console.log("__SET_DEVICE_CFG__", dataCfg);

    let cmd = 'cmd:setCfg '+JSON.stringify(dataCfg);

    console.log("send: "+cmd);
    this.doSendCmd(e, cmd);
  }

  async getReader()
  {
    await navigator.serial.requestPort({})
    .then((port) => {
      this.port = port;
      this.onPortOpen();
      // Connect to `port` or add it to the list of available ports.
    })
    .catch((e) => {
      // The user didn't select a port.
      console.log("__CANCEL__");
      return;
    });
  }

  async read()
  {
    while (this.port.readable) {
      let chunks = '';

      try {
        while (true)
        {
          const { value, done } = await this.reader.read();
          const decoded = this.decoder.decode(value);

          chunks += decoded;

          if (done || chunks.includes("\n")) {
            reader.releaseLock();
            break;
          }
        }
        return chunks;
      } catch (error) {
        console.log(error);
        throw error;
      } finally {
        reader.releaseLock();
      }
    }
  }

  async serial_readResponse() {
    let response = '';

    while (!response.endsWith('\n')) {
        let data = await this.reader.read();

        if (data.value !== undefined) {
            response += this.decoder.decode(data.value);
        }

        if (data.done === true) {
            break;
        }
    }

    // Trim the trailing newline
    /*
    if (response.endsWith('\n'))
      response = response.slice(0, response.length - 1);
    if (response.endsWith('\r'))
      response = response.slice(0, response.length - 1);
    */
    return response.split('\n');
  }

  async write (data)
  {
    const dataArrayBuffer = this.encoder.encode(data);
    return await this.writer.write(dataArrayBuffer);
  }

  async onPortOpen()
  {
    console.log("Port opened...");

    navigator.serial.addEventListener("connect", (event) => {this.onConnect(event);});
    this.port.addEventListener("disconnect", (event) => {this.onDisconnect(event);});
    await this.port.open({ baudRate: 115200 });


    this.reader = this.port.readable.getReader();
    this.writer = this.port.writable.getWriter();

    const signals = await this.port.getSignals();
    console.log(signals);

    //const portInfo = this.port.getInfo();
    //console.log(portInfo);

    while(1)
    {
      let msg = await this.serial_readResponse();
      //console.log(msg);

      for (let msgId in msg)
      {
        if (msg[msgId] === '' || msg[msgId] === '\r')
          continue;
        let parser = new IBMsgParser();
        parser.parseMsg(msg[msgId]);

        if (parser.logItem)
          this.elmLog.innerHTML = parser.logItem + this.elmLog.innerHTML;
        else
        {
          let html = "<div style='border-bottom: 1px solid gray;'>" + this.escapeHtml(msg[msgId]) + '</div>';
          this.elmLog.innerHTML = html + this.elmLog.innerHTML;
        }

        if (parser.itemType === parser.itemTypes.deviceCfg)
        {
          this.deviceCfgEditor.setValue(JSON.stringify(parser.deviceCfg, null, 2));
        }
      }
    }
  }

  onPortClose()
  {
    console.log("Port closed...");
  }

  onConnect(event)
  {
    console.log("onConnect", event);
  }

  onDisconnect(event)
  {
    console.log("onDisconnect", event);
  }
}

function initWidgetIBSerialTerm(id)
{
  let e = document.getElementById(id);
  e.shpWidget = new ShipardWidgetIBSerialTerm();
  e.shpWidget.init(e);

  console.log("IBSerialTerm", id);
}
