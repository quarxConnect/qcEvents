<?PHP

  /**
   * qcEvents - SSH Stream
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Promise.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Stream/SSH/Message.php');
  require_once ('qcEvents/Stream/SSH/KeyExchangeInit.php');
  require_once ('qcEvents/Stream/SSH/KeyExchangeDHInit.php');
  require_once ('qcEvents/Stream/SSH/NewKeys.php');
  require_once ('qcEvents/Stream/SSH/ServiceRequest.php');
  require_once ('qcEvents/Stream/SSH/UserAuthRequest.php');
  require_once ('qcEvents/Stream/SSH/ChannelOpen.php');
  require_once ('qcEvents/Stream/SSH/Channel.php');
  require_once ('qcEvents/Stream/SSH/PublicKey.php');
  require_once ('qcEvents/Stream/SSH/PrivateKey.php');
  
  // Make sure we have GMP available
  if (!extension_loaded ('gmp') && (!function_exists ('dl') || !dl ('gmp.so'))) {
    trigger_error ('GMP required');
    
    return;
  }
  
  class qcEvents_Stream_SSH extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* State of our stream */
    const STATE_NONE = 0;
    const STATE_CONNECT = 1;
    const STATE_AUTH = 2;
    const STATE_READY = 3;
    
    private $State = qcEvents_Stream_SSH::STATE_NONE;
    
    /* Instance of our source-stream */
    private $Stream = null;
    
    /* Receive-Buffer */
    private $Buffer = '';
    
    /* Pending header for next message */
    private $nextMessage = null;
    
    /* Mode of this stream */
    const MODE_CLIENT = 1;
    const MODE_SERVER = 2; # Unimplemented
    
    private $Mode = qcEvents_Stream_SSH::MODE_CLIENT;
    
    /* SSH-Versions of both sides */
    private $remoteVersion = null;
    private $localVersion = 'SSH-2.0-qcEventsSSH_0.1';
    
    /* ID of this session */
    private $sessionID = null;
    
    /* Message-Counters */
    private $sequenceLocal = 0;
    private $sequenceRemote = 0;
    
    /* Known hashing-methods */
    const HASH_NONE = 0;
    const HASH_MD5 = 1;
    const HASH_SHA1 = 2;
    const HASH_SHA256 = 3;
    
    private static $hashNames = array (
      'md5'    => qcEvents_Stream_SSH::HASH_MD5,
      'sha1'   => qcEvents_Stream_SSH::HASH_SHA1,
      'sha256' => qcEvents_Stream_SSH::HASH_SHA256,
    );
    
    /* Negotiated Key-Exchange */
    const KEX_DH1 = 1;
    const KEX_DH14 = 2;
    
    private static $keyExchangeNames = array (
      'diffie-hellman-group1-sha1'  => array (qcEvents_Stream_SSH::KEX_DH1,  qcEvents_Stream_SSH::HASH_SHA1),
      'diffie-hellman-group14-sha1' => array (qcEvents_Stream_SSH::KEX_DH14, qcEvents_Stream_SSH::HASH_SHA1),
      # 'ecdh-sha2-nistp256' => null,
      # 'ecdh-sha2-nistp384' => null,
      # 'ecdh-sha2-nistp521' => null,
      # 'curve25519-sha256@libssh.org' => null,
      # 'kexguess2@matt.ucc.asn.au' => null,
    );
    
    private $keyExchange = null;
    
    /* Messages sent for exechange-init */
    private $localKeyExchange = null;
    private $remoteKeyExchange = null;
    
    /* Negotiated Cipher-Algorithm */
    /* @see https://tools.ietf.org/html/rfc4253#section-6.3 */
    const CIPHER_NONE = 0;
    const CIPHER_3DES = 1;
    const CIPHER_BLOWFISH = 2;
    const CIPHER_TWOFISH = 3; # Unimplemented (ks 256, 192, 128)
    const CIPHER_AES = 4;
    const CIPHER_SERPENT = 5; # Unimplemented (ks 256, 192, 128)
    const CIPHER_RC4 = 6;
    const CIPHER_IDEA = 7;
    const CIPHER_CAST = 8;
    
    const CIPHER_MODE_STREAM = 0;
    const CIPHER_MODE_CBC = 1;
    const CIPHER_MODE_CTR = 2;
    
    private static $cipherNames = array (
      'none'           => array (qcEvents_Stream_SSH::CIPHER_NONE,       0, qcEvents_Stream_SSH::CIPHER_MODE_STREAM,  8, null),
      '3des-cbc'       => array (qcEvents_Stream_SSH::CIPHER_3DES,     192, qcEvents_Stream_SSH::CIPHER_MODE_CBC,     8, 'des-ede3-cbc'),
      '3des-ctr'       => array (qcEvents_Stream_SSH::CIPHER_3DES,     192, qcEvents_Stream_SSH::CIPHER_MODE_CTR,     8, 'des-ede3-ctr'),
      'blowfish-cbc'   => array (qcEvents_Stream_SSH::CIPHER_BLOWFISH, 128, qcEvents_Stream_SSH::CIPHER_MODE_CBC,     8, 'bf-cbc'),
      'blowfish-ctr'   => array (qcEvents_Stream_SSH::CIPHER_BLOWFISH, 128, qcEvents_Stream_SSH::CIPHER_MODE_CTR,     8, 'bf-ctr'),
      'twofish-cbc'    => array (qcEvents_Stream_SSH::CIPHER_TWOFISH,  256, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, null),
      'twofish128-cbc' => array (qcEvents_Stream_SSH::CIPHER_TWOFISH,  128, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, null),
      'twofish192-cbc' => array (qcEvents_Stream_SSH::CIPHER_TWOFISH,  192, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, null),
      'twofish256-cbc' => array (qcEvents_Stream_SSH::CIPHER_TWOFISH,  256 ,qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, null),
      'twofish128-ctr' => array (qcEvents_Stream_SSH::CIPHER_TWOFISH,  128, qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, null),
      'twofish192-ctr' => array (qcEvents_Stream_SSH::CIPHER_TWOFISH,  192, qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, null),
      'twofish256-ctr' => array (qcEvents_Stream_SSH::CIPHER_TWOFISH,  256 ,qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, null),
      'aes128-cbc'     => array (qcEvents_Stream_SSH::CIPHER_AES,      128, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, 'aes-128-cbc'),
      'aes192-cbc'     => array (qcEvents_Stream_SSH::CIPHER_AES,      192, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, 'aes-192-cbc'),
      'aes256-cbc'     => array (qcEvents_Stream_SSH::CIPHER_AES,      256, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, 'aes-256-cbc'),
      'aes128-ctr'     => array (qcEvents_Stream_SSH::CIPHER_AES,      128, qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, 'aes-128-ctr'),
      'aes192-ctr'     => array (qcEvents_Stream_SSH::CIPHER_AES,      192, qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, 'aes-192-ctr'),
      'aes256-ctr'     => array (qcEvents_Stream_SSH::CIPHER_AES,      256, qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, 'aes-256-ctr'),
      'serpent128-cbc' => array (qcEvents_Stream_SSH::CIPHER_SERPENT,  128, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, null),
      'serpent192-cbc' => array (qcEvents_Stream_SSH::CIPHER_SERPENT,  192, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, null),
      'serpent256-cbc' => array (qcEvents_Stream_SSH::CIPHER_SERPENT,  256, qcEvents_Stream_SSH::CIPHER_MODE_CBC,    16, null),
      'serpent128-ctr' => array (qcEvents_Stream_SSH::CIPHER_SERPENT,  128, qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, null),
      'serpent192-ctr' => array (qcEvents_Stream_SSH::CIPHER_SERPENT,  192, qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, null),
      'serpent256-ctr' => array (qcEvents_Stream_SSH::CIPHER_SERPENT,  256, qcEvents_Stream_SSH::CIPHER_MODE_CTR,    16, null),
      'arcfour'        => array (qcEvents_Stream_SSH::CIPHER_RC4,      128, qcEvents_Stream_SSH::CIPHER_MODE_STREAM,  8, 'rc4'),
      'idea-cbc'       => array (qcEvents_Stream_SSH::CIPHER_IDEA,     128, qcEvents_Stream_SSH::CIPHER_MODE_CBC,     8, 'idea-cbc'),
      'idea-ctr'       => array (qcEvents_Stream_SSH::CIPHER_IDEA,     128, qcEvents_Stream_SSH::CIPHER_MODE_CTR,     8, 'idea-ctr'),
      'cast128-cbc'    => array (qcEvents_Stream_SSH::CIPHER_CAST,     128, qcEvents_Stream_SSH::CIPHER_MODE_CBC,     8, 'cast-cbc'),
      'cast128-ctr'    => array (qcEvents_Stream_SSH::CIPHER_CAST,     128, qcEvents_Stream_SSH::CIPHER_MODE_CTR,     8, 'cast-ctr'),
    );
    
    private $cipherLocal  = null;
    private $cipherRemote = null;
    
    /* Negotiated MAC-Algorithm */
    /* @see https://tools.ietf.org/html/rfc4253#section-6.4 */
    private static $macNames = array (
      'none'         => array (qcEvents_Stream_SSH::HASH_NONE,  0),
      'hmac-md5'     => array (qcEvents_Stream_SSH::HASH_MD5,  16),
      'hmac-md5-96'  => array (qcEvents_Stream_SSH::HASH_MD5,  12),
      'hmac-sha1'    => array (qcEvents_Stream_SSH::HASH_SHA1, 20),
      'hmac-sha1-96' => array (qcEvents_Stream_SSH::HASH_SHA1, 12),
    );
    
    private $macLocal  = null;
    private $macRemote = null;
    
    /* Negotiated Compression-Algorithm */
    const COMPRESSION_NONE = 0;
    const COMPRESSION_ZLIB = 1; # Unimplemented
    
    private static $compressionNames = array (
      'none' => qcEvents_Stream_SSH::COMPRESSION_NONE,
      'zlib' => qcEvents_Stream_SSH::COMPRESSION_ZLIB,
    );
    
    private $compressionLocal = null;
    private $compressionRemote = null;
    
    /* Minimum size of a packet */
    private $packetMinimumSize = 16;
    
    /* Run in compability-mode */
    private $Compability = true;
    
    /* Promise-Callbacks created by initStreamConsumer() */
    private $connectPromise = null;
    
    /* Promise-Callbacks for authentication */
    private $authPromise = null;
    
    /* Number of next channel to allocate */
    private $nextChannel = 0x00000000;
    
    /* List of global requests that want a reply */
    private $Requests = array ();
    
    /* List of requested remote port-forwardings */
    private $Forwardings = array ();
    
    /* List of channels */
    private $Channels = array ();
    
    // {{{ getStream
    /**
     * Retrive our source-stream
     * 
     * @access public
     * @return qcEvents_Interface_Stream
     **/
    public function getStream () : ?qcEvents_Interface_Stream {
      return $this->Stream;
    }
    // }}}
    
    // {{{ getLocalVersion
    /**
     * Retrive the local version-string of this stream
     * 
     * @access public
     * @return string
     **/
    public function getLocalVersion () {
      return $this->localVersion;
    }
    // }}}
    
    // {{{ setLocalVersion
    /**
     * Store local version of this SSH-Stream
     * 
     * @param string $Version
     * 
     * @access public
     * @return bool
     **/
    public function setLocalVersion ($Version) {
      if ($this->State != $this::STATE_NONE)
        return false;
      
      $this->localVersion = $Version;
      
      if (substr ($this->localVersion, 0, 8) != 'SSH-2.0-')
        trigger_error ('SSH-Version should start with SSH-2.0-');
      
      return true;
    }
    // }}}
    
    // {{{ getRemoteVersion
    /**
     * Retrive version-string from our peer
     * 
     * @access public
     * @return string
     **/
    public function getRemoteVersion () {
      return $this->removeVersion;
    }
    // }}}
    
    // {{{ authPublicKey
    /**
     * Try to perform public-key-based authentication
     * 
     * @param string $Username
     * @param qcEvents_Stream_SSH_PrivateKey $rsaPrivateKey
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function authPublicKey ($Username, qcEvents_Stream_SSH_PrivateKey $PrivateKey) : qcEvents_Promise {
      // Prepare the data to be signed
      $Message = new qcEvents_Stream_SSH_UserAuthRequest;
      $Message->Username = $Username;
      $Message->Service = 'ssh-connection';
      $Message->Method = 'publickey';
      $Message->Signed = true;
      
      if ($PrivateKey->getType () == $PrivateKey::TYPE_RSA) {
        $Message->Algorithm = 'ssh-rsa';
        $Message->PublicKey = $PrivateKey->exportPublicKey ();
      } else
        # TODO: Add support for ssh-dss here
        return qcEvents_Promise::reject ('Unsupported key-type');
      
      // Prepare the data to be signed
      $Data = qcEvents_Stream_SSH_Message::writeString ($this->sessionID) . substr ($Message->toPacket (), 0, -4);
      
      $Message->Signature = $PrivateKey->signSSH ($Data);
      
      return $this->enqueueAuthMessage ($Message);
    }
    // }}}
    
    // {{{ authPassword
    /**
     * Try to perform password-based authentication
     * 
     * @param string $Username
     * @param string $Password
     * @param bool $Force (optional) Force submission of password over an unencrypted connection
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function authPassword ($Username, $Password, $Force = false) : qcEvents_Promise {
      // Make sure the connection is encrypted
      if (!$Force && ($this->cipherLocal [0] == self::CIPHER_NONE))
        return qcEvents_Promise::reject ('Will not transmit password over an unencrypted connection');
      
      // Create the message
      $Message = new qcEvents_Stream_SSH_UserAuthRequest;
      $Message->Username = $Username;
      $Message->Service = 'ssh-connection';
      $Message->Method = 'password';
      $Message->ChangePassword = false;
      $Message->Password = $Password;
      
      // Enqueue message for authentication
      return $this->enqueueAuthMessage ($Message);
    }
    // }}}
    
    // {{{ requestSession
    /**
     * Request a session-channel
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function requestSession () : qcEvents_Promise {
      // Make sure our state is good
      if ($this->State != $this::STATE_READY)
        return qcEvents_Promise::reject ('Invalid state');
      
      // Create an open-request
      $Message = new qcEvents_Stream_SSH_ChannelOpen;
      $Message->Type = 'session';
      $Message->SenderChannel = $this->nextChannel++;
      $Message->InitialWindowSize = 2097152; // 2 MB
      $Message->MaximumPacketSize = 32768;
      
      // Write out the message
      $this->writeMessage ($Message);
      
      // Prepare the channel
      $this->Channels [$Message->SenderChannel] = $Channel = new qcEvents_Stream_SSH_Channel ($this, $Message->SenderChannel, $Message->Type);
      
      // Run a callback
      $this->___callback ('channelCreated', $Channel);
      
      // Return it's promise
      return $this->Channels [$Message->SenderChannel]->getConnectionPromise ();
    }
    // }}}
    
    // {{{ requestForward
    /**
     * Request a TCP/IP-Forwarding
     * 
     * @param string $listenAddress (optional)
     * @param int $listenPort (optional)
     * @param callable $setupCallback (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function requestForward ($listenAddress = '', $listenPort = 0, callable $setupCallback = null) : qcEvents_Promise {
      // Make sure our state is good
      if ($this->State != $this::STATE_READY)
        return qcEvents_Promise::reject ('Invalid state');
      
      // Prepare the message
      $Message = new qcEvents_Stream_SSH_GlobalRequest;
      $Message->Name = 'tcpip-forward';
      $Message->Address = $listenAddress;
      $Message->Port = $listenPort;
      
      return $this->wantReply ($Message)->then (
        function ($Message) use ($listenAddress, $listenPort, $setupCallback) {
          // Get the port from the reply if needed
          if (($listenPort == 0) && (strlen ($Message->Payload) > 0)) {
            $Offset = 0;
            $listenPort = $Message::readUInt32 ($Message->Payload, $Offset);
          }
          
          // Register the forwarding
          $this->Forwardings [] = array ($listenAddress, $listenPort, $setupCallback);
          
          // Forward the result
          return new qcEvents_Promise_Solution (array ($listenAddress, $listenPort));
        }
      );
    }
    // }}}
    
    // {{{ cancelForward
    /**
     * Stop an active port-forwarding
     * 
     * @param string $listenAddress
     * @param int $listenPort
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function cancelForward ($listenAddress, $listenPort) : qcEvents_Promise {
      // Make sure we know the forwarding
      $Found = false;
      
      foreach ($this->Forwardings as $Index=>$Forwarding)
        if ($Found = (($Forwarding [0] == $listenAddress) && ($Forwarding [1] == $listenPort))) {
          unset ($this->Forwardings [$Index]);
          break;
        }
      
      if (!$Found)
        return qcEvents_Promise::reject ('Forwarding not found');
      
      // Write out the request
      $Message = new qcEvents_Stream_SSH_GlobalRequest;
      $Message->Name = 'cancel-tcpip-forward';
      $Message->Address = $listenAddress;
      $Message->Port = $listenPort;
      
      return $this->wantReply ($Message);
    }
    // }}}
    
    // {{{ requestConnection
    /**
     * Request a TCP/IP-channel
     * 
     * @param string $OriginatorHost
     * @param int $OriginatorPort
     * @param string $DestinationHost
     * @param int $DestinationPort
     * @param bool $Forwarded (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function requestConnection ($OriginatorHost, $OriginatorPort, $DestinationHost, $DestinationPort, $Forwarded = false) : qcEvents_Promise {
      // Make sure our state is good
      if ($this->State != $this::STATE_READY)
        return qcEvents_Promise::reject ('Invalid state');
      
      // Create an open-request
      $Message = new qcEvents_Stream_SSH_ChannelOpen;
      $Message->Type = ($Forwarded ? 'forwarded-tcpip' : 'direct-tcpip');
      $Message->SenderChannel = $this->nextChannel++;
      $Message->InitialWindowSize = 2097152; // 2 MB
      $Message->MaximumPacketSize = 32768;
      $Message->DestinationAddress = $DestinationHost;
      $Message->DestinationPort = $DestinationPort;
      $Message->OriginatorAddress = $OriginatorHost;
      $Message->OriginatorPort = $OriginatorPort;
      
      // Write out the message
      $this->writeMessage ($Message);
    
      // Prepare the channel
      $this->Channels [$Message->SenderChannel] = $Channel = new qcEvents_Stream_SSH_Channel ($this, $Message->SenderChannel, $Message->Type);
      
      // Run a callback
      $this->___callback ('channelCreated', $Channel);
      
      // Return it's promise
      return $this->Channels [$Message->SenderChannel]->getConnectionPromise ();
      
      return qcEvents_Promise::reject ('Unimplemented');
    }
    // }}}
    
    // {{{ removeChannel
    /**
     * Silently remove a channel from our set of channels
     * 
     * @param qcEvents_Stream_SSH_Channel $Channel
     * 
     * @access public
     * @return void
     **/
    public function removeChannel (qcEvents_Stream_SSH_Channel $Channel) {
      // Retrive the local id of the chanenl
      $localID = $Channel->getLocalID ();
      
      // Make sure the channel is hosted here
      if (!isset ($this->Channels [$localID]) ||
          ($Channel !== $this->Channels [$localID]) ||
          ($Channel->getStream () !== $this))
        return;
      
      // Remove the channel
      unset ($this->Channels [$localID]);
    }
    // }}}
    
    // {{{ startCommand
    /**
     * Try to open a session and start a command (stdin will be available, stdout/stderr won't be captured by this)
     * 
     * @param string $Command The command to execute
     * @param array $Environment (optional) Set of environment-variables to pass (not known to work, but on RFC)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function startCommand ($Command, array $Environment = null) : qcEvents_Promise {
      return $this->requestSession ()->then (
        function (qcEvents_Stream_SSH_Channel $Channel) use ($Command, $Environment) {
          // Push environment to channel
          if ($Environment)
            foreach ($Environment as $Key=>$Value)
              $Channel->setEnv ($Key, $Value);
          
          // Try to execute the command
          return $Channel->exec ($Command)->then (
            function () use ($Channel) {
              return $Channel;
            },
            function () use ($Channel) {
              // Clean up the channel
              $Channel->close ();
              
              // Forward the original error
              throw new qcEvents_Promise_Soluiton (func_get_args ());
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ runCommand
    /**
     * Try to open a session, run a command and return it's output
     * 
     * @param string $Command
     * @param array $Environment (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function runCommand ($Command, array $Environment = null) : qcEvents_Promise {
      return $this->startCommand ($Command, $Environment)->then (
        function (qcEvents_Stream_SSH_Channel $Channel) {
          // Close stdin on channel
          $Channel->eof ();
          
          // Wait for end of stream
          return $Channel->once ('eventClosed')->then (
            function () use ($Channel) {
              return $Channel->read ();
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ consume
    /**
     * Receive data from our source-stream
     * 
     * @param string $Data
     * @param qcEvents_Interface_Source $Stream
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Stream) {
      // Make sure the stream is right
      if ($this->Stream !== $Stream)
        return;
      
      // Push to internal buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Try to process data from the buffer
      $this->processBuffer ();
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      # TODO
      $this->___callback ('eventClosed');
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source) : qcEvents_Promise {
      // Check if there was another stream pending
      if ($this->connectPromise)
        call_user_func ($this->connectPromise [1], 'Replaced by another stream');
      
      // Assign the stream
      $this->Stream = $Source;
      
      // Reset our state
      $this->State = self::STATE_CONNECT;
      $this->keyExchange = null;
      $this->cipherLocal = $this->cipherRemote = self::$cipherNames ['none'];
      $this->macLocal = $this->macRemote = self::$macNames ['none'];
      $this->compressionLocal = $this->compressionRemote = self::$compressionNames ['none'];
      
      // Push our version to peer
      $this->Stream->write ($this->localVersion . "\r\n");
      
      // Create initial key-exchange-message
      $this->localKeyExchange = $Message = new qcEvents_Stream_SSH_KeyExchangeInit;
    
      $Message->KexAlgorithms = array ('diffie-hellman-group1-sha1', 'diffie-hellman-group14-sha1');
      $Message->serverHostKeyAlgortihms = array ('ssh-rsa');
      $Message->ciphersClientServer = array ('aes128-ctr', 'aes192-ctr', 'aes256-ctr');
      $Message->ciphersServerClient = array ('aes128-ctr', 'aes192-ctr', 'aes256-ctr');
      $Message->macClientServer = $Message->macServerClient = array ('hmac-sha1-96', 'hmac-sha1', 'hmac-md5-96', 'hmac-md5');
      $Message->compClientServer = $Message->compServerClient = array ('none');
      $Message->langClientServer = $Message->langServerClient = array ('');
      $Message->kexFollows = false;
    
      // Send key-exchange-message to peer
      $this->writePacket ($Message->toPacket ());
      
      // Return new promise
      return new qcEvents_Promise (
        function (callable $Resolve, callable $Reject) {
          $this->connectPromise = array ($Resolve, $Reject);
        }
      );
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      // Check if this source is actually assigned
      if ($Source === $this->Stream) {
        // Remove the source
        $this->Stream = null;
        
        // Check if we are still initializing
        if ($this->connectPromise) {
          call_user_func ($this->connectPromise [1], 'Interrupted by deinitConsumer()');
          
          $this->connectPromise = null;
        }
        
        // Raise a callback
        $this->___callback ('eventUnpiped', $Source);
      }
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    private function enqueueAuthMessage (qcEvents_Stream_SSH_Message $Message) : qcEvents_Promise {
      return $this->writeAuthMessage ($Message);
    }
    
    // {{{ writeAuthMessage
    /**
     * Push an authentication-message to the wire
     * 
     * @param qcEvents_Stream_SSH_Message $Message
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function writeAuthMessage (qcEvents_Stream_SSH_Message $Message) : qcEvents_Promise {
      return new qcEvents_Promise (
        function (callable $Resolve, callable $Reject) use ($Message) {
          // Write out the message
          $this->writeMessage ($Message);
          
          // Register the callbacks
          $this->authPromise = array ($Resolve, $Reject);
        }
      );
    }
    // }}}
    
    // {{{ wantReply
    /**
     * Write out a global request and expect a reply for it
     * 
     * @param qcEvents_Stream_SSH_GlobalRequest $Request
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function wantReply (qcEvents_Stream_SSH_GlobalRequest $Request) : qcEvents_Promise {
      return new qcEvents_Promise (
        function (callable $Resolve, callable $Reject) use ($Request) {
          // Make sure the reply-bit is set
          $Request->wantReply = true;
    
          // Push to queue
          $this->Requests [] = array ($Request, $Resolve, $Reject);
    
          // Check wheter to write out the request
          if (count ($this->Requests) == 1)
            $this->writeMessage ($Request)->catch ($Reject);
        }
      );
    }
    // }}}
    
    // {{{ writeMessage
    /**
     * Write a message to the wire
     * 
     * @param qcEvents_Stream_SSH_Message $Message
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function writeMessage (qcEvents_Stream_SSH_Message $Message) : qcEvents_Promise {
      // Sanity-check for global requests not to poison our queue
      if (($Message instanceof qcEvents_Stream_SSH_GlobalRequest) && $Message->wantReply) {
        if ((count ($this->Requests) < 1) ||
            ($this->Requests [0][0] !== $Message))
          return $this->wantReply ($Message);
      }
      
      return $this->writePacket ($Message->toPacket ());
    }
    // }}}
    
    // {{{ writePacket
    /**
     * Push a packet to the wire
     * 
     * @param string $Data
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function writePacket ($Data) : qcEvents_Promise {
      // Retrive the length of the packet
      $packetLength = strlen ($Data);
      
      // Determine number of blocks
      $blocks = ceil (($packetLength + 9) / $this->cipherLocal [3]);
      $blockSize = $blocks * $this->cipherLocal [3];
      
      // Generate padding
      $paddingLength = $blockSize - $packetLength - 5;
      $Padding = '';
      
      for ($i = 0; $i < $paddingLength; $i++)
        $Padding .= chr (rand (0, 255));
      
      // Assemble the packet
      $Packet = pack ('Nc', $packetLength + $paddingLength + 1, $paddingLength) . $Data . $Padding;
      
      // Generate the MAC
      if ($this->macLocal [0] != self::HASH_NONE)
        $MAC = substr (hash_hmac (array_search ($this->macLocal [0], self::$hashNames), qcEvents_Stream_SSH_Message::writeUInt32 ($this->sequenceLocal) . $Packet, $this->macLocal [2], true), 0, $this->macLocal [1]);
      else
        $MAC = '';
      
      $this->sequenceLocal++;
      
      // Encrypt the packet
      if ($this->cipherLocal [0] != self::CIPHER_NONE) {
        $Ciphertext = '';
        
        for ($Offset = 0; $Offset < $blockSize; $Offset += $this->cipherLocal [3]) {
          $Block = openssl_encrypt (substr ($Packet, $Offset, $this->cipherLocal [3]), $this->cipherLocal [4], $this->cipherLocal [5], OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->cipherLocal [6]);
          
          if ($this->cipherLocal [2] == self::CIPHER_MODE_CTR) {
            for ($i = strlen ($this->cipherLocal [6]) - 1; $i >= 0; $i--) {
              $c = ord ($this->cipherLocal [6][$i]);
              $this->cipherLocal [6][$i] = chr (($c + 1) & 0xFF);
              
              if ($c < 255)
                break;
            }
          } elseif ($this->cipherLocal [2] == self::CIPHER_MODE_CBC)
            $this->cipherLocal [6] = $Block;
          
          $Ciphertext .= $Block;
        }
        
        $Packet = $Ciphertext;
      }
      
      // Write out the packet
      return $this->Stream->write ($Packet . $MAC);
    }
    // }}}
    
    // {{{ processBuffer
    /**
     * Try to get packets from the buffer and process them
     * 
     * @access private
     * @return void
     **/
    private function processBuffer () {
      // Check for initial greeting
      if (($this->Mode == self::MODE_CLIENT) && ($this->remoteVersion === null))
        return $this->checkInitialGreeting ();
      
      // Check for binary packets
      $Length = strlen ($this->Buffer);
      $Offset = 0;
      
      while ($Length - $Offset >= max ($this->packetMinimumSize, $this->cipherRemote [3]) + $this->macRemote [1]) {
        // Try to decrypt first block
        if ($this->nextMessage === null) {
          $Packet = $this->decryptBlock (substr ($this->Buffer, $Offset, $this->cipherRemote [3]));
          
          // Read basic header from packet
          $this->nextMessage = unpack ('Nlength/cpadding', substr ($Packet, 0, 5));
          $this->nextMessage ['data'] = $Packet;
        } else
          $Packet = $this->nextMessage ['data'];
        
        // Check how many blocks to read
        $readBlocks = ceil (($this->nextMessage ['length'] + 4) / $this->cipherRemote [3]);
        $readLength = $readBlocks * $this->cipherRemote [3];
        
        if ($readLength > 35000)
          trigger_error ('Have to read more than 35k octets, that is propably a bug');
        
        // Make sure we have the entire packet on the buffer
        if ($Length - $Offset < $readLength + $this->macRemote [1])
          break;
        
        // Read the remaining packet-data
        for ($Block = 1; $Block < $readBlocks; $Block++)
          $Packet .= $this->decryptBlock (substr ($this->Buffer, $Offset + $Block * $this->cipherRemote [3], $this->cipherRemote [3]));
        
        // Read and verify MAC
        if ($this->macRemote [1] > 0) {
          $iMAC = substr ($this->Buffer, $Offset + $readLength, $this->macRemote [1]);
          $rMAC = substr (hash_hmac (array_search ($this->macRemote [0], self::$hashNames), qcEvents_Stream_SSH_Message::writeUInt32 ($this->sequenceRemote) . $Packet, $this->macRemote [2], true), 0, $this->macRemote [1]);
          
          if (strcmp ($iMAC, $rMAC) != 0)
            # TODO
            return trigger_error ('MAC-Check failed');
        }
        
        $this->sequenceRemote++;
        
        // Split up the packet
        $payloadLength = $this->nextMessage ['length'] - $this->nextMessage ['padding'] - 1;
        $Payload = substr ($Packet, 5, $payloadLength);
        $Padding = substr ($Packet, -$this->nextMessage ['padding'], $this->nextMessage ['padding']);
        
        # TODO: Uncompress payload
        
        $this->processPacket ($Payload, $payloadLength);
        
        // Move forward
        $Offset += $readLength + $this->macRemote [1];
        $this->nextMessage = null;
      }
      
      // Truncate data from the buffer
      if ($Offset > 0)
        $this->Buffer = substr ($this->Buffer, $Offset);
    }
    // }}}
    
    // {{{ processPacket
    /**
     * Process a packet received from the transport-layer
     * 
     * @param string $Packet
     * 
     * @access private
     * @reutrn void
     **/
    private function processPacket ($Packet, $Length) {
      // Check wheter to process anything
      if ($Length < 1)
        return;
      
      // Try to unpack the message
      $Message = qcEvents_Stream_SSH_Message::fromPacket ($Packet, $Length);
      
      // Check wheter to start a new key-exchange (may happen at any time)
      if ($Message instanceof qcEvents_Stream_SSH_KeyExchangeInit) {
        // Make sure we are not exchanging a key
        if ($this->keyExchange !== null)
          return $this->failStream ('Duplicate KeyExchangeInit-Message received');
        
        // Store the message for reference
        $this->remoteKeyExchange = $Message;
        
        // Choose which message to use as reference
        if ($this->Mode == self::MODE_CLIENT) {
          $Reference = $this->localKeyExchange;
          $Offer = $this->remoteKeyExchange;
        } else {
          $Reference = $this->remoteKeyExchnage;
          $Offer = $this->localKeyExchanage;
        }
        
        // Try to negotiate algorithms
        $this->keyExchange = new stdClass;
        
        if ((($this->keyExchange->Params = $this->negotiateAlgorithm ($Reference->KexAlgorithms, $Offer->KexAlgorithms, self::$keyExchangeNames)) === null) ||
            (($this->keyExchange->serverHostKeyAlgorithm = $this->negotiateAlgorithm ($Reference->serverHostKeyAlgortihms, $Offer->serverHostKeyAlgortihms, array ('ssh-dss' => 0, 'ssh-rsa' => 1))) === null) ||
            (($this->keyExchange->clientServerCipher = $this->negotiateAlgorithm ($Reference->ciphersClientServer, $Offer->ciphersClientServer, self::$cipherNames)) === null) ||
            (($this->keyExchange->serverClientCipher = $this->negotiateAlgorithm ($Reference->ciphersServerClient, $Offer->ciphersServerClient, self::$cipherNames)) === null) ||
            (($this->keyExchange->clientServerMAC    = $this->negotiateAlgorithm ($Reference->macClientServer, $Offer->macClientServer, self::$macNames)) === null) ||
            (($this->keyExchange->serverClientMAC    = $this->negotiateAlgorithm ($Reference->macServerClient, $Offer->macServerClient, self::$macNames)) === null) ||
            (($this->keyExchange->clientServerComp   = $this->negotiateAlgorithm ($Reference->compClientServer, $Offer->compClientServer, self::$compressionNames)) === null) ||
            (($this->keyExchange->serverClientComp   = $this->negotiateAlgorithm ($Reference->compServerClient, $Offer->compServerClient, self::$compressionNames)) === null))
          return $this->failStream ('Failed to negotiate algorithms');
        
        // Start Key-Exchange (if in client-mode)
        if ($this->Mode == self::MODE_CLIENT) {
          if ($this->keyExchange->Params [0] == self::KEX_DH1) {
            $this->keyExchange->dh_g = gmp_init (2);
            $this->keyExchange->dh_p = gmp_init ('FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE65381FFFFFFFFFFFFFFFF', 16);
          } elseif ($this->keyExchange->Params [1] == self::KEX_DH14) {
            $this->keyExchange->dh_g = gmp_init (2);
            $this->keyExchange->dh_p = gmp_init ('FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3BE39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF6955817183995497CEA956AE515D2261898FA051015728E5A8AACAA68FFFFFFFFFFFFFFFF', 16);
          } else
            return $this->failStream ('Unsupported Key-Exchange-Algorithm');
          
          $this->keyExchange->dh_q = ($this->keyExchange->dh_p - 1) / 2;
          
          $this->keyExchange->dh_x = gmp_random_range (2, $this->keyExchange->dh_q - 1);
          $this->keyExchange->dh_e = gmp_powm ($this->keyExchange->dh_g, $this->keyExchange->dh_x, $this->keyExchange->dh_p);
          
          $Reply = new qcEvents_Stream_SSH_KeyExchangeDHInit;
          $Reply->e = $this->keyExchange->dh_e;
          
          $this->writeMessage ($Reply);
        }
      
      // Check for a Key-Exchange DH-Reply
      } elseif ($Message instanceof qcEvents_Stream_SSH_KeyExchangeDHReply) {
        // Check for an ongoing kex
        if ($this->keyExchange === null)
          return $this->failStream ('Received DH-Reply when not being in key-exchange-mode');
        
        // We only accept this in client-mode
        if ($this->Mode != self::MODE_CLIENT)
          return $this->failStream ('Received DH-Reply from client');
        
        // Derive shared secret
        $this->keyExchange->secret = qcEvents_Stream_SSH_Message::writeMPInt (gmp_powm ($Message->f, $this->keyExchange->dh_x, $this->keyExchange->dh_p));
        $this->keyExchange->hash = hash (
          array_search ($this->keyExchange->Params [1], self::$hashNames),
          qcEvents_Stream_SSH_Message::writeString ($this->localVersion) .
          qcEvents_Stream_SSH_Message::writeString ($this->remoteVersion) .
          qcEvents_Stream_SSH_Message::writeString ($this->localKeyExchange->toPacket ()) .
          qcEvents_Stream_SSH_Message::writeString ($this->remoteKeyExchange->toPacket ()) .
          qcEvents_Stream_SSH_Message::writeString ($Message->serverHostKey) .
          qcEvents_Stream_SSH_Message::writeMPInt ($this->keyExchange->dh_e) .
          qcEvents_Stream_SSH_Message::writeMPInt ($Message->f) .
          $this->keyExchange->secret,
          true
        );
        
        // Check the hostkey and signature
        $PublicKey = qcEvents_Stream_SSH_PublicKey::loadFromString ($Message->serverHostKey);
        
        if (!$PublicKey->verifySSH ($this->keyExchange->hash, $Message->Signature))
          return $this->failStream ('Could not verify hostkey');
        
        $this->___callback ('authHostkey', $PublicKey);
        
        // Make sure we have a session-id
        if ($this->sessionID === null)
          $this->sessionID = $this->keyExchange->hash;
        
        // Send SSH_MSG_NEWKEYS
        $this->writeMessage (new qcEvents_Stream_SSH_NewKeys);
        
        // Assign new client-to-server-params
        $this->cipherLocal = $this->keyExchange->clientServerCipher;
        $this->macLocal = $this->keyExchange->clientServerMAC;
        $this->compressionLocal = $this->keyExchange->clientServerComp;
        
        $h = array_search ($this->keyExchange->Params [1], self::$hashNames);
        
        $this->cipherLocal [] = $this->deriveKey ('C', $this->cipherLocal [1] / 8);
        $this->cipherLocal [] = $this->deriveKey ('A', $this->cipherLocal [3]);
        $this->macLocal [] = $this->deriveKey ('E');
        
        // Move to authentication-state
        if ($this->State == self::STATE_CONNECT) {
          $Reply = new qcEvents_Stream_SSH_ServiceRequest;
          $Reply->Service = 'ssh-userauth';
          
          $this->writeMessage ($Reply);
        }
      
      // Remote party switches to new keys
      } elseif ($Message instanceof qcEvents_Stream_SSH_NewKeys) {
        // Check for an ongoing kex
        if ($this->keyExchange === null)
          return $this->failStream ('Remote party switches to new keys without having exchanged new keys');
        
        // Assign new server-to-client-params
        $h = array_search ($this->keyExchange->Params [1], self::$hashNames);
        
        if ($this->Mode == self::MODE_CLIENT) {
          $this->cipherRemote = $this->keyExchange->serverClientCipher;
          $this->macRemote = $this->keyExchange->serverClientMAC;
          $this->compressionRemote = $this->keyExchange->serverClientComp;
          
          $this->cipherRemote [] = $this->deriveKey ('D', $this->cipherRemote [1] / 8);
          $this->cipherRemote [] = $this->deriveKey ('B', $this->cipherRemote [3]);
          $this->macRemote [] = $this->deriveKey ('F');
          
        // Assign new client-to-server-params
        } else {
          $this->cipherRemote = $this->keyExchange->clientServerCipher;
          $this->macRemote = $this->keyExchange->clientServerMAC;
          $this->compressionRemote = $this->keyExchange->clientServerComp;
          
          $this->cipherRemote [] = $this->deriveKey ('C', $this->cipherRemote [1] / 8);
          $this->cipherRemote [] = $this->deriveKey ('A', $this->cipherRemote [3]);
          $this->macRemote [] = $this->deriveKey ('E');
        }
      
      // A new service was accepted
      } elseif (($Message instanceof qcEvents_Stream_SSH_ServiceAccept) && ($this->State == self::STATE_CONNECT)) {
        if ($Message->Service == 'ssh-userauth') {
          $this->State = self::STATE_AUTH;
          
          // Check if we were initiating
          if ($this->connectPromise) {
            // Resolve the promise
            call_user_func ($this->connectPromise [0]);
            $this->connectPromise = null;
            
            // Raise an event for this
            $this->___callback ('eventPipedStream', $this->Stream);
          }
        }
      
      // A response to an ongoing authentication-process was received
      } elseif ((($Message instanceof qcEvents_Stream_SSH_UserAuthSuccess) ||
                 ($Message instanceof qcEvents_Stream_SSH_UserAuthFailure) ||
                 ($Message instanceof qcEvents_Stream_SSH_UserAuthPublicKeyOK)) &&
                ($this->State == self::STATE_AUTH)) {
        // Change our state
        if ($Message instanceof qcEvents_Stream_SSH_UserAuthSuccess)
          $this->State = self::STATE_READY;
        
        // Resolve any promise
        if ($this->authPromise) {
          // Resolve the promise
          if ($Message instanceof qcEvents_Stream_SSH_UserAuthFailure)
            call_user_func ($this->authPromise [1], $Message->PartialSuccess, $Message->Methods);
          else
            call_user_func ($this->authPromise [0]);
          
          $this->authPromise = null;
          
          // Raise an event for this
          if ($Message instanceof qcEvents_Stream_SSH_UserAuthSuccess)
            $this->___callback ('authSuccessfull');
        }
        
      // Push any authentication-related banner to an event
      } elseif (($Message instanceof qcEvents_Stream_SSH_UserAuthBanner) && ($this->State == self::STATE_AUTH)) {
        $this->___callback ('authBanner', $Message->Message, $Message->Language);
      
      // Process global request
      } elseif (($Message instanceof qcEvents_Stream_SSH_GlobalRequest) && ($this->State == self::STATE_READY)) {
        trigger_error ('Unhandled global request: ' . $Message->Name);
      
      } elseif ((($Message instanceof qcEvents_Stream_SSH_RequestSuccess) ||
                 ($Message instanceof qcEvents_Stream_SSH_RequestFailure)) &&
                ($this->State == self::STATE_READY)) {
        // Make sure there is a request pending at all
        if (count ($this->Requests) < 1)
          return trigger_error ('Received reply for global request without pending one');
        
        // Get the request from queue
        $RequestInfo = array_shift ($this->Requests);
        
        // Resolve or reject the promise
        call_user_func ($RequestInfo [($Message instanceof qcEvents_Stream_SSH_RequestSuccess ? 1 : 2)], $Message);
        
        // Check wheter to write out the next request
        if (count ($this->Requests) > 0)
          $this->writeMessage ($this->Requests [0][0])->catch ($this->Requests [0][2]);
      
      // Process channel-related messages
      } elseif (($Message instanceof qcEvents_Stream_SSH_ChannelOpen) && ($this->State == self::STATE_READY)) {
        // We only support forwarded tcp/ip-channels for the moment
        if ($Message->Type != 'forwarded-tcpip') {
          $Reply = new qcEvents_Stream_SSH_ChannelRejection;
          $Reply->RecipientChannel = $Message->SenderChannel;
          $Reply->Code = $Reply::CODE_ADMINISTRATIVELY_PROHIBITED;
          $Reply->Reason = 'Only forwarded channels are allowed';
          
          return $this->writeMessage ($Reply);
        }
        
        // Make sure the forwarding was requested
        $Found = false;
        $setupCallback = null;
        
        foreach ($this->Forwardings as $Forwarding)
          if ($Found = (($Forwarding [0] == $Message->DestinationAddress) && ($Forwarding [1] == $Message->DestinationPort))) {
            $setupCallback = $Forwarding [2];
            
            break;
          }
        
        if (!$Found) {
          $Reply = new qcEvents_Stream_SSH_ChannelRejection;
          $Reply->RecipientChannel = $Message->SenderChannel;
          $Reply->Code = $Reply::CODE_ADMINISTRATIVELY_PROHIBITED;
          $Reply->Reason = 'Forwarding was not requested';
        
          return $this->writeMessage ($Reply);
        }
        
        // Preapre a new channel
        $Channel = new qcEvents_Stream_SSH_Channel ($this, $this->nextChannel++, 'forwarded-tcpip');
        $Channel->receiveMessage ($Message);
        
        // Setup the channel
        if ($setupCallback) {
          $Promise = $setupCallback ($Channel);
          
          if (!($Promise instanceof qcEvents_Promise))
            $Promise = ($Promise === false ? qcEvents_Promise::reject ('Failed to setup') : qcEvents_Promise::resolve ());
        } else
          $Promise = qcEvents_Promise::resolve ();
        
        $Promise->then (
          function () use ($Message, $Channel) {
            // Prepare confirmation-messsage
            $Reply = new qcEvents_Stream_SSH_ChannelConfirmation;
            $Reply->RecipientChannel = $Message->SenderChannel;
            $Reply->SenderChannel = $Channel->getLocalID ();
            $Reply->InitialWindowSize = 2097152; // 2 MB
            $Reply->MaximumPacketSize = 32768;
            
            // Write out the reply
            $this->writeMessage ($Reply);
            
            // Register the new channel
            $this->Channels [$Reply->SenderChannel] = $Channel;
            
            // Raise callbacks
            $this->___callback ('channelCreated', $Channel);
            $this->___callback ('channelConnected', $Channel);
          },
          function ($e) use ($Message) {
            $Reply = new qcEvents_Stream_SSH_ChannelRejection;
            $Reply->RecipientChannel = $Message->SenderChannel;
            $Reply->Code = $Reply::CODE_ADMINISTRATIVELY_PROHIBITED;
            $Reply->Reason = 'Channel-Setup was rejected by middleware';
            
            return $this->writeMessage ($Reply);
          }
        );
        
      } elseif ((($Message instanceof qcEvents_Stream_SSH_ChannelConfirmation) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelRejection) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelWindowAdjust) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelData) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelExtendedData) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelEnd) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelClose) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelRequest) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelSuccess) ||
                 ($Message instanceof qcEvents_Stream_SSH_ChannelFailure)) &&
                ($this->State == self::STATE_READY)) {
        // Make sure the channel is known
        if (isset ($this->Channels [$Message->RecipientChannel]))
          $this->Channels [$Message->RecipientChannel]->receiveMessage ($Message);
        else
          trigger_error ('Message for unknown channel received');
      } else
        trigger_error ('Unhandled message');
    }
    // }}}
    
    // {{{ failStream
    /**
     * Process a fatal error on the stream
     * 
     * @param string $Message
     * 
     * @access private
     * @return void
     **/
    private function failStream ($Message) {
      // Bail out the error
      trigger_error ($Message);
      
      // Check if we were initiating
      if ($this->connectPromise) {
        call_user_func ($this->connectPromise [1], $Message);
        
        $this->connectPromise = null;
      }
      
      // Remove the stream
      $this->Stream->close ();
      $this->Stream = null;
    }
    // }}}
    
    // {{{ negotiateAlgorithm
    /**
     * Pick an algorithm for client-server-negotiations
     * 
     * @param array $Reference
     * @param array $Offer
     * @param array $Names
     * 
     * @access private
     * @return mixed
     **/
    private function negotiateAlgorithm (array $Reference, array $Offer, array $Names) {
      foreach ($Reference as $clientAlgorithm) {
        // Make sure this one is known
        if (!isset ($Names [$clientAlgorithm]))
          continue;
        
        // Make sure this one is also offered
        if (!in_array ($clientAlgorithm, $Offer))
          continue;
        
        return $Names [$clientAlgorithm];
      }
      
      return null;
    }
    // }}}
    
    // {{{ derviceKey
    /**
     * Derive a specific key from key-exchnage-result
     * 
     * @param string $ID
     * @param int $Length (optional)
     * 
     * @access private
     * @return string
     **/
    private function deriveKey ($ID, $Length = null) {
      // Find hash-function to use
      $Hash = array_search ($this->keyExchange->Params [1], self::$hashNames);
      
      // Generate first output
      $outHash = hash ($Hash, $this->keyExchange->secret . $this->keyExchange->hash . $ID . $this->sessionID, true);
      $outLength = strlen ($outHash);
      
      if (($Length === null) || ($outLength == $Length))
        return $outHash;
        
      if ($outLength < $Length)
        for ($Offset = $outLength; $Offset < $Length; $Offset += $outLength)
          $outHash .= hash ($Hash, $this->keyExchange->secret . $this->keyExchange->hash . $outHash, true);
      
      return substr ($outHash, 0, $Length);
    }
    // }}}
    
    // {{{ checkInitialGreeting
    /**
     * Check and process initial greeting
     * 
     * @access private
     * @return void
     **/
    private function checkInitialGreeting () {
      // Check if the entire initial greeting is on the buffer
      if (($p = strpos ($this->Buffer, ($this->Compability ? "\n" : "\r\n"))) === false) {
        if (strlen ($this->Buffer) > 254)
          return $this->failStream ('Initial greeting too big');
        
        return false;
      }
      
      // Get the greeting off the buffer
      $this->remoteVersion = substr ($this->Buffer, 0, $p);
      $this->Buffer = substr ($this->Buffer, $p + 1);
      
      if ($this->Compability && (substr ($this->remoteVersion, -1, 1) == "\r"))
        $this->remoteVersion = substr ($this->remoteVersion, 0, -1);
      
      // Unpack the version
      if (substr ($this->remoteVersion, 0, 4) != 'SSH-') {
        $this->remoteVersion = null;
        
        return;
      }
      
      if(($p = strpos ($this->remoteVersion, '-', 4)) === false)
        return $this->failStream ('Invalid version: ' . $this->remoteVersion);
      
      $ProtocolVersion = substr ($this->remoteVersion, 4, $p - 4);
      $SoftwareVersion = substr ($this->remoteVersion, $p + 1);
      
      if (!$this->Compability &&
          ($ProtocolVersion != '2.0') &&
          (($this->Mode != self::MODE_CLIENT) || ($ProtocolVersion != '1.99')))
        return $this->failStream ('Invalid protocol-version: ' . $ProtocolVersion);
      
      if (strlen ($this->Buffer) > 0)
        $this->processBuffer ();
    }
    // }}}
    
    // {{{ decryptBlock
    /**
     * Try to decrypt a block
     * 
     * @param string $Block
     * 
     * @access private
     * @return string
     **/
    private function decryptBlock ($Block) {
      // Check if there is anything to do
      if ($this->cipherRemote [0] == self::CIPHER_NONE)
        return $Block;
      
      // Try to decrypt the block
      $Plaintext = openssl_decrypt ($Block, $this->cipherRemote [4], $this->cipherRemote [5], OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->cipherRemote [6]);
      
      // Update Cipher-States
      if ($this->cipherRemote [2] == self::CIPHER_MODE_CTR)
        for ($i = strlen ($this->cipherRemote [6]) - 1; $i >= 0; $i--) {
          $c = ord ($this->cipherRemote [6][$i]);
          $this->cipherRemote [6][$i] = chr (($c + 1) & 0xFF);
          
          if ($c < 255)
            break;
        }
      
      elseif ($this->cipherRemote [2] == self::CIPHER_MODE_CBC)
        $this->cipherRemote [6] = $Block;
      
      // Return the result
      return $Plaintext;
    }
    // }}}
    
    
    // {{{ eventClosed
    /**
     * Callback: The interface was closed
     * 
     * @access public
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param qcEvents_Interface_Stream $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (qcEvents_Interface_Stream $Source) { }
    // }}}
    
    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (qcEvents_Interface_Source $Source) { }
    // }}}
    
    // {{{ authHostkey
    /**
     * Callback: Hostkey was received from server
     * 
     * @param qcEvents_Stream_SSH_PublicKey $Hostkey
     * 
     * @access protected
     * @return void
     **/
    protected function authHostkey (qcEvents_Stream_SSH_PublicKey $Hostkey) { }
    // }}}
    
    // {{{ authBanner
    /**
     * Callback: A banner to display during authentication was received
     * 
     * @param string $Message
     * @param string $Language
     * 
     * @access protected
     * @return void
     **/
    protected function authBanner ($Message, $Langauge) { }
    // }}}
    
    // {{{ authSuccessfull
    /**
     * Callback: Authentication was successfull
     * 
     * @access protected
     * @return void
     **/
    protected function authSuccessfull () { }
    // }}}
    
    // {{{ channelCreated
    /**
     * Callback: A channel was created
     * 
     * @param qcEvents_Stream_SSH_Channel $Channel
     * 
     * @access protected
     * @retrun void
     **/
    protected function channelCreated (qcEvents_Stream_SSH_Channel $Channel) { }
    // }}}
    
    // {{{ channelConnected
    /**
     * Callback: A channel was successfully connected
     * 
     * @param qcEvents_Stream_SSH_Channel $Channel
     * 
     * @access protected
     * @return void
     **/
    protected function channelConnected (qcEvents_Stream_SSH_Channel $Channel) { }
    // }}}
  }

?>