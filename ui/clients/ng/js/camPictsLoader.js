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
            this.startVideoGO2RTC(camPictElement);
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

  startVideoGO2RTC (videoEl)
  {
    let streamUrl = videoEl.getAttribute('data-stream-url');
    let streamId = videoEl.getAttribute('data-stream-id');

    const video = document.createElement('video-stream');
    video.src = new URL('api/ws?src=' + encodeURIComponent(streamId), streamUrl);
    video.mode = 'webrtc/tcp';
    videoEl.appendChild(video);
  }
}
