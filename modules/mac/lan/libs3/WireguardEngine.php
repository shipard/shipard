<?php

namespace mac\lan\libs3;

use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * Class WireguardEngine
 */
class WireguardEngine extends Utility
{
  var $serverNdx = 0;
  var $serverRecData = NULL;
  var $clients = [];

  var $peerNdx = 0;
  var $peerRecData = NULL;


  public function setServer($serverNdx)
  {
    $this->serverNdx = $serverNdx;
    $this->serverRecData = $this->app()->loadItem($serverNdx, 'mac.lan.wgServers');
    if ($this->serverRecData)
      $this->loadClients();
  }

  protected function loadClients()
  {
    $q = [];
    array_push($q, 'SELECT *');
    array_push($q, ' FROM [mac_lan_wgPeers]');
    array_push($q, ' WHERE [wgServer] = %i', $this->serverNdx);
    array_push($q, ' AND [docState] = %i', 4000);
    array_push($q, ' ORDER BY [id]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->clients[] = $r->toArray();
    }
  }

  public function setPeer($peerNdx)
  {
    $this->peerNdx = $peerNdx;
    $this->peerRecData = $this->app()->loadItem($peerNdx, 'mac.lan.wgPeers');

    $this->serverNdx = $this->peerRecData['wgServer'];
    $this->serverRecData = $this->app()->loadItem($this->serverNdx, 'mac.lan.wgServers');
  }

  public function createCfgPeer()
  {
    $allowedIPs = '0.0.0.0/0,::/0';
    $persistentKeepalive = 25;

    $c = '';
    $c .= "### ".$this->peerRecData['id']." ###\n";

    $c .= "[Interface]\n";
    $c .= "PrivateKey = ".$this->peerRecData['peerKeyPrivate']."\n";

    $c .= "Address = ".$this->peerRecData['peerAddr4'];
    if ($this->peerRecData['peerAddr6'] !== '')
      $c .= ','.$this->peerRecData['peerAddr6'];
    $c .= "\n";

    if ($this->serverRecData['dns'] !== '')
      $c .= 'DNS = '.$this->serverRecData['dns']."\n";

    $c .= "\n";

    $c .= "[Peer]\n";
    $c .= "PublicKey = ".$this->serverRecData['keyPublic']."\n";
    $c .= "PresharedKey = ".$this->peerRecData['peerKeyPreshared']."\n";

    $c .= "AllowedIPs = ".$allowedIPs."\n";
    $c .= "Endpoint = ".$this->serverRecData['endpoint']."\n";
    $c .= "PersistentKeepalive = ".$persistentKeepalive."\n";

    return $c;
  }

	public function createPeerQRCode()
	{
    $peerCfg = $this->createCfgPeer();
    $peerCfgFileName = Utils::tmpFileName('txt', 'peer_cfg_'.$this->peerRecData['ndx']);
    file_put_contents($peerCfgFileName, $peerCfg);

    $peerQRCodeBaseFileName = 'peer_qr_'.$this->peerRecData['ndx'].'_'.time().'_'.mt_rand(100000, 999999).'.svg';
    $peerQRCodeFullFileName = __APP_DIR__ .'/tmp/'.$peerQRCodeBaseFileName;

		$cmd = "qrencode -t SVG --rle -o \"{$peerQRCodeFullFileName}\" -r \"{$peerCfgFileName}\"";
		exec ($cmd);

    $url = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/tmp/'.$peerQRCodeBaseFileName;
    return $url;
	}

  public function createCfgServerLinux()
  {
    $wgInterfaceId = 'wg0';

    $c = '';
    $c .= "[Interface]\n";

    $c .= "SaveConfig = false\n";
    $c .= "Address = ".$this->serverRecData['ifaceAddr4'];
    if ($this->serverRecData['ifaceAddr6'] !== '')
    $c .= ','.$this->serverRecData['ifaceAddr6'];
    $c .= "\n";

    $c .= "ListenPort = ".$this->serverRecData['listenPort']."\n";
    $c .= "PrivateKey = ".$this->serverRecData['keyPrivate']."\n";

    $c .= "PostUp = /etc/wireguard/postUp_{$wgInterfaceId}.sh \"%i\"\n";
    $c .= "PreDown = /etc/wireguard/preDown_{$wgInterfaceId}.sh \"%i\"\n";

    $c .= "\n";

    foreach ($this->clients as $client)
    {
      $c .= "[Peer]\n";
      $c .= "### ".$client['id']." ###\n";
      $c .= "PublicKey = ".$client['peerKeyPublic']."\n";
      $c .= "PresharedKey = ".$client['peerKeyPreshared']."\n";

      $c .= "AllowedIPs = ".$client['peerAddr4'];
      if ($client['peerAddr6'] !== '')
        $c .= ','.$client['peerAddr6'];
      $c .= "\n";
      $c .= "\n";
    }

    return $c;
  }

  public function createCfgServerLinuxPostUp()
  {
    $wgInterfaceId = $this->serverWGIfaceId();

    $c = "#!/bin/bash\n";
    $c .= "### /etc/wireguard/postUp_{$wgInterfaceId}.sh\n\n";
    $c .= "ufw route allow in on wg0 out on eth0\n";
    $c .= "iptables -t nat -I POSTROUTING -o eth0 -j MASQUERADE\n";
    $c .= "ip6tables -t nat -I POSTROUTING -o eth1 -j MASQUERADE\n";

    return $c;
  }

  public function createCfgServerLinuxPreDown()
  {
    $wgInterfaceId = $this->serverWGIfaceId();

    $c = "#!/bin/bash\n";
    $c .= "### /etc/wireguard/preDown_{$wgInterfaceId}.sh\n\n";
    $c .= "ufw route delete allow in on wg0 out on eth0\n";
    $c .= "iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE\n";
    $c .= "ip6tables -t nat -D POSTROUTING -o eth1 -j MASQUERADE\n";

    return $c;
  }

  protected function serverWGIfaceId()
  {
    return 'wg0';
  }

  public function generateKeyPair(&$privateKey, &$publicKey)
  {
    /*
    openssl genpkey -algorithm X25519 -outform der -out privatekey.der
    openssl pkey -inform der -in privatekey.der -pubout -outform der -out pubkey.der
    cat pubkey.der | tail -c 32 | base64 > pubkey.txt
    cat privatekey.der | tail -c 32 | base64 > privatekey.txt
    */

    $fnPrivateKeyDer = Utils::tmpFileName('der', 'privatekey_der');
    $fnPubKeyDer = Utils::tmpFileName('der', 'pubkey_der');
    $fnPrivateKeyTxt = Utils::tmpFileName('txt', 'privatekey_txt');
    $fnPubKeyTxt = Utils::tmpFileName('txt', 'pubkey_txt');

    exec("openssl genpkey -algorithm X25519 -outform der -out $fnPrivateKeyDer");
    exec("openssl pkey -inform der -in $fnPrivateKeyDer -pubout -outform der -out $fnPubKeyDer");
    exec("cat $fnPubKeyDer | tail -c 32 | base64 > $fnPubKeyTxt");
    exec("cat $fnPrivateKeyDer | tail -c 32 | base64 > $fnPrivateKeyTxt");

    $privKey = file_get_contents($fnPrivateKeyTxt);
    $pubKey = file_get_contents($fnPubKeyTxt);

    $privateKey = $privKey;
    $publicKey = $pubKey;

    unlink($fnPrivateKeyDer);
    unlink($fnPubKeyDer);
    unlink($fnPrivateKeyTxt);
    unlink($fnPubKeyTxt);
  }

  public function generateKeyPreshared(&$presharedKey)
  {
    /*
    openssl rand 32 | base64 > psk.txt
    */
    $fnPresharedKeyTxt = Utils::tmpFileName('txt', 'psk_txt');
    exec("openssl rand 32 | base64 > $fnPresharedKeyTxt");
    $pskKey = file_get_contents($fnPresharedKeyTxt);
    $presharedKey = $pskKey;
    unlink($fnPresharedKeyTxt);
  }
}
