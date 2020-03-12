<?PHP

  /**
   * qcEvents - Asyncronous POP3 Client
   * Copyright (C) 2015-2020 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Trait/Parented.php');
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Stream/POP3/Client.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * POP3 Client
   * -----------
   * Asynchronous POP3-Client
   * 
   * @class qcEvents_Client_POP3
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Client_POP3 extends qcEvents_Hookable {
    use qcEvents_Trait_Parented;
    
    /* Our underlying POP3-Stream */
    private $Stream = null;
    
    // {{{ __construct
    /**
     * Create a new POP3-Client
     * 
     * @param qcEvents_Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase) {
      $this->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ connect
    /**
     * Establish a TCP-Connection to a POP3-Server
     * 
     * @param string $Hostname
     * @param int $Port (optional)
     * @param string $Username (optional)
     * @param string $Password (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function connect ($Hostname, $Port = null, $Username = null, $Password = null) : qcEvents_Promise {
      // Check wheter to close an active stream first
      if ($this->Stream)
        $waitPromise = $this->Stream->close ()->catch (function () { });
      else
        $waitPromise = qcEvents_Promise::resolve ();
      
      // Determine which port to use
      if ($Port === null)
        $Port = 110;
      
      return $waitPromise->then (
        function () use ($Hostname, $Port) {
          // Create a socket for the stream
          $Socket = new qcEvents_Socket ($this->getEventBase ());
          
          // Try to connect to server
          return $Socket->connect ($Hostname, $Port, qcEvents_Socket::TYPE_TCP)->then (
            function () use ($Socket) {
              return $Socket;
            }
          );
        }
      )->then (
        function (qcEvents_Socket $Socket) {
          // Create a new POP3-Stream
          $Stream = new qcEvents_Stream_POP3_Client;
          
          // Connect both streams
          return $Socket->pipeStream ($Stream)->then (
            function () use ($Stream) {
              return $Stream;
            }
          );
        }
      )->then (
        function (qcEvents_Stream_POP3_Client $Stream) use ($Hostname, $Port, $Username, $Password, $Callback, $Private) {
          // Check wheter to start authentication
          if (($Username === null) || ($Password === null)) {
            $this->setStream ($Stream);
            
            return $this->___raiseCallback ($Callback, $Hostname, $Port, null, true, $Private);
          }
          
          # TODO: Negotiate TLS whenever possible (or requested)
          # TODO: Add support for APOP/SASL-Authentication
          
          return $Stream->login ($Username, $Password)->then (
            function () use ($Stream) {
              return $Stream;
            }
          );
        }
      )->then (
        function (qcEvents_Stream_POP3_Client $Stream) {
          $this->setStream ($Stream);
        },
        function () {
          // Remoev the stream
          if ($this->Stream)
            $this->Stream->close ();
          
          $this->unsetStream ();
          
          // Run all callbacks
          $this->___callback ('popConnectionFailed');
          
          // Forward the error
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ getStream
    /**
     * Retrive instance of our connected stream
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function getStream () : qcEvents_Promise {
      if ($this->Stream)
        return qcEvents_Promise::resolve ($this->Stream);
      
      return qcEvents_Promise::reject ('Not connected to a stream');
    }
    // }}}
    
    // {{{ setStream
    /**
     * Setup a stream-handle for this client
     * 
     * @param qcEvents_Stream_POP3_Client $Stream
     * 
     * @access private
     * @return void
     **/
    private function setStream (qcEvents_Stream_POP3_Client $Stream) {
      // Set the stream
      $this->Stream = $Stream;
      
      // Register hooks
      $Stream->addHook (
        'popStateChanged',
        function (qcEvents_Stream_POP3_Client $Stream, $oldState, $newState) {
          if ($this->Stream === $Stream)
            $this->___callback ('popStateChanged', $oldState, $newState);
        }
      );
      
      $Stream->addHook (
        'popDisconnected',
        function (qcEvents_Stream_POP3_Client $Stream) {
          if ($this->Stream !== $Stream)
            return;
          
          $this->unsetStream ();
        }
      );
      
      $Stream->addHook (
        'popCapabilities',
        function (qcEvents_Stream_POP3_Client $Stream, $Capabilities) {
          if ($this->Stream === $Stream)
            $this->___callback ('popCapabilities', $Capabilities);
        }
      );
      
      $Stream->addHook (
        'popAuthenticated',
        function (qcEvents_Stream_POP3_Client $Stream, $Username) {
          if ($this->Stream === $Stream)
            $this->___callback ('popAuthenticated', $Username);
        }
      );
      
      // Fire initial callback
      $this->___callback ('popConnected');
    }
    // }}}
    
    // {{{ unsetStream
    /**
     * Remove a client-stream from this client
     * 
     * @access private
     * @return void
     **/
    private function unsetStream () {
      $this->Stream = null;
      $this->___callback ('popDisconnected');
    }
    // }}}
    
    // {{{ getCapabilities
    /**
     * Retrive the capabilities from server
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getCapabilities () : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) use ($Callback, $Private) {
          return $popStream->getCapabilities ();
        }
      );
    }
    // }}}
    
    // {{{ haveCapability
    /**
     * Check if the server supports a given capability
     * 
     * @param string $Capability
     * 
     * @access public
     * @return bool
     **/
    public function haveCapability ($Capability) {
      if (!$this->Stream)
        return null;
      
      return $this->Stream->haveCapability ($Capability);
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Try to enable encryption on this connection
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function startTLS () : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) {
          return $popStream->startTLS ();
        }
      );
    }
    // }}}
    
    // {{{ login
    /**
     * Perform USER/PASS login on server
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function login ($Username, $Password) : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popClient) use ($Username, $Password) {
          return $popClient->login ($Username, $Password);
        }
      );
    }
    // }}}
    
    // {{{ apop
    /**
     * Perform login using APOP
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function apop ($Username, $Password) : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) use ($Username, $Password) {
          return $popStream->apop ($Username, $Password);
        }
      );
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Perform login using AUTH
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function authenticate ($Username, $Password) : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) use ($Username, $Password) {
          return $popStream->authenticate ($Username, $Password);
        }
      );
    }
    // }}}
    
    // {{{ stat
    /**
     * Retrive statistical data about this mailbox
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function stat () : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) {
          return $popStream->stat ();
        }
      );
    }
    // }}}
    
    // {{{ messageSize
    /**
     * Retrive the size of a given message
     * 
     * @param int $Index
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function messageSize ($Index) : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) use ($Index) {
          return $popStream->messageSize ($Index);
        }
      );
    }
    // }}}
    
    // {{{ messageSizes
    /**
     * Retrive the sizes of all messages
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function messageSizes () : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) {
          return $popStream->messageSizes ();
        }
      );
    }
    // }}}
    
    // {{{ getUID
    /**
     * Retrive the UID of a given message
     * 
     * @param int $Index
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getUID ($Index) : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) use ($Index) {
          return $popStream->getUID ($Index);
        }
      );
    }
    // }}}
    
    // {{{ getUIDs
    /**
     * Retrive the UIDs of all messages
     *  
     * @access public
     * @return qcEvents_Promise
     **/
    public function getUIDs () : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) {
          return $popStream->getUIDs ();
        }
      );
    }
    // }}}
    
    // {{{ getMessage
    /**
     * Retrive a message by index
     * 
     * @param int $Index
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getMessage ($Index) : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) use ($Index) {
          return $popStream->getMessage ($Index);
        }
      );
    }
    // }}}
    
    // {{{ getMessageLines
    /**   
     * Retrive the entire header and a given number of lines from a message
     * 
     * @param int $Index
     * @param int $Lines
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getMessageLines ($Index, $Lines) : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) use ($Index, $Lines) {
          return $popStream->getMessageLines ($Index, $Lines);
        }
      );
    }
    // }}}
    
    // {{{ deleteMessage
    /**
     * Remove a message from server
     * 
     * @param int $Index
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deleteMessage ($Index) : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) use ($Index) {
          return $popStream->deleteMessage ($Index);
        }
      );
    }
    // }}}
    
    // {{{ noOp
    /**
     * Merely keep the connection alive
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function noOp () : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) {
          return $popStream->noOp ();
        }
      );
    }
    // }}}
    
    // {{{ reset
    /**   
     * Reset Message-Flags
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function reset () : qcEvents_Promise {
      return $this->getStream ()->then (
        function (qcEvents_Stream_POP3_Client $popStream) {
          return $popStream->reset ();
        }
      );
    }
    // }}}
    
    // {{{ close
    /**
     * Close the POP3-Connection
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      if (!$this->Stream)
        return qcEvents_Promise::resolve ();
      
      $Stream = $this->Stream;
      $this->Stream = null;
      
      return $Stream->close ();
    }
    // }}}
    
    
    // {{{ popStateChanged
    /**
     * Callback: Our protocol-state was changed
     * 
     * @param enum $newState
     * @param enum $oldState
     *  
     * @access protected
     * @return void
     **/
    protected function popStateChanged ($newState, $oldState) { }
    // }}}
    
    // {{{ popConnecting
    /**
     * Callback: We are connecting to the server
     * 
     * @access protected
     * @return void
     **/
    protected function popConnecting () { }
    // }}}
    
    // {{{ popConnected
    /**
     * Callback: The connection to POP3-Server was established
     * 
     * @access protected
     * @return void
     **/
    protected function popConnected () { }
    // }}}
    
    // {{{ popConnectionFailed
    /** 
     * Callback: Connection-Attemp failed
     * 
     * @access protected
     * @return void
     **/
    protected function popConnectionFailed () { }
    // }}}
    
    // {{{ popDisconnected
    /** 
     * Callback: POP3-Connection was closed, Client is in Disconnected-State
     *    
     * @access protected
     * @return void
     **/
    protected function popDisconnected () { }
    // }}}
    
    // {{{ popCapabilities
    /**
     * Callback: Server-Capabilities were received/changed
     * 
     * @param array $Capabilties
     * 
     * @access protected
     * @return void
     **/
    protected function popCapabilities ($Capabilities) { }
    // }}}
    
    // {{{ popAuthenticated
    /**
     * Callback: POP3-Connection was authenticated
     * 
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function popAuthenticated ($Username) { }
    // }}}
  }

?>