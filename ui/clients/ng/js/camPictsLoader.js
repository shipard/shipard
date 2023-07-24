class ShipardCamsPictsLoader
{
  camerasTimer = null;

  init()
  {
    this.reloadImages();
  }

  reloadImages()
  {
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
          uiData['iotCamPictures'][camId]['elms'][key];
          continue;
        }

        let pictStyle = camPictElement.getAttribute('data-pict-style');

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
