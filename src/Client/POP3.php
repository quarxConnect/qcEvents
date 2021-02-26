<?php

  /**
   * quarxConnect Events - Asyncronous POP3 Client
   * Copyright (C) 2015-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Client;
  use quarxConnect\Events\Stream;
  use quarxConnect\Events;
  
  /**
   * POP3 Client
   * -----------
   * Asynchronous POP3-Client
   * 
   * @class POP3
   * @extends Events\Hookable
   * @package quarxConnect/Events
   * @revision 02
   **/
  class POP3 extends Events\Hookable {
    use Events\Trait\Based;
    
    /* Our underlying POP3-Stream */
    private $Stream = null;
    
    // {{{ __construct
    /**
     * Create a new POP3-Client
     * 
     * @param Events\Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase) {
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
     * @param bool $requireTLS (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function connect ($Hostname, $Port = null, $Username = null, $Password = null, $requireTLS = true) : Events\Promise {
      // Check wheter to close an active stream first
      if ($this->Stream)
        $waitPromise = $this->Stream->close ()->catch (function () { });
      else
        $waitPromise = Events\Promise::resolve ();
      
      // Determine which port to use
      if ($Port === null)
        $Port = 110;
      
      return $waitPromise->then (
        function () use ($Hostname, $Port) {
          // Create a socket for the stream
          $Socket = new Events\Socket ($this->getEventBase ());
          
          // Try to connect to server
          return $Socket->connect (
            $Hostname,
            $Port,
            $Socket::TYPE_TCP
          )->then (
            function () use ($Socket) {
              return $Socket;
            }
          );
        }
      )->then (
        function (Events\Socket $Socket) {
          // Create a new POP3-Stream
          $Stream = new Stream\POP3\Client ();
          
          // Connect both streams
          return $Socket->pipeStream ($Stream)->then (
            function () use ($Stream) {
              return $Stream;
            }
          );
        }
      )->then (
        function (Stream\POP3\Client $Stream) use ($requireTLS) {
          if ($requireTLS === false)
            return $Stream;
          
          return $Stream->startTLS ()->then (
            function () use ($Stream) {
              return $Stream;
            },
            function () use ($Stream, $requireTLS) {
              if (!$requireTLS && !$Stream->haveCapability ('STLS'))
                return $Stream;
              
              throw new Events\Promise\Solution (func_get_args ());
            }
          );
        }
      )->then (
        function (Stream\POP3\Client $Stream) use ($Username, $Password) {
          // Check wheter to start authentication
          if (($Username === null) || ($Password === null)) {
            $this->setStream ($Stream);
            
            return $Stream;
          }
          
          # TODO: Add support for APOP/SASL-Authentication
          
          return $Stream->login ($Username, $Password)->then (
            function () use ($Stream) {
              return $Stream;
            }
          );
        }
      )->then (
        function (Stream\POP3\Client $Stream) {
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
          throw new Events\Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ getStream
    /**
     * Retrive instance of our connected stream
     * 
     * @access private
     * @return Events\Promise
     **/
    private function getStream () : Events\Promise {
      if ($this->Stream)
        return Events\Promise::resolve ($this->Stream);
      
      return Events\Promise::reject ('Not connected to a stream');
    }
    // }}}
    
    // {{{ setStream
    /**
     * Setup a stream-handle for this client
     * 
     * @param Stream\POP3\Client $Stream
     * 
     * @access private
     * @return void
     **/
    private function setStream (Stream\POP3\Client $Stream) {
      // Set the stream
      $this->Stream = $Stream;
      
      // Register hooks
      $Stream->addHook (
        'popStateChanged',
        function (Stream\POP3\Client $Stream, $oldState, $newState) {
          if ($this->Stream === $Stream)
            $this->___callback ('popStateChanged', $oldState, $newState);
        }
      );
      
      $Stream->addHook (
        'popDisconnected',
        function (Stream\POP3\Client $Stream) {
          if ($this->Stream !== $Stream)
            return;
          
          $this->unsetStream ();
        }
      );
      
      $Stream->addHook (
        'popCapabilities',
        function (Stream\POP3\Client $Stream, $Capabilities) {
          if ($this->Stream === $Stream)
            $this->___callback ('popCapabilities', $Capabilities);
        }
      );
      
      $Stream->addHook (
        'popAuthenticated',
        function (Stream\POP3\Client $Stream, $Username) {
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
     * @return Events\Promise
     **/
    public function getCapabilities () : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
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
     * @return Events\Promise
     **/
    public function startTLS () : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
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
    public function login ($Username, $Password) : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
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
     * @return Events\Promise
     **/
    public function apop ($Username, $Password) : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
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
     * @return Events\Promise
     **/
    public function authenticate ($Username, $Password) : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ stat
    /**
     * Retrive statistical data about this mailbox
     * 
     * @access public
     * @return Events\Promise
     **/
    public function stat () : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ messageSize
    /**
     * Retrive the size of a given message
     * 
     * @param int $Index
     * 
     * @access public
     * @return Events\Promise
     **/
    public function messageSize ($Index) : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ messageSizes
    /**
     * Retrive the sizes of all messages
     * 
     * @access public
     * @return Events\Promise
     **/
    public function messageSizes () : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ getUID
    /**
     * Retrive the UID of a given message
     * 
     * @param int $Index
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getUID ($Index) : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ getUIDs
    /**
     * Retrive the UIDs of all messages
     *  
     * @access public
     * @return Events\Promise
     **/
    public function getUIDs () : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ getMessage
    /**
     * Retrive a message by index
     * 
     * @param int $Index
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getMessage ($Index) : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
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
     * @return Events\Promise
     **/
    public function getMessageLines ($Index, $Lines) : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ deleteMessage
    /**
     * Remove a message from server
     * 
     * @param int $Index
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deleteMessage ($Index) : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ noOp
    /**
     * Merely keep the connection alive
     * 
     * @access public
     * @return Events\Promise
     **/
    public function noOp () : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ reset
    /**   
     * Reset Message-Flags
     * 
     * @access public
     * @return Events\Promise
     **/
    public function reset () : Events\Promise {
      return $this->wrapClientCall (__FUNCTION__, func_get_args ());
    }
    // }}}
    
    // {{{ wrapClientCall
    /**
     * Forward a call to this client to our stream
     * 
     * @param string $functionName
     * @param array $functionParameters
     * 
     * @access private
     * @return Events\Promise
     **/
    private function wrapClientCall ($functionName, array $functionParameters) : Events\Promise {
      return $this->getStream ()->then (
        function (Stream\POP3\Client $popStream)
        use ($functionName, $functionParameters) {
          return call_user_func_array (
            [ $popStream, $functionName],
            $functionParameters
          );
        }
      );
    }
    // }}}
    
    // {{{ close
    /**
     * Close the POP3-Connection
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      if (!$this->Stream)
        return Events\Promise::resolve ();
      
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
