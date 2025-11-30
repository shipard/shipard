class ShipardCamsPictsLoader
{
  init()
  {
    this.reloadImages();
  }

  reloadImages()
  {
    this.setPictures();
  }

  setPictures()
  {
    for (let camId in uiData['iotCamPictures'])
    {
      let ids = uiData['iotCamPictures'][camId]['elms'];
      for (var key in ids)
      {
        let camPictElement = document.getElementById(ids[key]);
        if (!camPictElement)
        {
          continue;
        }

        let pictStyle = camPictElement.getAttribute('data-pict-style');

        if (pictStyle === 'video')
        {
          const played = parseInt(camPictElement.getAttribute('data-stream-started'));
          if (!played)
          {
            this.startVideoGO2RTC(camPictElement);
            camPictElement.setAttribute('data-stream-started', '1');
          }
        }
      }
    }
  }

  startVideoGO2RTC (videoEl)
  {
    let streamUrl = videoEl.getAttribute('data-stream-url');
    let streamId = videoEl.getAttribute('data-stream-id');
    let videoMode = videoEl.getAttribute('data-stream-mode');

    const video = document.createElement('video-stream');
    video.src = new URL('api/ws?src=' + encodeURIComponent(streamId), streamUrl);
    if (videoMode)
      video.mode = videoMode;
    videoEl.appendChild(video);
  }
}
