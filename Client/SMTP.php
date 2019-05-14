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
        return $Socket->pipeStream ($Client, true)->then (
          function (qcEvents_Socket $Socket, $Status) use ($Client) {
            // Check wheter to enable TLS
            if (($this->remoteTLS === true) && !$Client->hasFeature ('STARTTLS') && !$Socket->tlsEnable ())
              return $this->Pool->releaseSocket ($Socket);
            
            // Try to enable TLS
            if (($this->remoteTLS !== false) && $Client->hasFeature ('STARTTLS') && !$Socket->tlsEnable ())
              return $Client->startTLS ()->catch (
                function () use ($Socket) {
                  // Check if TLS was required
                  if ($this->remoteTLS === null)
                    return;
                  
                  // Release the socket
                  $this->Pool->releaseSocket ($Socket);
                  
                  // Forward the error
                  throw new qcEvents_Promise_Solution (func_get_args ());
                }
              )->then (
                function () use ($Socket, $Client) {
                  // Check wheter to perform authentication
                  if ($this->remoteUser === null)
                    return $this->Pool->enableSocket ($Socket, $Client);
                  
                  // Try to authenticate
                  return $Client->authenticate (
                    $this->remoteUser,
                    $this->remotePassword
                  )->then (
                    function () use ($Socket, $Client) {
                      $this->Pool->enableSocket ($Socket, $Client);
                    },
                    function () use ($Socket) {
                      $this->Pool->releaseSocket ($Socket);
                      
                      throw new qcEvents_Promise_Solution (func_get_args ());
                    }
                  );
                }
              );
            
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
          },
          function () use ($Socket) {
            $this->Pool->releaseSocket ($Socket)
          }
        );
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
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function sendMail ($Originator, array $Receivers, $Mail) : qcEvents_Promise {
      return $this->Pool->acquireSocket (
        $this->remoteHost,
        ($this->remotePort === null ? 25 : $this->remotePort),
        qcEvents_Socket::TYPE_TCP,
        ($this->remotePort == 465)
      )->then (
        function (qcEvents_Socket $Socket, qcEvents_Interface_Stream_Consumer $Client = null)
        use ($Originator, $Receivers, $Mail) {
          // Make sure we got our SMTP-Stream
          if (!$Client || !($Client instanceof qcEvents_Stream_SMTP_Client)) {
            // Release the socket
            if ($Socket)
              $this->Pool->releaseSocket ($Socket);
            
            // Indicate an error
            throw new exception ('No SMTP-Client was acquired');
          }
          
          // Try to send the mail
          return $Client->sendMail ($Originator, $Receivers, $Mail)->then (
            function () use ($Socket) {
              // Release the socket
              $this->Pool->releaseSocket ($Socket);
            },
            function () use ($Socket) {
              // Release the socket
              $this->Pool->releaseSocket ($Socket);
              
              // Forward the error
              throw new qcEvents_Promise_Solution (func_get_args ());
            }
          );
        }
      );
    }
    // }}}
  }

?>
