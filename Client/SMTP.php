<?PHP

  /**
   * qcEvents - Asyncronous SMTP Client
   * Copyright (C) 2017 Bernd Holzmueller <bernd@quarxconnect.de>
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
  require_once ('qcEvents/Stream/SMTP/Client.php');
  require_once ('qcEvents/Socket/Pool.php');
  
  /**
   * SMTP-Client
   * ----------- 
   * Simple SMTP-Client-Implementation (RFC 5321)
   * 
   * @class qcEvents_Client_SMTP
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 02
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   **/
  class qcEvents_Client_SMTP extends qcEvents_Hookable {
    /* Socket-Pool */
    private $Pool = null;
    
    /* Hostname or IP to use as SMTP-Server */
    private $remoteHost = '';
    
    /* Port to connect to */
    private $remotePort = 25;
    
    /* Enable/Disable TLS-Encryption (NULL: Auto-detect) */
    private $remoteTLS = null;
    
    /* Username for authentication */
    private $remoteUser = null;
    
    /* Password for authentication */
    private $remotePassword = null;
    
    // {{{ __construct
    /**
     * Create a new SMTP-Client
     * 
     * @param qcEvents_Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase, $Hostname = null, $Port = null, $TLS = null) {
      // Create new socket-pool
      $this->Pool = new qcEvents_Socket_Pool ($eventBase);
      $this->Pool->addHook ('socketConnected', function (qcEvents_Socket_Pool $Pool, qcEvents_Socket $Socket) {
        // Create a new SMTP-Client
        $Client = new qcEvents_Stream_SMTP_Client;
        
        // Connect ourself with that socket
        return $Socket->pipeStream ($Client, true, function (qcEvents_Socket $Socket, $Status) use ($Client) {
          // Check if SMTP was setup correctly
          if (!$Status)
            return $this->Pool->releaseSocket ($Socket);
          
          // Check wheter to enable TLS
          if (($this->remoteTLS === true) && !$Client->hasFeature ('STARTTLS') && !$Socket->tlsEnable ())
            return $this->Pool->releaseSocket ($Socket);
          
          // Try to enable TLS
          if (($this->remoteTLS !== false) && $Client->hasFeature ('STARTTLS') && !$Socket->tlsEnable ())
            return $Client->startTLS (function (qcEvents_Stream_SMTP_Client $Client, $Status) use ($Socket) {
              // Check if TLS was enabled (and is required)
              if (!$Status && ($this->remoteTLS !== null))
                return $this->Pool->releaseSocket ($Socket);
              
              // Check wheter to perform authentication
              if ($this->remoteUser === null)
                return $this->Pool->enableSocket ($Socket, $Client);
              
              // Try to authenticate
              return $Client->authenticate (
                $this->remoteUser,
                $this->remotePassword,
                function (qcEvents_Stream_SMTP_Client $Client, $Username, $Status) use ($Socket) {
                  if (!$Status)
                    return $this->Pool->releaseSocket ($Socket);
                  
                  return $this->Pool->enableSocket ($Socket, $Client);
                }
              );
            });
          
          // Check wheter to perform authentication
          if ($this->remoteUser === null)
            return $this->Pool->enableSocket ($Socket, $Client);
          
          // Try to authenticate
          return $Client->authenticate (
            $this->remoteUser,
            $this->remotePassword,
            function (qcEvents_Stream_SMTP_Client $Client, $Username, $Status) use ($Socket) {
              if (!$Status)
                return $this->Pool->releaseSocket ($Socket);
            
              return $this->Pool->enableSocket ($Socket, $Client);
            }
          );
        });
      });
      
      // Setup ourself
      if ($Hostname !== null)
        $this->setRemoteHost ($Hostname, $Port, $TLS);
    }
    // }}}
    
    // {{{ setRemoteHost
    /**
     * Store IP/Hostname and port to connect to
     * 
     * @param mixed $Host
     * @param int $Port (optional)
     * @param bool $enableTLS (optional)
     * 
     * @access public
     * @return void
     **/
    public function setRemoteHost ($Host, $Port = null, $enableTLS = null) {
      $this->remoteHost = $Host;
      $this->remotePort = ($Port === null ? null : (int)$Port);
      $this->remoteTLS = ($enableTLS === null ? null : !!$enableTLS);
      
      if ($this->remoteTLS === false)
        trigger_error ('Forcing TLS-Encyption OFF is NOT RECOMMENDED!');
    }
    // }}}
    
    // {{{ setCredentials
    /**
     * Store username/password for authentication
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return void
     **/
    public function setCredentials ($Username, $Password) {
      $this->remoteUser = $Username;
      $this->remotePassword = $Password;
    }
    // }}}
    
    // {{{ sendMail
    /**
     * @param string $Originator
     * @param array $Receivers
     * @param string $Mail
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_SMTP $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function sendMail ($Originator, array $Receivers, $Mail, callable $Callback = null, $Private = null) {
      return $this->Pool->acquireSocket (
        $this->remoteHost,
        ($this->remotePort === null ? 25 : $this->remotePort),
        qcEvents_Socket::TYPE_TCP,
        ($this->remotePort == 465),
        function (qcEvents_Socket_Pool $Pool, qcEvents_Socket $Socket = null, qcEvents_Interface_Stream_Consumer $Client = null)
        use ($Originator, $Receivers, $Mail, $Callback, $Private) {
          // Check if a socket was aquired
          if (!$Socket || !$Client || !($Client instanceof qcEvents_Stream_SMTP_Client)) {
            // Release the socket
            if ($Socket)
              $Pool->releaseSocket ($Socket);
            
            // Indicate an error
            return $this->___raiseCallback ($Callback, false, $Private);
          }
          
          // Try to send the mail
          return $Client->sendMail (
            $Originator,
            $Receivers,
            $Mail,
            function (qcEvents_Stream_SMTP_Client $Client, $Originator, $Receivers, $Mail, $Status)
            use ($Pool, $Socket, $Callback, $Private) {
              // Release the socket
              $Pool->releaseSocket ($Socket);
              
              // Forward the result
              return $this->___raiseCallback ($Callback, $Status, $Private);
            }
          );
        }
      );
    }
    // }}}
  }

?>
