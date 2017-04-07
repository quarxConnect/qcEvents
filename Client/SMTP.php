<?PHP

  /**
   * qcEvents - Asyncronous SMTP Client
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Stream/SMTP/Client.php');
  require_once ('qcEvents/Trait/Parented.php');
  require_once ('qcEvents/Socket.php');
  
  /**
   * SMTP-Client
   * ----------- 
   * Simple SMTP-Client-Implementation (RFC 5321)
   * 
   * @class qcEvents_Client_SMTP
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   **/
  class qcEvents_Client_SMTP extends qcEvents_Stream_SMTP_Client {
    use qcEvents_Trait_Parented;
    
    private $Connected = false;
    
    // {{{ __construct
    /**
     * Create a new SMTP-Client
     * 
     * @param qcEvents_Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase) {
      $this->setEventBase ($eventBase);
      
      $this->addHook ('smtpConnected', function () { $this->Connected = true; });
      $this->addHook ('smtpDisconnected', function () { $this->Connected = false; });
    }
    // }}}
    
    // {{{ connect
    /**
     * Establish a TCP-Connection to a SMTP-Server
     * 
     * @param string $Hostname
     * @param int $Port (optional)
     * @param string $Username (optional)
     * @param string $Password (optional)
     * @param callable $Callback (optional) Callback to raise once the operation was finished
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * The Callback will be raised in the form of
     * 
     *   function (qcEvents_Client_SMTP $Client, string $Hostname, int $Port, string $Username = null, bool $Status, mixed $Private) { }
     * 
     * @access public  
     * @return bool
     **/
    public function connect ($Hostname, $Port = null, $Username = null, $Password = null, callable $Callback = null, $Private = null) {
      // Check if we are currently in connected state
      if ($this->Connected)
        $this->close (function (qcEvents_Client_SMTP $Self, $Status) use ($Hostname, $Port, $Username, $Password, $Callback, $Private) {
          // Just assume this is true
          $this->Connected = false;
          
          // Try to connect again
          $this->connect ($Hostname, $Port, $Username, $Password, $Callback, $Private);
        });
      
      // Determine which port to user
      if (($Port === null) || ($Port = 25))
        $Port = 25;
      
      // Create a socket for the stream
      $Socket = new qcEvents_Socket ($this->getEventBase ());
      
      // Try to connect to server
      $this->Connected = null;
      
      return $Socket->connect ($Hostname, $Port, qcEvents_Socket::TYPE_TCP, false, null, function (qcEvents_Socket $Socket, $Status) use ($Hostname, $Port, $Username, $Password, $Callback, $Private) {
        // Check if the connection was established
        if (!$Status) {
          $this->Connected = false;
          $this->___callback ('smtpConnectionFailed');
          
          return $this->___raiseCallback ($Callback, $Hostname, $Port, $Username, false, $Private);
        }
        
        // Connect ourself with that socket
        return $Socket->pipeStream ($this, true, function ($x, qcEvents_Interface_Source $Source, qcEvents_Interface_Stream_Consumer $Destination, $Finish, $Status) use ($Hostname, $Port, $Username, $Password, $Callback, $Private) {
          // Check if the connection failed
          if (!($this->Connected = $Status))
            return $this->___raiseCallback ($Callback, $Hostname, $Port, $Username, false, $Private);
          
          // Always try to negotiate TLS
          return $this->startTLS (function (qcEvents_Client_SMTP $Self, $Status) use ($Username, $Password, $Callback, $Hostname, $Port, $Private) {
            // Check wheter to authenticate
            if (($Username === null) || ($Password === null) || !$Status)
              return $this->___raiseCallback ($Callback, $Hostname, $Port, null, $Status, $Private);
            
            // Try to authenticate
            return $this->authenticate ($Username, $Password, function (qcEvents_Stream_SMTP_Client $Self, $Username, $Status) use ($Hostname, $Port, $Callback, $Private) {
              // Check if the authentication was successfull
              if (!$Status) {
                // Indicate the connection as failed
                $this->___callback ('smtpConnectionFailed');
                
                // Reset the stream
                $this->close ();
              }
              
              // Raise the requested callback
              $this->___raiseCallback ($Callback, $Hostname, $Port, $Username, $Status, $Private);
            });
          });
        });
      });
    }
    // }}}
  }

?>
