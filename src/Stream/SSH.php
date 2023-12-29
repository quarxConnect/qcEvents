<?php

  /**
   * qcEvents - SSH Stream
   * Copyright (C) 2019-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events\Stream;
  use \quarxConnect\Events;
  
  class SSH extends Events\Emitter implements Events\ABI\Stream\Consumer, Events\ABI\Socket\Factory {
    /* State of our stream */
    private const STATE_NONE = 0;
    private const STATE_CONNECT = 1;
    private const STATE_AUTH = 2;
    private const STATE_READY = 3;
    
    private $streamState = SSH::STATE_NONE;
    
    /* Instance of our source-stream */
    private $sourceStream = null;
    
    /* Receive-Buffer */
    private $streamBuffer = '';
    
    /* Pending header for next message */
    private $nextMessage = null;
    
    /* Mode of this stream */
    private const MODE_CLIENT = 1;
    private const MODE_SERVER = 2; # Unimplemented
    
    private $sshMode = SSH::MODE_CLIENT;
    
    /* SSH-Versions of both sides */
    private $remoteVersion = null;
    private $localVersion = 'SSH-2.0-qcEventsSSH_0.1';
    
    /* ID of this session */
    private $sessionID = null;
    
    /* Message-Counters */
    private $sequenceLocal = 0;
    private $sequenceRemote = 0;
    
    /* Known hashing-methods */
    private const HASH_NONE = 0;
    private const HASH_MD5 = 1;
    private const HASH_SHA1 = 2;
    private const HASH_SHA256 = 3;
    
    private static $hashNames = [
      'md5'    => SSH::HASH_MD5,
      'sha1'   => SSH::HASH_SHA1,
      'sha256' => SSH::HASH_SHA256,
    ];
    
    /* Negotiated Key-Exchange */
    private const KEX_DH1 = 1;
    private const KEX_DH14 = 2;
    
    private static $keyExchangeNames = [
      'diffie-hellman-group1-sha1'  => [ SSH::KEX_DH1,  SSH::HASH_SHA1 ],
      'diffie-hellman-group14-sha1' => [ SSH::KEX_DH14, SSH::HASH_SHA1 ],
      # 'ecdh-sha2-nistp256' => null,
      # 'ecdh-sha2-nistp384' => null,
      # 'ecdh-sha2-nistp521' => null,
      # 'curve25519-sha256@libssh.org' => null,
      # 'kexguess2@matt.ucc.asn.au' => null,
    ];
    
    private $keyExchange = null;
    
    /* Messages sent for exechange-init */
    private $localKeyExchange = null;
    private $remoteKeyExchange = null;
    
    /* Negotiated Cipher-Algorithm */
    /* @see https://tools.ietf.org/html/rfc4253#section-6.3 */
    private const CIPHER_NONE = 0;
    private const CIPHER_3DES = 1;
    private const CIPHER_BLOWFISH = 2;
    private const CIPHER_TWOFISH = 3; # Unimplemented (ks 256, 192, 128)
    private const CIPHER_AES = 4;
    private const CIPHER_SERPENT = 5; # Unimplemented (ks 256, 192, 128)
    private const CIPHER_RC4 = 6;
    private const CIPHER_IDEA = 7;
    private const CIPHER_CAST = 8;
    
    private const CIPHER_MODE_STREAM = 0;
    private const CIPHER_MODE_CBC = 1;
    private const CIPHER_MODE_CTR = 2;
    
    private static $cipherNames = [
      'none'           => [ SSH::CIPHER_NONE,       0, SSH::CIPHER_MODE_STREAM,  8, null ],
      '3des-cbc'       => [ SSH::CIPHER_3DES,     192, SSH::CIPHER_MODE_CBC,     8, 'des-ede3-cbc' ],
      '3des-ctr'       => [ SSH::CIPHER_3DES,     192, SSH::CIPHER_MODE_CTR,     8, 'des-ede3-ctr' ],
      'blowfish-cbc'   => [ SSH::CIPHER_BLOWFISH, 128, SSH::CIPHER_MODE_CBC,     8, 'bf-cbc' ],
      'blowfish-ctr'   => [ SSH::CIPHER_BLOWFISH, 128, SSH::CIPHER_MODE_CTR,     8, 'bf-ctr' ],
      'twofish-cbc'    => [ SSH::CIPHER_TWOFISH,  256, SSH::CIPHER_MODE_CBC,    16, null ],
      'twofish128-cbc' => [ SSH::CIPHER_TWOFISH,  128, SSH::CIPHER_MODE_CBC,    16, null ],
      'twofish192-cbc' => [ SSH::CIPHER_TWOFISH,  192, SSH::CIPHER_MODE_CBC,    16, null ],
      'twofish256-cbc' => [ SSH::CIPHER_TWOFISH,  256, SSH::CIPHER_MODE_CBC,    16, null ],
      'twofish128-ctr' => [ SSH::CIPHER_TWOFISH,  128, SSH::CIPHER_MODE_CTR,    16, null ],
      'twofish192-ctr' => [ SSH::CIPHER_TWOFISH,  192, SSH::CIPHER_MODE_CTR,    16, null ],
      'twofish256-ctr' => [ SSH::CIPHER_TWOFISH,  256, SSH::CIPHER_MODE_CTR,    16, null ],
      'aes128-cbc'     => [ SSH::CIPHER_AES,      128, SSH::CIPHER_MODE_CBC,    16, 'aes-128-cbc' ],
      'aes192-cbc'     => [ SSH::CIPHER_AES,      192, SSH::CIPHER_MODE_CBC,    16, 'aes-192-cbc' ],
      'aes256-cbc'     => [ SSH::CIPHER_AES,      256, SSH::CIPHER_MODE_CBC,    16, 'aes-256-cbc' ],
      'aes128-ctr'     => [ SSH::CIPHER_AES,      128, SSH::CIPHER_MODE_CTR,    16, 'aes-128-ctr' ],
      'aes192-ctr'     => [ SSH::CIPHER_AES,      192, SSH::CIPHER_MODE_CTR,    16, 'aes-192-ctr' ],
      'aes256-ctr'     => [ SSH::CIPHER_AES,      256, SSH::CIPHER_MODE_CTR,    16, 'aes-256-ctr' ],
      'serpent128-cbc' => [ SSH::CIPHER_SERPENT,  128, SSH::CIPHER_MODE_CBC,    16, null ],
      'serpent192-cbc' => [ SSH::CIPHER_SERPENT,  192, SSH::CIPHER_MODE_CBC,    16, null ],
      'serpent256-cbc' => [ SSH::CIPHER_SERPENT,  256, SSH::CIPHER_MODE_CBC,    16, null ],
      'serpent128-ctr' => [ SSH::CIPHER_SERPENT,  128, SSH::CIPHER_MODE_CTR,    16, null ],
      'serpent192-ctr' => [ SSH::CIPHER_SERPENT,  192, SSH::CIPHER_MODE_CTR,    16, null ],
      'serpent256-ctr' => [ SSH::CIPHER_SERPENT,  256, SSH::CIPHER_MODE_CTR,    16, null ],
      'arcfour'        => [ SSH::CIPHER_RC4,      128, SSH::CIPHER_MODE_STREAM,  8, 'rc4' ],
      'idea-cbc'       => [ SSH::CIPHER_IDEA,     128, SSH::CIPHER_MODE_CBC,     8, 'idea-cbc' ],
      'idea-ctr'       => [ SSH::CIPHER_IDEA,     128, SSH::CIPHER_MODE_CTR,     8, 'idea-ctr' ],
      'cast128-cbc'    => [ SSH::CIPHER_CAST,     128, SSH::CIPHER_MODE_CBC,     8, 'cast-cbc' ],
      'cast128-ctr'    => [ SSH::CIPHER_CAST,     128, SSH::CIPHER_MODE_CTR,     8, 'cast-ctr' ],
    ];
    
    private $cipherLocal  = null;
    private $cipherRemote = null;
    
    /* Negotiated MAC-Algorithm */
    /* @see https://tools.ietf.org/html/rfc4253#section-6.4 */
    private static $macNames = [
      'none'         => [ SSH::HASH_NONE,  0 ],
      'hmac-md5'     => [ SSH::HASH_MD5,  16 ],
      'hmac-md5-96'  => [ SSH::HASH_MD5,  12 ],
      'hmac-sha1'    => [ SSH::HASH_SHA1, 20 ],
      'hmac-sha1-96' => [ SSH::HASH_SHA1, 12 ],
    ];
    
    private $macLocal  = null;
    private $macRemote = null;
    
    /* Negotiated Compression-Algorithm */
    private const COMPRESSION_NONE = 0;
    private const COMPRESSION_ZLIB = 1; # Unimplemented
    
    private static $compressionNames = [
      'none' => SSH::COMPRESSION_NONE,
      'zlib' => SSH::COMPRESSION_ZLIB,
    ];
    
    private $compressionLocal = null;
    private $compressionRemote = null;
    
    /* Minimum size of a packet */
    private $packetMinimumSize = 16;
    
    /* Run in compability-mode */
    private $sshCompabilityMode = true;
    
    /* Promise created by initStreamConsumer() */
    private $connectPromise = null;
    
    /* Promise for authentication */
    private $authPromise = null;
    
    /* Number of next channel to allocate */
    private $nextChannel = 0x00000000;
    
    /* List of global requests that want a reply */
    private $sshRequests = [ ];
    
    /* List of requested remote port-forwardings */
    private $portForwardings = [ ];
    
    /* List of channels */
    private $sshChannels = [ ];
    
    // {{{ getStream
    /**
     * Retrive our source-stream
     * 
     * @access public
     * @return Events\ABI\Stream
     **/
    public function getStream () : ?Events\ABI\Stream {
      return $this->sourceStream;
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return Events\Base
     **/
    public function getEventBase () : ?Events\Base {
      if (!$this->sourceStream)
        return null;
      
      return $this->sourceStream->getEventBase ();
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Set the Event-Base of this source
     * 
     * @param Events\Base $eventBase
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (\quarxConnect\Events\Base $eventBase) : void {
      if (!$this->sourceStream)
        return;
      
      $this->sourceStream->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void
     **/
    public function unsetEventBase () : void {
      if (!$this->sourceStream)
        return;
      
      $this->sourceStream->unsetEventBase ();
    }
    // }}}
    
    // {{{ getLocalVersion
    /**
     * Retrive the local version-string of this stream
     * 
     * @access public
     * @return string
     **/
    public function getLocalVersion () : string {
      return $this->localVersion;
    }
    // }}}
    
    // {{{ setLocalVersion
    /**
     * Store local version of this SSH-Stream
     * 
     * @param string $localVersion
     * 
     * @access public
     * @return void
     **/
    public function setLocalVersion (string $localVersion) : void {
      if ($this->streamState != self::STATE_NONE)
        throw new \Exception ('Invalid stream-state (set local version before establishing the connection');
      
      $this->localVersion = $localVersion;
      
      if (substr ($this->localVersion, 0, 8) != 'SSH-2.0-')
        trigger_error ('SSH-Version should start with SSH-2.0-', E_USER_NOTICE);
    }
    // }}}
    
    // {{{ getRemoteVersion
    /**
     * Retrive version-string from our peer
     * 
     * @access public
     * @return string
     **/
    public function getRemoteVersion () : ?string {
      return $this->remoteVersion;
    }
    // }}}
    
    // {{{ authPublicKey
    /**
     * Try to perform public-key-based authentication
     * 
     * @param string $authUsername
     * @param SSH\PrivateKey $rsaPrivateKey
     * 
     * @access public
     * @return Events\Promise
     **/
    public function authPublicKey (string $authUsername, SSH\PrivateKey $rsaPrivateKey) : Events\Promise {
      // Prepare the data to be signed
      $sshMessage = new SSH\UserAuthRequest ();
      $sshMessage->Username = $authUsername;
      $sshMessage->Service = 'ssh-connection';
      $sshMessage->Method = 'publickey';
      $sshMessage->Signed = true;
      
      if ($rsaPrivateKey->getType () == $rsaPrivateKey::TYPE_RSA) {
        $sshMessage->Algorithm = 'ssh-rsa';
        $sshMessage->PublicKey = $rsaPrivateKey->exportPublicKey ();
      } else
        # TODO: Add support for ssh-dss here
        return Events\Promise::reject ('Unsupported key-type');
      
      // Sign the message
      $sshMessage->Signature = $rsaPrivateKey->signSSH (
        SSH\Message::writeString ($this->sessionID) . substr ($sshMessage->toPacket (), 0, -4)
      );
      
      return $this->enqueueAuthMessage ($sshMessage);
    }
    // }}}
    
    // {{{ authPassword
    /**
     * Try to perform password-based authentication
     * 
     * @param string $authUsername
     * @param string $authPassword
     * @param bool $forceOnUnencrypted (optional) Force submission of password over an unencrypted connection
     * 
     * @access public
     * @return Events\Promise
     **/
    public function authPassword (string $authUsername, string $authPassword, bool $forceOnUnencrypted = false) : Events\Promise {
      // Make sure the connection is encrypted
      if (
        !$forceOnUnencrypted &&
        ($this->cipherLocal [0] == self::CIPHER_NONE)
      )
        return Events\Promise::reject ('Will not transmit password over an unencrypted connection');
      
      // Create the message
      $sshMessage = new SSH\UserAuthRequest ();
      $sshMessage->Username = $authUsername;
      $sshMessage->Service = 'ssh-connection';
      $sshMessage->Method = 'password';
      $sshMessage->ChangePassword = false;
      $sshMessage->Password = $authPassword;
      
      // Enqueue message for authentication
      return $this->enqueueAuthMessage ($sshMessage);
    }
    // }}}
    
    // {{{ requestSession
    /**
     * Request a session-channel
     * 
     * @access public
     * @return Events\Promise
     **/
    public function requestSession () : Events\Promise {
      // Make sure our state is good
      if ($this->streamState != self::STATE_READY)
        return Events\Promise::reject ('Invalid state');
      
      // Create an open-request
      $sshMessage = new SSH\ChannelOpen ();
      $sshMessage->Type = 'session';
      $sshMessage->SenderChannel = $this->nextChannel++;
      
      // Write out the message
      $this->writeMessage ($sshMessage);
      
      // Prepare the channel
      $this->sshChannels [$sshMessage->SenderChannel] = $sshChannel = new SSH\Channel ($this, $sshMessage->SenderChannel, $sshMessage->Type);
      
      // Run a callback
      $this->___callback ('channelCreated', $sshChannel);
      
      // Return it's promise
      return $sshChannel->getConnectionPromise ();
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
     * @return Events\Promise
     **/
    public function requestForward (string $listenAddress = '', int $listenPort = 0, callable $setupCallback = null) : Events\Promise {
      // Make sure our state is good
      if ($this->streamState != self::STATE_READY)
        return Events\Promise::reject ('Invalid state');
      
      // Prepare the message
      $sshMessage = new SSH\GlobalRequest ();
      $sshMessage->Name = 'tcpip-forward';
      $sshMessage->Address = $listenAddress;
      $sshMessage->Port = $listenPort;
      
      return $this->wantReply ($sshMessage)->then (
        function ($sshMessage) use ($listenAddress, $listenPort, $setupCallback) {
          // Get the port from the reply if needed
          if (($listenPort == 0) && (strlen ($sshMessage->Payload) > 0)) {
            $portOffset = 0;
            $listenPort = $sshMessage::readUInt32 ($Message->Payload, $portOffset);
          }
          
          // Register the forwarding
          $this->portForwardings [] = [ $listenAddress, $listenPort, $setupCallback ];
          
          // Forward the result
          return new Events\Promise\Solution ([ $listenAddress, $listenPort ]);
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
     * @return Events\Promise
     **/
    public function cancelForward (string $listenAddress, int $listenPort) : Events\Promise {
      // Make sure we know the forwarding
      $forwardingFound = false;
      
      foreach ($this->portForwardings as $forwardingIndex=>$forwardingMeta)
        if ($forwardingFound = (($forwardingMeta [0] == $listenAddress) && ($forwardingMeta [1] == $listenPort))) {
          unset ($this->portForwardings [$forwardingIndex]);
          break;
        }
      
      if (!$forwardingFound)
        return Events\Promise::reject ('Forwarding not found');
      
      // Write out the request
      $sshMessage = new SSH\GlobalRequest ();
      $sshMessage->Name = 'cancel-tcpip-forward';
      $sshMessage->Address = $listenAddress;
      $sshMessage->Port = $listenPort;
      
      return $this->wantReply ($sshMessage);
    }
    // }}}
    
    // {{{ requestConnection
    /**
     * Request a TCP/IP-channel
     * 
     * @param string $originatorHost
     * @param int $originatorPort
     * @param string $destinationHost
     * @param int $destinationPort
     * @param bool $channelForwarded (optional)
     * @param int $connectTimeout (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function requestConnection (string $originatorHost, int $originatorPort, string $destinationHost, int $destinationPort, bool $channelForwarded = false, int $connectTimeout = 120) : Events\Promise {
      // Make sure our state is good
      if ($this->streamState != $this::STATE_READY)
        return Events\Promise::reject ('Invalid state');
      
      // Create an open-request
      $sshMessage = new SSH\ChannelOpen ();
      $sshMessage->Type = ($channelForwarded ? 'forwarded-tcpip' : 'direct-tcpip');
      $sshMessage->SenderChannel = $this->nextChannel++;
      $sshMessage->DestinationAddress = $destinationHost;
      $sshMessage->DestinationPort = $destinationPort;
      $sshMessage->OriginatorAddress = $originatorHost;
      $sshMessage->OriginatorPort = $originatorPort;
      
      // Write out the message
      $this->writeMessage ($sshMessage);
    
      // Prepare the channel
      $this->sshChannels [$sshMessage->SenderChannel] = $sshChannel = new SSH\Channel ($this, $sshMessage->SenderChannel, $sshMessage->Type, $connectTimeout);
      
      // Run a callback
      $this->___callback ('channelCreated', $sshChannel);
      
      // Return it's promise
      return $sshChannel->getConnectionPromise ();
    }
    // }}}
    
    // {{{ removeChannel
    /**
     * Silently remove a channel from our set of channels
     * 
     * @param SSH\Channel $sshChannel
     * 
     * @access public
     * @return void
     **/
    public function removeChannel (SSH\Channel $sshChannel) : void {
      // Retrive the local id of the chanenl
      $localID = $sshChannel->getLocalID ();
      
      // Make sure the channel is hosted here
      if (
        !isset ($this->sshChannels [$localID]) ||
        ($sshChannel !== $this->sshChannels [$localID]) ||
        ($sshChannel->getStream () !== $this)
      )
        return;
      
      // Remove the channel
      unset ($this->sshChannels [$localID]);
    }
    // }}}
    
    // {{{ createConnection
    /**
     * Request a connected socket from this factory
     * 
     * @param array|string $remoteHost
     * @param int $remotePort
     * @param int $socketType
     * @param bool $useTLS (optional)
     * @param bool $allowReuse (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function createConnection ($remoteHost, int $remotePort, int $socketType, bool $useTLS = false, bool $allowReuse = false) : Events\Promise {
      // Sanatize parameters
      if ($useTLS)
        return Events\Promise::reject ('No TLS-Support yet');
      
      if ($socketType != Events\Socket::TYPE_TCP)
        return Events\Promise::reject ('Only TCP-Connections are supported');
      
      if (!is_array ($remoteHost))
        $remoteHost = [ $remoteHost ];
      
      if (count ($remoteHost) < 1)
        return Events\Promise::reject ('Empty list of hosts');
      
      // Try to create the connection
      return $this->requestConnection (
        '127.0.0.1',
        rand (1025, 0xffff),
        array_shift ($remoteHost),
        $remotePort,
        false,
        Events\Socket::CONNECT_TIMEOUT
      )->catch (
        function () use ($remoteHost, $remotePort) {
          // Check if there is another hostname/ip to try
          if (count ($remoteHost) > 0)
            return $this->createConnection ($remoteHost, $remotePort, Events\Socket::TYPE_TCP, false, false);
          
          // Just pass the rejection
          throw new Events\Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}

    // {{{ releaseConnection
    /**
     * Return a connected socket back to the factory
     * 
     * @param Events\ABI\Stream $leasedConnection
     * 
     * @access public
     * @return void
     **/
    public function releaseConnection (Events\ABI\Stream $leasedConnection) : void {
      if (
        !($leasedConnection instanceof SSH\Channel) ||
        ($leasedConnection->getStream () !== $this)
      )
        return;
      
      $leasedConnection->close ();
    }
    // }}}
    
    // {{{ startCommand
    /**
     * Try to open a session and start a command (stdin will be available, stdout/stderr won't be captured by this)
     * 
     * @param string $commandLine The command to execute
     * @param array $environmentVariables (optional) Set of environment-variables to pass (not known to work, but on RFC)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function startCommand (string $commandLine, array $environmentVariables = null) : Events\Promise {
      return $this->requestSession ()->then (
        function (SSH\Channel $sshChannel) use ($commandLine, $environmentVariables) {
          // Push environment to channel
          if ($environmentVariables)
            foreach ($environmentVariables as $variableName=>$variableValue)
              $sshChannel->setEnv ($variableName, $variableValue);
          
          // Try to execute the command
          return $sshChannel->exec ($commandLine)->then (
            function () use ($sshChannel) {
              return $sshChannel;
            },
            function () use ($sshChannel) {
              // Clean up the channel
              $sshChannel->close ();
              
              // Forward the original error
              throw new Events\Promise\Soluiton (func_get_args ());
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
     * @param string $commandLine
     * @param array $environmentVariables (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function runCommand (string $commandLine, array $environmentVariables = null) : Events\Promise {
      return $this->startCommand ($commandLine, $environmentVariables)->then (
        function (SSH\Channel $sshChannel) {
          // Close stdin on channel
          $sshChannel->eof ();
          
          // Wait for end of stream
          return $sshChannel->once ('eventClosed')->then (
            function () use ($sshChannel) {
              return new Events\Promise\Solution ([
                $sshChannel->read (),
                $sshChannel->getCommandStatus (),
              ]);
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ runScript
    /**
     * Try to open a session, run a script and return it's output
     * 
     * @param string $shellScript
     * @param array $shellEnvironment (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function runScript (string $shellScript, array $shellEnvironment = null) : Events\Promise {
      // Check for shebang on the script
      if (substr ($shellScript, 0, 2) == '#!')
        $scriptInterpreter = trim (substr ($shellScript, 2, strpos ($shellScript, "\n") - 2));
      else
        $scriptInterpreter = '/bin/sh';
      
      // Start the interpreter
      return $this->startCommand ($scriptInterpreter, $shellEnvironment)->then (
        function (SSH\Channel $sshChannel) use ($shellScript) {
          // Push script to interpreter
          $sshChannel->write ($shellScript);
          
          // Close stdin on channel
          $sshChannel->eof ();

          // Wait for end of stream
          return $sshChannel->once ('eventClosed')->then (
            function () use ($sshChannel) {
              return new Events\Promise_Solution ([
                $sshChannel->read (),
                $sshChannel->getCommandStatus (),
              ]);
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ getKeyExchangeInit
    /**
     * Retrive a message to initialize key-exchange
     * 
     * @access private
     * @return SSH\KeyExchangeInit
     **/
    private function getKeyExchangeInit () : SSH\KeyExchangeInit {
      $sshMessage = new SSH\KeyExchangeInit ();
      
      $sshMessage->KexAlgorithms = [ 'diffie-hellman-group1-sha1', 'diffie-hellman-group14-sha1' ];
      $sshMessage->serverHostKeyAlgortihms = [ 'ssh-rsa' ];
      $sshMessage->ciphersClientServer = [ 'aes128-ctr', 'aes192-ctr', 'aes256-ctr' ];
      $sshMessage->ciphersServerClient = [ 'aes128-ctr', 'aes192-ctr', 'aes256-ctr' ];
      $sshMessage->macClientServer = $sshMessage->macServerClient = [ 'hmac-sha1-96', 'hmac-sha1', 'hmac-md5-96', 'hmac-md5' ];
      $sshMessage->compClientServer = $sshMessage->compServerClient = [ 'none' ];
      $sshMessage->langClientServer = $sshMessage->langServerClient = [ '' ];
      $sshMessage->kexFollows = false;
      
      return $sshMessage;
    }
    // }}}
    
    // {{{ consume
    /**
     * Receive data from our source-stream
     * 
     * @param string $incomingData
     * @param Events\ABI\Source $sourceStream
     * 
     * @access public
     * @return void
     **/
    public function consume ($incomingData, Events\ABI\Source $sourceStream) : void {
      // Make sure the stream is right
      if ($this->sourceStream !== $sourceStream)
        return;
      
      // Push to internal buffer
      $this->streamBuffer .= $incomingData;
      unset ($incomingData);
      
      // Try to process data from the buffer
      $this->processBuffer ();
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      // Close all channels
      $closePromises = [ ];
      
      foreach ($this->sshChannels as $sshChannel)
        $closePromises [] = $sshChannel->close ()->catch (function () { });
      
      $this->sshChannels = [ ];
      
      # TODO
      return Events\Promise::all ($closePromises)->then (
        function () {
          $this->___callback ('eventClosed');
        }
      );
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param Events\ABI\Stream $streamSource
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $streamSource) : Events\Promise {
      // Check if there was another stream pending
      if ($this->connectPromise)
        $this->connectPromise->reject ('Replaced by another stream');
      
      // Assign the stream
      $this->sourceStream = $streamSource;
      
      // Reset our state
      $this->streamState = self::STATE_CONNECT;
      $this->keyExchange = null;
      $this->cipherLocal = $this->cipherRemote = self::$cipherNames ['none'];
      $this->macLocal = $this->macRemote = self::$macNames ['none'];
      $this->compressionLocal = $this->compressionRemote = self::$compressionNames ['none'];
      
      // Push our version to peer
      $this->sourceStream->write ($this->localVersion . "\r\n");
      
      // Send key-exchange-message to peer
      $this->writeMessage ($this->localKeyExchange = $this->getKeyExchangeInit ());
      
      // Return new promise
      $this->connectPromise = new Events\Promise\Deferred ();
      
      return $this->connectPromise->getPromise ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\ABI\Source $sourceStream
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Events\ABI\Source $sourceStream) : Events\Promise {
      // Check if this source is actually assigned
      if ($sourceStream === $this->sourceStream) {
        // Remove the source
        $this->sourceStream = null;
        
        // Check if we are still initializing
        if ($this->connectPromise) {
          $connectPromise = $this->connectPromise;
          $this->connectPromise = null;
          
          $connectPromise->reject ('Interrupted by deinitConsumer()');
        }
        
        // Close all channels
        foreach ($this->sshChannels as $sshChannel)
          $sshChannel->close ();
        
        $this->sshChannels = [ ];
        
        // Raise a callback
        $this->___callback ('eventUnpiped', $sourceStream);
      }
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ enqueueAuthMessage
    /**
     * Push an auth-message to our send-queue
     * 
     * @param SSH\Message $authMessage
     * 
     * @access private
     * @return Events\Promise
     **/
    private function enqueueAuthMessage (SSH\Message $authMessage) : Events\Promise {
      // Just forward for the moment
      return $this->writeAuthMessage ($authMessage);
    }
    // }}}
    
    // {{{ writeAuthMessage
    /**
     * Push an authentication-message to the wire
     * 
     * @param SSH\Message $sshMessage
     * 
     * @access private
     * @return Events\Promise
     **/
    private function writeAuthMessage (SSH\Message $authMessage) : Events\Promise {
      // Create deferred Promise
      $this->authPromise = new Events\Promise\Deferred ();
      
      // Write out the message
      $this->writeMessage ($authMessage);
      
      // Return the promise
      return $this->authPromise->getPromise ();
    }
    // }}}
    
    // {{{ wantReply
    /**
     * Write out a global request and expect a reply for it
     * 
     * @param SSH\GlobalRequest $sshRequest
     * 
     * @access private
     * @return Events\Promise
     **/
    private function wantReply (SSH\GlobalRequest $sshRequest) : Events\Promise {
      // Create new deferred promise
      $deferredPromise = new Events\Promise\Deferred ();
      
      // Make sure the reply-bit is set
      $sshRequest->wantReply = true;
      
      // Push to queue
      $this->sshRequests [] = [ $sshRequest, $deferredPromise ];
      
      // Check whether to write out the request
      if (count ($this->sshRequests) == 1)
        $this->writeMessage ($sshRequest)->catch ([ $deferredPromise, 'reject' ]);
      
      // Return the actual promise
      return $deferredPromise->getPromise ();
    }
    // }}}
    
    // {{{ writeMessage
    /**
     * Write a message to the wire
     * 
     * @param SSH\Message $sshMessage
     * 
     * @access public
     * @return Events\Promise
     **/
    public function writeMessage (SSH\Message $sshMessage) : Events\Promise {
      // Sanity-check for global requests not to poison our queue
      if (
        ($sshMessage instanceof SSH\GlobalRequest) &&
        $sshMessage->wantReply
      ) {
        if (
          (count ($this->sshRequests) < 1) ||
          ($this->sshRequests [0][0] !== $sshMessage)
        )
          return $this->wantReply ($sshMessage);
      }
      
      return $this->writePacket ($sshMessage->toPacket ());
    }
    // }}}
    
    // {{{ writePacket
    /**
     * Push a packet to the wire
     * 
     * @param string $packetData
     * 
     * @access private
     * @return Events\Promise
     **/
    private function writePacket (string $packetData) : Events\Promise {
      // Retrive the length of the packet
      $packetLength = strlen ($packetData);
      
      // Determine number of blocks
      $blockCount = ceil (($packetLength + 9) / $this->cipherLocal [3]);
      $blockSize = $blockCount * $this->cipherLocal [3];
      
      // Generate padding
      $paddingLength = $blockSize - $packetLength - 5;
      $paddingData = '';
      
      for ($i = 0; $i < $paddingLength; $i++)
        $paddingData .= chr (rand (0, 255));
      
      // Assemble the packet
      $packetData = pack ('Nc', $packetLength + $paddingLength + 1, $paddingLength) . $packetData . $paddingData;
      
      // Generate the MAC
      if ($this->macLocal [0] != self::HASH_NONE)
        $packetMAC = substr (hash_hmac (array_search ($this->macLocal [0], self::$hashNames), SSH\Message::writeUInt32 ($this->sequenceLocal) . $packetData, $this->macLocal [2], true), 0, $this->macLocal [1]);
      else
        $packetMAC = '';
      
      $this->sequenceLocal++;
      
      // Encrypt the packet
      if ($this->cipherLocal [0] != self::CIPHER_NONE) {
        $packetCiphertext = '';
        
        for ($blockOffset = 0; $blockOffset < $blockSize; $blockOffset += $this->cipherLocal [3]) {
          $cipherBlock = openssl_encrypt (
            substr ($packetData, $blockOffset, $this->cipherLocal [3]),
            $this->cipherLocal [4],
            $this->cipherLocal [5],
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $this->cipherLocal [6]
          );
          
          if ($this->cipherLocal [2] == self::CIPHER_MODE_CTR) {
            for ($i = strlen ($this->cipherLocal [6]) - 1; $i >= 0; $i--) {
              $c = ord ($this->cipherLocal [6][$i]);
              $this->cipherLocal [6][$i] = chr (($c + 1) & 0xFF);
              
              if ($c < 255)
                break;
            }
          } elseif ($this->cipherLocal [2] == self::CIPHER_MODE_CBC)
            $this->cipherLocal [6] = $cipherBlock;
          
          $packetCiphertext .= $cipherBlock;
        }
        
        $packetData = $packetCiphertext;
      }
      
      // Write out the packet
      if (!$this->sourceStream)
        return Events\Promise::reject ('Not connected');
      
      return $this->sourceStream->write ($packetData . $packetMAC);
    }
    // }}}
    
    // {{{ processBuffer
    /**
     * Try to get packets from the buffer and process them
     * 
     * @access private
     * @return void
     **/
    private function processBuffer () : void {
      // Check for initial greeting
      if (
        ($this->sshMode == self::MODE_CLIENT) &&
        ($this->remoteVersion === null)
      ) {
        $this->checkInitialGreeting ();
        
        return;
      }
      
      // Check for binary packets
      $bufferLength = strlen ($this->streamBuffer);
      $bufferOffset = 0;
      
      while ($bufferLength - $bufferOffset >= max ($this->packetMinimumSize, $this->cipherRemote [3]) + $this->macRemote [1]) {
        // Try to decrypt first block
        if ($this->nextMessage === null) {
          $packetData = $this->decryptBlock (substr ($this->streamBuffer, $bufferOffset, $this->cipherRemote [3]));
          
          // Read basic header from packet
          $this->nextMessage = unpack ('Nlength/cpadding', substr ($packetData, 0, 5));
          $this->nextMessage ['data'] = $packetData;
        } else
          $packetData = $this->nextMessage ['data'];
        
        // Check how many blocks to read
        $readBlocks = ceil (($this->nextMessage ['length'] + 4) / $this->cipherRemote [3]);
        $readLength = (int)($readBlocks * $this->cipherRemote [3]);
        
        if ($readLength > 35000)
          trigger_error ('Have to read more than 35k octets, that is propably a bug');
        
        // Make sure we have the entire packet on the buffer
        if ($bufferLength - $bufferOffset < $readLength + $this->macRemote [1])
          break;
        
        // Read the remaining packet-data
        for ($packetBlock = 1; $packetBlock < $readBlocks; $packetBlock++)
          $packetData .= $this->decryptBlock (substr ($this->streamBuffer, $bufferOffset + $packetBlock * $this->cipherRemote [3], $this->cipherRemote [3]));
        
        // Read and verify MAC
        if ($this->macRemote [1] > 0) {
          $receivedMAC = substr ($this->streamBuffer, $bufferOffset + $readLength, $this->macRemote [1]);
          $expectedMAC = substr (hash_hmac (array_search ($this->macRemote [0], self::$hashNames), SSH\Message::writeUInt32 ($this->sequenceRemote) . $packetData, $this->macRemote [2], true), 0, $this->macRemote [1]);
          
          if (strcmp ($receivedMAC, $expectedMAC) != 0) {
            $this->failStream ('MAC-Check failed');
            
            return;
          }
        }
        
        $this->sequenceRemote++;
        
        // Split up the packet
        $payloadLength = $this->nextMessage ['length'] - $this->nextMessage ['padding'] - 1;
        $payloadData = substr ($packetData, 5, $payloadLength);
        $packetPadding = substr ($packetData, -$this->nextMessage ['padding'], $this->nextMessage ['padding']);
        unset ($packetData);
        
        # TODO: Uncompress payload
        
        $this->processPacket ($payloadData, $payloadLength);
        
        // Move forward
        $bufferOffset += $readLength + $this->macRemote [1];
        
        $this->nextMessage = null;
      }
      
      // Truncate data from the buffer
      if ($bufferOffset > 0)
        $this->streamBuffer = substr ($this->streamBuffer, $bufferOffset);
    }
    // }}}
    
    // {{{ processPacket
    /**
     * Process a packet received from the transport-layer
     * 
     * @param string $packetData
     * @param int $packetLength
     * 
     * @access private
     * @reutrn void
     **/
    private function processPacket (string $packetData, int $packetLength) : void {
      // Check wheter to process anything
      if ($packetLength < 1)
        return;
      
      // Try to unpack the message
      $sshMessage = SSH\Message::fromPacket ($packetData, $packetLength);
      
      // Check wheter to start a new key-exchange (may happen at any time)
      if ($sshMessage instanceof SSH\KeyExchangeInit) {
        // Make sure we are not exchanging a key
        if ($this->keyExchange !== null) {
          $this->failStream ('Duplicate KeyExchangeInit-Message received');
          
          return;
        }
        
        // Check if we have already sent a kxinit
        if ($this->localKeyExchange === null)
          $this->writeMessage ($this->localKeyExchange = $this->getKeyExchangeInit ());
        
        // Store the message for reference
        $this->remoteKeyExchange = $sshMessage;
        
        // Choose which message to use as reference
        if ($this->sshMode == self::MODE_CLIENT) {
          $kexReference = $this->localKeyExchange;
          $kexOffer = $this->remoteKeyExchange;
        } else {
          $kexReference = $this->remoteKeyExchnage;
          $kexOffer = $this->localKeyExchanage;
        }
        
        // Try to negotiate algorithms
        $this->keyExchange = new \stdClass ();
        
        if (
          (($this->keyExchange->Params = $this->negotiateAlgorithm ($kexReference->KexAlgorithms, $kexOffer->KexAlgorithms, self::$keyExchangeNames)) === null) ||
          (($this->keyExchange->serverHostKeyAlgorithm = $this->negotiateAlgorithm ($kexReference->serverHostKeyAlgortihms, $kexOffer->serverHostKeyAlgortihms, [ 'ssh-dss' => 0, 'ssh-rsa' => 1 ])) === null) ||
          (($this->keyExchange->clientServerCipher = $this->negotiateAlgorithm ($kexReference->ciphersClientServer, $kexOffer->ciphersClientServer, self::$cipherNames)) === null) ||
          (($this->keyExchange->serverClientCipher = $this->negotiateAlgorithm ($kexReference->ciphersServerClient, $kexOffer->ciphersServerClient, self::$cipherNames)) === null) ||
          (($this->keyExchange->clientServerMAC    = $this->negotiateAlgorithm ($kexReference->macClientServer, $kexOffer->macClientServer, self::$macNames)) === null) ||
          (($this->keyExchange->serverClientMAC    = $this->negotiateAlgorithm ($kexReference->macServerClient, $kexOffer->macServerClient, self::$macNames)) === null) ||
          (($this->keyExchange->clientServerComp   = $this->negotiateAlgorithm ($kexReference->compClientServer, $kexOffer->compClientServer, self::$compressionNames)) === null) ||
          (($this->keyExchange->serverClientComp   = $this->negotiateAlgorithm ($kexReference->compServerClient, $kexOffer->compServerClient, self::$compressionNames)) === null)
        ) {
          $this->failStream ('Failed to negotiate algorithms');
          
          return;
        }
        
        // Start Key-Exchange (if in client-mode)
        if ($this->sshMode == self::MODE_CLIENT) {
          if ($this->keyExchange->Params [0] == self::KEX_DH1) {
            $this->keyExchange->dh_g = gmp_init (2);
            $this->keyExchange->dh_p = gmp_init ('FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE65381FFFFFFFFFFFFFFFF', 16);
          } elseif ($this->keyExchange->Params [1] == self::KEX_DH14) {
            $this->keyExchange->dh_g = gmp_init (2);
            $this->keyExchange->dh_p = gmp_init ('FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3BE39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF6955817183995497CEA956AE515D2261898FA051015728E5A8AACAA68FFFFFFFFFFFFFFFF', 16);
          } else {
            $this->failStream ('Unsupported Key-Exchange-Algorithm');
            
            return;
          }
          
          $this->keyExchange->dh_q = ($this->keyExchange->dh_p - 1) / 2;
          
          $this->keyExchange->dh_x = gmp_random_range (2, $this->keyExchange->dh_q - 1);
          $this->keyExchange->dh_e = gmp_powm ($this->keyExchange->dh_g, $this->keyExchange->dh_x, $this->keyExchange->dh_p);
          
          $sshReply = new SSH\KeyExchangeDHInit ();
          $sshReply->e = $this->keyExchange->dh_e;
          
          $this->writeMessage ($sshReply);
        }
      
      // Check for a Key-Exchange DH-Reply
      } elseif ($sshMessage instanceof SSH\KeyExchangeDHReply) {
        // Check for an ongoing kex
        if ($this->keyExchange === null) {
          $this->failStream ('Received DH-Reply when not being in key-exchange-mode');
          
          return;
        }
        
        // We only accept this in client-mode
        if ($this->sshMode != self::MODE_CLIENT) {
          $this->failStream ('Received DH-Reply from client');
          
          return;
        }
        
        // Derive shared secret
        $this->keyExchange->secret = SSH\Message::writeMPInt (gmp_powm ($sshMessage->f, $this->keyExchange->dh_x, $this->keyExchange->dh_p));
        $this->keyExchange->hash = hash (
          array_search ($this->keyExchange->Params [1], self::$hashNames),
          SSH\Message::writeString ($this->localVersion) .
          SSH\Message::writeString ($this->remoteVersion) .
          SSH\Message::writeString ($this->localKeyExchange->toPacket ()) .
          SSH\Message::writeString ($this->remoteKeyExchange->toPacket ()) .
          SSH\Message::writeString ($sshMessage->serverHostKey) .
          SSH\Message::writeMPInt ($this->keyExchange->dh_e) .
          SSH\Message::writeMPInt ($sshMessage->f) .
          $this->keyExchange->secret,
          true
        );
        
        // Check the hostkey and signature
        $publicKey = SSH\PublicKey::loadFromString ($sshMessage->serverHostKey);
        
        if (!$publicKey->verifySSH ($this->keyExchange->hash, $sshMessage->Signature)) {
          $this->failStream ('Could not verify hostkey');
          
          return;
        }
        
        $this->___callback ('authHostkey', $publicKey);
        
        // Make sure we have a session-id
        if ($this->sessionID === null)
          $this->sessionID = $this->keyExchange->hash;
        
        // Send SSH_MSG_NEWKEYS
        $this->writeMessage (new SSH\NewKeys ());
        
        // Assign new client-to-server-params
        $this->cipherLocal = $this->keyExchange->clientServerCipher;
        $this->macLocal = $this->keyExchange->clientServerMAC;
        $this->compressionLocal = $this->keyExchange->clientServerComp;
        
        # $h = array_search ($this->keyExchange->Params [1], self::$hashNames);
        
        $this->cipherLocal [] = $this->deriveKey ('C', $this->cipherLocal [1] / 8);
        $this->cipherLocal [] = $this->deriveKey ('A', $this->cipherLocal [3]);
        $this->macLocal [] = $this->deriveKey ('E');
        
        // Move to authentication-state
        if ($this->streamState == self::STATE_CONNECT) {
          $sshReply = new SSH\ServiceRequest ();
          $sshReply->Service = 'ssh-userauth';
          
          $this->writeMessage ($sshReply);
        }
      
      // Remote party switches to new keys
      } elseif ($sshMessage instanceof SSH\NewKeys) {
        // Check for an ongoing kex
        if ($this->keyExchange === null) {
          $this->failStream ('Remote party switches to new keys without having exchanged new keys');
          
          return;
        }
        
        // Assign new server-to-client-params
        # $h = array_search ($this->keyExchange->Params [1], self::$hashNames);
        
        if ($this->sshMode == self::MODE_CLIENT) {
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
        
        // Remove informations from negotiation
        $this->keyExchange = null;
        $this->localKeyExchange = null;
      
      // A new service was accepted
      } elseif (
        ($sshMessage instanceof SSH\ServiceAccept) &&
        ($this->streamState == self::STATE_CONNECT)
      ) {
        if ($sshMessage->Service == 'ssh-userauth') {
          $this->streamState = self::STATE_AUTH;
          
          // Check if we were initiating
          if ($this->connectPromise) {
            // Resolve the promise
            $connectPromise = $this->connectPromise;
            $this->connectPromise = null;
            
            $connectPromise->resolve ();
            
            // Raise an event for this
            $this->___callback ('eventPipedStream', $this->sourceStream);
          }
        }
      
      // A response to an ongoing authentication-process was received
      } elseif (
        (
          ($sshMessage instanceof SSH\UserAuthSuccess) ||
          ($sshMessage instanceof SSH\UserAuthFailure) ||
          ($sshMessage instanceof SSH\UserAuthPublicKeyOK)
        ) &&
        ($this->streamState == self::STATE_AUTH)
      ) {
        // Change our state
        if ($sshMessage instanceof SSH\UserAuthSuccess)
          $this->streamState = self::STATE_READY;
        
        // Resolve any promise
        if ($this->authPromise) {
          // Resolve the promise
          $authPromise = $this->authPromise;
          $this->authPromise = null;
          
          if ($sshMessage instanceof SSH\UserAuthFailure)
            $authPromise->reject ('Authentication failed', $sshMessage->PartialSuccess, $sshMessage->Methods);
          else
            $authPromise->resolve ();
          
          // Raise an event for this
          if ($sshMessage instanceof SSH\UserAuthSuccess)
            $this->___callback ('authSuccessfull');
        } else
          trigger_error ('Authentication-Response without promise received');
        
      // Push any authentication-related banner to an event
      } elseif (
        ($sshMessage instanceof SSH\UserAuthBanner) &&
        ($this->streamState == self::STATE_AUTH)
      ) {
        $this->___callback ('authBanner', $sshMessage->Message, $sshMessage->Language);
      
      // Process disconnect
      } elseif ($sshMessage instanceof SSH\Disconnect) {
        // Check if we were initiating
        if ($this->connectPromise) {
          // Reject the promise
          $connectPromise = $this->connectPromise;
          $this->connectPromise = null;
          
          $connectPromise->reject ('Disconnect received');
        }
        
        // Check for an auth-promise
        if ($this->authPromise) {
          // Reject the promise
          $authPromise = $this->authPromise;
          $this->authPromise = null;
          
          $authPromise->reject ('Disconnect received', false, [ ]);
        }
      
      // Process global request
      } elseif (
        ($sshMessage instanceof SSH\GlobalRequest) &&
        ($this->streamState == self::STATE_READY)
      ) {
        $this->___callback ('sshGlobalRequest', $sshMessage);
      
      } elseif (
        (
          ($sshMessage instanceof SSH\RequestSuccess) ||
          ($sshMessage instanceof SSH\RequestFailure)
        ) &&
        ($this->streamState == self::STATE_READY)
      ) {
        // Make sure there is a request pending at all
        if (count ($this->sshRequests) < 1)
          return;
        
        // Get the request from queue
        $requestInfo = array_shift ($this->sshRequests);
        
        // Resolve or reject the promise
        if ($sshMessage instanceof SSH\RequestSuccess)
          $requestInfo->resolve ($sshMessage);
        else
          $requestInfo->reject ('Request failed', $sshMessage);
        
        // Check wheter to write out the next request
        if (count ($this->sshRequests) > 0)
          $this->writeMessage (
            $this->sshRequests [0][0]
          )->catch (
            [ $this->sshRequests [0][1], 'reject' ]
          );
      
      // Process channel-related messages
      } elseif (
        ($sshMessage instanceof SSH\ChannelOpen) &&
        ($this->streamState == self::STATE_READY)
      ) {
        // We only support forwarded tcp/ip-channels for the moment
        if ($sshMessage->Type != 'forwarded-tcpip') {
          $sshReply = new SSH\ChannelRejection ();
          $sshReply->RecipientChannel = $sshMessage->SenderChannel;
          $sshReply->Code = $sshReply::CODE_ADMINISTRATIVELY_PROHIBITED;
          $sshReply->Reason = 'Only forwarded channels are allowed';
          
          $this->writeMessage ($sshReply);
          
          return;
        }
                
        // Make sure the forwarding was requested
        $forwardingFound = false;
        $setupCallback = null;
        
        foreach ($this->portForwardings as $portForwarding)
          if ($forwardingFound = (($portForwarding [0] == $sshMessage->DestinationAddress) && ($portForwarding [1] == $sshMessage->DestinationPort))) {
            $setupCallback = $portForwarding [2];
            
            break;
          }
        
        if (!$forwardingFound) {
          $sshReply = new SSH\ChannelRejection ();
          $sshReply->RecipientChannel = $sshMessage->SenderChannel;
          $sshReply->Code = $sshReply::CODE_ADMINISTRATIVELY_PROHIBITED;
          $sshReply->Reason = 'Forwarding was not requested';
        
          $this->writeMessage ($sshReply);
          
          return;
        }
        
        // Preapre a new channel
        $sshChannel = new SSH\Channel ($this, $this->nextChannel++, 'forwarded-tcpip');
        $sshChannel->receiveMessage ($sshMessage);
        
        // Setup the channel
        if ($setupCallback) {
          try {
            $setupPromise = $setupCallback ($sshChannel);
            
            if (!($setupPromise instanceof Events\Promise))
              $setupPromise = ($setupPromise === false ? Events\Promise::reject ('Failed to setup') : Events\Promise::resolve ());
          } catch (\Throwable $setupError) {
            $setupPromise = Events\Promise::reject ($setupError);
          }
        } else
          $setupPromise = Events\Promise::resolve ();
        
        $setupPromise->then (
          function () use ($sshMessage, $sshChannel) {
            // Prepare confirmation-messsage
            $sshReply = new SSH\ChannelConfirmation ();
            $sshReply->RecipientChannel = $sshMessage->SenderChannel;
            $sshReply->SenderChannel = $sshChannel->getLocalID ();
            
            // Write out the reply
            $this->writeMessage ($sshReply);
            
            // Register the new channel
            $this->sshChannels [$sshReply->SenderChannel] = $sshChannel;
            
            // Raise callbacks
            $this->___callback ('channelCreated', $sshChannel);
            $this->___callback ('channelConnected', $sshChannel);
          },
          function (\Throwable $error) use ($sshMessage) {
            $sshReply = new SSH\ChannelRejection ();
            $sshReply->RecipientChannel = $sshMessage->SenderChannel;
            $sshReply->Code = $sshReply::CODE_ADMINISTRATIVELY_PROHIBITED;
            $sshReply->Reason = 'Channel-Setup was rejected by middleware';
            
            $this->writeMessage ($sshReply);
          }
        );
        
      } elseif (
        (
          ($sshMessage instanceof SSH\ChannelConfirmation) ||
          ($sshMessage instanceof SSH\ChannelRejection) ||
          ($sshMessage instanceof SSH\ChannelWindowAdjust) ||
          ($sshMessage instanceof SSH\ChannelData) ||
          ($sshMessage instanceof SSH\ChannelExtendedData) ||
          ($sshMessage instanceof SSH\ChannelEnd) ||
          ($sshMessage instanceof SSH\ChannelClose) ||
          ($sshMessage instanceof SSH\ChannelRequest) ||
          ($sshMessage instanceof SSH\ChannelSuccess) ||
          ($sshMessage instanceof SSH\ChannelFailure)
        ) &&
        ($this->streamState == self::STATE_READY)
      ) {
        // Make sure the channel is known
        if (isset ($this->sshChannels [$sshMessage->RecipientChannel]))
          $this->sshChannels [$sshMessage->RecipientChannel]->receiveMessage ($sshMessage);
      } elseif ($sshMessage instanceof SSH\Debug)
        $this->___callback ('sshDebugMessage', $sshMessage);
      elseif ($sshMessage !== null)
        $this->___callback ('sshUnhandledMessage', $sshMessage);
      elseif ($packetLength > 0)
        trigger_error ('Unparsed message: ' . ord ($packetData [0]));
    }
    // }}}
    
    // {{{ failStream
    /**
     * Process a fatal error on the stream
     * 
     * @param string $failMessage
     * 
     * @access private
     * @return void
     **/
    private function failStream (string $failMessage) : void {
      // Check if we were initiating
      if ($this->connectPromise) {
        $connectPromise = $this->connectPromise;
        $this->connectPromise = null;
        
        $connectPromise->reject ($failMessage);
      }
      
      // Remove the stream
      $this->sourceStream->close ();
      $this->sourceStream = null;
    }
    // }}}
    
    // {{{ negotiateAlgorithm
    /**
     * Pick an algorithm for client-server-negotiations
     * 
     * @param array $referenceAlgorithms
     * @param array $offeredAlgorithms
     * @param array $algorithmNames
     * 
     * @access private
     * @return mixed
     **/
    private function negotiateAlgorithm (array $referenceAlgorithms, array $offeredAlgorithms, array $algorithmNames) {
      foreach ($referenceAlgorithms as $clientAlgorithm) {
        // Make sure this one is known
        if (!isset ($algorithmNames [$clientAlgorithm]))
          continue;
        
        // Make sure this one is also offered
        if (!in_array ($clientAlgorithm, $offeredAlgorithms))
          continue;
        
        return $algorithmNames [$clientAlgorithm];
      }
      
      return null;
    }
    // }}}
    
    // {{{ derviceKey
    /**
     * Derive a specific key from key-exchnage-result
     * 
     * @param string $keyID
     * @param int $keyLength (optional)
     * 
     * @access private
     * @return string
     **/
    private function deriveKey (string $keyID, int $keyLength = null) : string {
      // Find hash-function to use
      $hashName = array_search ($this->keyExchange->Params [1], self::$hashNames);
      
      // Generate first output
      $outHash = hash ($hashName, $this->keyExchange->secret . $this->keyExchange->hash . $keyID . $this->sessionID, true);
      $outLength = strlen ($outHash);
      
      if (($keyLength === null) || ($outLength == $keyLength))
        return $outHash;
        
      if ($outLength < $keyLength)
        for ($keyOffset = $outLength; $keyOffset < $keyLength; $keyOffset += $outLength)
          $outHash .= hash ($hashName, $this->keyExchange->secret . $this->keyExchange->hash . $outHash, true);
      
      return substr ($outHash, 0, $keyLength);
    }
    // }}}
    
    // {{{ checkInitialGreeting
    /**
     * Check and process initial greeting
     * 
     * @access private
     * @return void
     **/
    private function checkInitialGreeting () : void {
      // Check if the entire initial greeting is on the buffer
      if (($p = strpos ($this->streamBuffer, ($this->sshCompabilityMode ? "\n" : "\r\n"))) === false) {
        if (strlen ($this->streamBuffer) > 254)
          $this->failStream ('Initial greeting too big');
        
        return;
      }
      
      // Get the greeting off the buffer
      $this->remoteVersion = substr ($this->streamBuffer, 0, $p);
      $this->streamBuffer = substr ($this->streamBuffer, $p + 1);
      
      if ($this->sshCompabilityMode && (substr ($this->remoteVersion, -1, 1) == "\r"))
        $this->remoteVersion = substr ($this->remoteVersion, 0, -1);
      
      // Unpack the version
      if (substr ($this->remoteVersion, 0, 4) != 'SSH-') {
        $this->remoteVersion = null;
        
        return;
      }
      
      if(($p = strpos ($this->remoteVersion, '-', 4)) === false) {
        $this->failStream ('Invalid version: ' . $this->remoteVersion);
        
        return;
      }
      
      $protocolVersion = substr ($this->remoteVersion, 4, $p - 4);
      $softwareVersion = substr ($this->remoteVersion, $p + 1);
      
      if (
        !$this->sshCompabilityMode &&
        ($protocolVersion != '2.0') &&
        (($this->sshMode != self::MODE_CLIENT) || ($protocolVersion != '1.99'))
      ) {
        $this->failStream ('Invalid protocol-version: ' . $protocolVersion);
        
        return;
      }
      
      if (strlen ($this->streamBuffer) > 0)
        $this->processBuffer ();
    }
    // }}}
    
    // {{{ decryptBlock
    /**
     * Try to decrypt a block
     * 
     * @param string $cipherText
     * 
     * @access private
     * @return string
     **/
    private function decryptBlock (string $cipherText) : string {
      // Check if there is anything to do
      if ($this->cipherRemote [0] == self::CIPHER_NONE)
        return $cipherText;
      
      // Try to decrypt the block
      $plainText = openssl_decrypt (
        $cipherText,
        $this->cipherRemote [4],
        $this->cipherRemote [5],
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        $this->cipherRemote [6]
      );
      
      // Update Cipher-States
      if ($this->cipherRemote [2] == self::CIPHER_MODE_CTR)
        for ($i = strlen ($this->cipherRemote [6]) - 1; $i >= 0; $i--) {
          $c = ord ($this->cipherRemote [6][$i]);
          $this->cipherRemote [6][$i] = chr (($c + 1) & 0xFF);
          
          if ($c < 255)
            break;
        }
      
      elseif ($this->cipherRemote [2] == self::CIPHER_MODE_CBC)
        $this->cipherRemote [6] = $cipherText;
      
      // Return the result
      return $plainText;
    }
    // }}}
    
    
    // {{{ eventClosed
    /**
     * Callback: The interface was closed
     * 
     * @access public
     * @return void
     **/
    protected function eventClosed () : void {
      // No-Op
    }
    // }}}
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param Events\ABI\Stream $sourceStream
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (Events\ABI\Stream $sourceStream) : void {
      // No-Op
    }
    // }}}
    
    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\ABI\Source $sourceStream
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (Events\ABI\Source $sourceStream) : void {
      // No-Op
    }
    // }}}
    
    // {{{ authHostkey
    /**
     * Callback: Hostkey was received from server
     * 
     * @param SSH\PublicKey $hostkey
     * 
     * @access protected
     * @return void
     **/
    protected function authHostkey (SSH\PublicKey $hostkey) : void {
      // No-Op
    }
    // }}}
    
    // {{{ authBanner
    /**
     * Callback: A banner to display during authentication was received
     * 
     * @param string $sshMessage
     * @param string $bannerLanguage
     * 
     * @access protected
     * @return void
     **/
    protected function authBanner (string $sshMessage, string $bannerLangauge) : void {
      // No-Op
    }
    // }}}
    
    // {{{ authSuccessfull
    /**
     * Callback: Authentication was successfull
     * 
     * @access protected
     * @return void
     **/
    protected function authSuccessfull () : void {
      // No-Op
    }
    // }}}
    
    // {{{ sshGlobalRequest
    /**
     * Callback: Global request was received
     * 
     * @param SSH\GlobalRequest $globalRequest
     * 
     * @access protected
     * @return void
     **/
    protected function sshGlobalRequest (SSH\GlobalRequest $globalRequest) : void {
     // No-Op
    }
    // }}}
    
    // {{{ sshDebugMessage
    /**
     * Callback: A debug-message was received
     * 
     * @param SSH\Debug $debugMessage
     * 
     * @access protected
     * @return void
     **/
    protected function sshDebugMessage (SSH\Debug $debugMessage) : void {
      // No-Op
    }
    // }}}
    
    // {{{ sshUnhandledMessage
    /**
     * Callback: An unhandled SSH-Message was received
     * 
     * @param SSH\Message $unhandledMessage
     * 
     * @access protected
     * @return void
     **/
    protected function sshUnhandledMessage (SSH\Message $unhandledMessage) : void {
      // No-Op
    }
    // }}}
    
    // {{{ channelCreated
    /**
     * Callback: A channel was created
     * 
     * @param SSH\Channel $Channel
     * 
     * @access protected
     * @retrun void
     **/
    protected function channelCreated (SSH\Channel $sshChannel) : void {
     // No-Op
    }
    // }}}
    
    // {{{ channelConnected
    /**
     * Callback: A channel was successfully connected
     * 
     * @param SSH\Channel $sshChannel
     * 
     * @access protected
     * @return void
     **/
    protected function channelConnected (SSH\Channel $sshChannel) : void {
      // No-Op
    }
    // }}}
  }
