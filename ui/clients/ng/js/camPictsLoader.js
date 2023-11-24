class ShipardCamsPictsLoader
{
  camerasTimer = null;

  init()
  {
    this.reloadImages();
  }

  reloadImages()
  {
    //console.log('reload-images', uiData['iotCamServers']);
    if (this.camerasTimer) {
      clearTimeout(this.camerasTimer);
    }

    for (let serverNdx in uiData['iotCamServers'])
    {
      const urlPath = uiData['iotCamServers'][serverNdx]['camUrl'] + "campicts";
      shc.server.get (urlPath,
        function (data) {
          this.setPictures(serverNdx, data);
        }.bind(this),
        function (data) {
          console.log("--load-error--");
        }.bind(this),
        1
      );
    }

    this.camerasTimer = setTimeout(function() {this.reloadImages()}.bind(this), 3000);
  }

  setPictures(serverNdx, data)
  {
    //console.log('set-pictures', data);
    const server = uiData['iotCamServers'][serverNdx];
    for (let camNdx in data)
    {
      if ( data[camNdx]['image'] === false)
      {
        continue;
      }
      let camId = 'CMP' + camNdx;
      if (uiData['iotCamPictures'][camId] === undefined)
        continue;

      let pictUrl = '';
      let ids = uiData['iotCamPictures'][camId]['elms'];
      for (var key in ids)
      {
        let camPictElement = document.getElementById(ids[key]);
        if (!camPictElement)
        {
          //console.log("Invalid element", key);
          //uiData['iotCamPictures'][camId]['elms'][key];
          continue;
        }

        let pictStyle = camPictElement.getAttribute('data-pict-style');

        if (pictStyle === 'video')
        {
          let videoElement = camPictElement.querySelector('video');

          const played = parseInt(camPictElement.getAttribute('data-stream-started'));
          if (!played)
          {
            this.startVideoRTC(videoElement);
            camPictElement.setAttribute('data-stream-started', '1');
          }
        }
        else
        {
          if (pictStyle === 'full')
            pictUrl = server['camUrl'] + 'imgs/' + camNdx + '/' + data[camNdx]['image'];
          else
            pictUrl = server['camUrl'] + 'imgs/-w960/-q70/' + camNdx + '/' + data[camNdx]['image'];

          let imgElement = camPictElement.querySelector('img');
          imgElement.src = pictUrl;
        }
      }
    }
  }

  startVideoRTC (videoEl)
  {
    const url = videoEl.getAttribute('data-stream-url');
    const webrtc = new RTCPeerConnection({
      iceServers: [{
        urls: ['stun:stun.l.google.com:19302']
      }],
      sdpSemantics: 'unified-plan'
    });
    webrtc.ontrack = function (event)
    {
      console.log(event.streams.length + ' track is delivered');
      videoEl.srcObject = event.streams[0];
      videoEl.play();
    };
    webrtc.addTransceiver('video', { direction: 'sendrecv' });
    webrtc.onnegotiationneeded = async function handleNegotiationNeeded () {
      const offer = await webrtc.createOffer();
      await webrtc.setLocalDescription(offer);
      fetch(url, {
        method: 'POST',
        body: new URLSearchParams({ data: btoa(webrtc.localDescription.sdp) })
      })
        .then(response => response.text())
        .then(data => {
          try {
            webrtc.setRemoteDescription(
              new RTCSessionDescription({ type: 'answer', sdp: atob(data) })
            );
          } catch (e) {
            console.warn(e);
          }
        });
    };

    const webrtcSendChannel = webrtc.createDataChannel('rtsptowebSendChannel');
    webrtcSendChannel.onopen = (event) => {
      console.log(`${webrtcSendChannel.label} has opened`);
      webrtcSendChannel.send('ping');
    };
    webrtcSendChannel.onclose = (_event) => {
      console.log(`${webrtcSendChannel.label} has closed`);
      startPlay(videoEl, url);
    };
    webrtcSendChannel.onmessage = event => console.log(event.data);
  }
}
