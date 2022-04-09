<?php

  /**
   * qcEvents - Socks Stream
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
  
  class Socks extends Events\Virtual\Source implements Events\ABI\Stream, Events\ABI\Stream\Consumer {
    /* Timeouts */
    public const NEGOTIATE_TIMEOUT = Events\Socket::CONNECT_TIMEOUT;
    public const AUTHENTICATE_TIMEOUT = 30;
    public const CONNECT_TIMEOUT = Events\Socket::CONNECT_TIMEOUT * 2;
    
    /* Connection-types */
    public const TYPE_TCP = 1;
    public const TYPE_TCP_SERVER = 2;
    public const TYPE_UDP = 3;
    
    /* Stream we are connected to */
    private $sourceStream = null;
    
    /* Buffer for incoming data */
    private $socksBuffer = '';
    
    /* Version we are using */
    public const SOCKS4  = 40;
    public const SOCKS4a = 41;
    public const SOCKS5  = 50;
    
    private $socksVersion = Socks::SOCKS5;
    
    /* Mode of this stream */
    public const MODE_CLIENT = 0;
    public const MODE_SERVER = 1;
    
    private $socksMode = Socks::MODE_CLIENT;
    
    /* Protocol-State */
    private const STATE_OPEN   = 0; // Connection is open, but unauthenticated
    private const STATE_UNAUTH = 1; // Connection is open and requires authentication
    private const STATE_AUTH   = 2; // Connection is open and authenticated
    private const STATE_CONN   = 3; // Connection is connected ;)
    private const STATE_CLOSE  = 4; // Connection is closed
    
    private $socksState = Socks::STATE_CLOSE;
    
    /* Offset password-authentication during greeting (since SOCKS5) */
    private $offerPasswordAuthentication = false;
    
    /* Promise-Callbacks for current operation */
    private $promiseCallbacks = null;
    
    // {{{ authenticate
    /**
     * Try to authenticate using username/password
     * 
     * @param string $userName
     * @param string $userPassword
     * 
     * @access public
     * @return Events\Promise
     **/
    public function authenticate (string $userName, string $userPassword) : Events\Promise {
      // Sanatize state
      if (
        ($this->socksState != self::STATE_UNAUTH) ||
        $this->promiseCallbacks
      )
        return Events\Promise::reject ('Invalid state');
      
      // Write the username/password to the wire
      $this->sourceStream->write (
        "\x01" .
        chr (strlen ($userName) & 0xFF) . substr ($userName, 0, 255) . 
        chr (strlen ($userPassword) & 0xFF) . substr ($userPassword, 0, 255)
      );
      
      // Create a promise
      return $this->createPromiseCallbacks (self::AUTHENTICATE_TIMEOUT);
    }
    // }}}
    
    // {{{ connect
    /**
     * Request a connection through the socks-server we are connected to
     * 
     * @param string $hostName
     * @param int $hostPort
     * qparam int $connectionType
     * 
     * @access public
     * @return Events\Promise
     **/
    public function connect (string $hostName, int $hostPort, int $connectionType = Socks::TYPE_TCP) : Events\Promise {
      // Sanatize state
      if (
        ($this->socksState != self::STATE_AUTH) ||
        $this->promiseCallbacks
      )
        return Events\Promise::reject ('Invalid state');
      
      // Sanatize port
      if (($hostPort < 0x0001) || ($hostPort > 0xFFFF))
        return Events\Promise::reject ('Invalid port');
      
      // Sanatize type
      if (
        ($connectionType > Socks::TYPE_TCP_SERVER) &&
        ($this->socksVersion < self::SOCKS5)
      )
        return Events\Promise::reject ('SOCKS4 only supports TCP-Connection-Types');
      
      // Check if we have to process an IPv6-Connection
      if ($isIPv6 = Events\Socket::isIPv6 ($hostName)) {
        // Make sure we are running at least in SOCKS5-Mode
        if ($this->socksVersion < self::SOCKS5)
          return Events\Promise::reject ('IPv6 is only supported since SOCKS5');
      
      // Check if we have to process a domainname for connection
      } elseif (!($isIPv4 = Events\Socket::isIPv4 ($hostName))) {
        if ($this->socksVersion < self::SOCKS4a)
          # TODO: Try to resolve manually here
          return Events\Promise::reject ('SOCKS4 cannot connect to a domainname');
      }
      
      // Build the message
      if ($this->socksVersion == self::SOCKS5) {
        $socksMessage = pack ('CCC', 5, $connectionType, 0);
        
        if ($isIPv4)
          $socksMessage .= "\x01" . pack ('N', ip2long ($hostName));
        elseif ($isIPv6)
          $socksMessage .= "\x04" . Events\Socket::ip6toBinary ($hostName);
        else
          $socksMessage .= "\x03" . chr (strlen ($hostName) & 0xFF) . substr ($hostName, 0, 255);
        
        $socksMessage .= pack ('n', $hostPort);
        
      } else {
        // Write protocol-version, connection-type and port to message
        $socksMessage = pack ('CCn', 4, $connectionType, $hostPort);
        
        // Write host to message
        if (!$isIPv4)
          $socksMessage .= pack ('NC', 0xFF, 0) . $hostName . "\x00";
        else
          $socksMessage .= pack ('NC', ip2long ($hostName), 0);
      }
      
      $this->sourceStream->write ($socksMessage);
      
      // Create a promise
      return $this->createPromiseCallbacks ($this::CONNECT_TIMEOUT);
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $sourceData
     * @param Events\ABI\Source $sourceStream
     * 
     * @access public
     * @return void
     **/
    public function consume ($sourceData, Events\ABI\Source $Source) : void {
      // Push to internal buffer
      $this->socksBuffer .= $sourceData;
      unset ($sourceData);
      
      // Process bytes on the buffer
      $bufferLength = strlen ($this->socksBuffer);
      $bufferOffset = 0;
      
      while ($bufferOffset < $bufferLength) {
        // Check if we are expecting a response to our greeting
        if ($this->socksState == self::STATE_OPEN) {
          // We only expect this in SOCKS5
          if ($this->socksVersion != self::SOCKS5) {
            $this->failConnection ('Unknown message in open-state');
            
            return;
          }
          
          // Check if there are enough bytes on the buffer
          if ($bufferLength - $bufferOffset < 2)
            break;
          
          // Read the greeting
          if (ord ($this->socksBuffer [$bufferOffset++]) != 0x05) {
            $this->failConnection ('Invalid version in greeting');
            
            return;
          }
          
          $authMethod = ord ($this->socksBuffer [$bufferOffset++]);
          
          switch ($authMethod) {
            case 0x00:
              $this->socksState = self::STATE_AUTH;
              
              break;
            case 0x02:
              $this->socksState = self::STATE_UNAUTH;
              
              break;
            case 0xFF:
              $this->failConnection ('No valid authentication-methods offered');
              
              return;
            
            default:
              $this->failConnection ('Invalid authentication-method requested');
              
              return;
          }
          
          $this->runPromiseCallback ();
          
          break;
        // Check if we are expecting an authentication-response
        } elseif ($this->socksState == self::STATE_UNAUTH) {
          // We only expect this in SOCKS5
          if ($this->socksVersion != self::SOCKS5) {
            $this->failConnection ('Unknown message in unauth-state');
            
            return;
          }
          
          // Check if there are enough bytes on the buffer
          if ($bufferLength - $bufferOffset < 2)
            break;
          
          // Check authentication-version
          if (ord ($this->socksBuffer [$bufferOffset++]) != 0x01) {
            $this->failConnection ('Invalid version for user-authentication');
            
            return;
          }
          
          // Check status
          if (ord ($this->socksBuffer [$bufferOffset++]) == 0x00)
            $this->socksState = self::STATE_AUTH;
          
          // Run callbacks
          $this->runPromiseCallback ($this->socksState == self::STATE_AUTH ? 0 : 1);
          
          // Fail if authentication wasn't successfull
          if ($this->socksState < self::STATE_AUTH) {
            $this->failConnection ('Authentication failed');
            
            return;
          }
          
          break;
        // Check if we are expecting a connection-response
        } elseif ($this->socksState == self::STATE_AUTH) {
          // Check SOCKS4-Response
          if ($this->socksVersion < self::SOCKS5) {
            // Check if there are enough bytes on the buffer
            if ($bufferLength - $bufferOffset < 8)
              break;
            
            if (ord ($this->socksBuffer [$bufferOffset++]) != 0x00) {
              $this->failConnection ('First byte is not NULL');
              
              return;
            }
            
            if (($resultByte = ord ($this->socksBuffer [$bufferOffset++])) != 0x5A) {
              $this->failConnection ('Connection failed with ' . $resultByte);
              
              return;
            }
            
            if ($this->socksVersion >= self::SOCKS4a) {
              $remotePort = (ord ($this->socksBuffer [$bufferOffset++]) << 8) | ord ($this->socksBuffer [$bufferOffset++]);
              $remoteIP = long2ip (
                (ord ($this->socksBuffer [$bufferOffset++]) << 24) |
                (ord ($this->socksBuffer [$bufferOffset++]) << 16) |
                (ord ($this->socksBuffer [$bufferOffset++]) << 8) |
                 ord ($this->socksBuffer [$bufferOffset++])
              );
            } else {
              $remoteIP = $remotePort = null;
              $bufferOffset += 6;
            }
          } else {
            // Check if there are enough bytes on the buffer
            if ($bufferLength - $bufferOffset < 8)
              break;
            
            $localOffset = $bufferOffset;
            
            if (ord ($this->socksBuffer [$localOffset++]) != 0x05) {
              $this->failConnection ('Invalid version');
              
              return;
            }
            
            if (($resultByte = ord ($this->socksBuffer [$localOffset++])) != 0x00) {
              $this->failConnection ('Connection failed with ' . $resultByte);
              
              return;
            }
            
            if (ord ($this->socksBuffer [$localOffset++]) != 0x00) {
              $this->failConnection ('Reserved byte is not NULL');
              
              return;
            }
            
            $remoteType = ord ($this->socksBuffer [$localOffset++]);
            
            if ($remoteType == 0x01) {
              if ($bufferLength - $localOffset < 6)
                break;
              
              $remoteIP = long2ip (
                (ord ($this->socksBuffer [$localOffset++]) << 24) |
                (ord ($this->socksBuffer [$localOffset++]) << 16) |
                (ord ($this->socksBuffer [$localOffset++]) << 8) |
                 ord ($this->socksBuffer [$localOffset++])
              );
            } elseif ($remoteType == 0x04) {
              if ($bufferLength - $localOffset < 18)
                break;
              
              $remoteIP = Events\Socket::ip6fromBinary (substr ($this->socksBuffer, $localOffset, 16));
              $localOffset += 16;
            } elseif ($remoteType == 0x03) {
              $domainLength = ord ($this->socksBuffer [$localOffset++]);
              
              if ($bufferLength - $localOffset < $domainLength + 2)
                break;
              
              $remoteIP = substr ($this->socksBuffer, $localOffset, $domainLength);
              $localOffset += $domainLength;
            } else {
              $this->failConnection ('Invalid address-type');
              
              return;
            }
            
            $bufferOffset = $localOffset;
            $remotePort = (ord ($this->socksBuffer [$bufferOffset++]) << 8) | ord ($this->socksBuffer [$bufferOffset++]);
          }
          
          // Update our state
          $this->socksState = self::STATE_CONN;
          
          // Run callbacks
          $this->runPromiseCallback (0, $remoteIP, $remotePort);
        } elseif ($this->socksState == self::STATE_CONN) {
          $this->sourceInsert (substr ($this->socksBuffer, $bufferOffset, $bufferLength - $bufferOffset));
          $bufferOffset = $bufferLength;
        } else
          break;
      }
      
      // Truncate read bytes from buffer
      if ($bufferOffset > 0)
        $this->socksBuffer = substr ($this->socksBuffer, $bufferOffset);
    }
    // }}}
    
    // {{{ write
    /**
     * Write data to this sink
     * 
     * @param string $sourceData The data to write to this sink
     * 
     * @access public
     * @return Events\Promise
     **/
    public function write ($sourceData) : Events\Promise {
      if (
        ($this->sourceStream === null) ||
        ($this->socksState != self::STATE_CONN)
      )
        return Events\Promise::reject ('Not connected');
      
      return $this->sourceStream->write ($sourceData);
    }
    // }}}
    
    // {{{ watchWrite
    /**
     * Set/Retrive the current event-watching status
     * 
     * @param bool $Set (optional) Set the status
     *  
     * @access public
     * @return bool
     **/
    public function watchWrite ($Set = null) {
      if ($this->sourceStream === null)
        return false;
      
      return $this->sourceStream->watchWrite ($Set);
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
      // Update our state
      $this->socksState = self::STATE_CLOSE;
      
      // Run a callback
      $this->___callback ('eventClosed');
      
      // Indicate success
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param Events\ABI\Source $sourceStream
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initConsumer (Events\ABI\Source $sourceStream) : Events\Promise {
      if ($sourceStream instanceof Events\ABI\Stream)
        return $this->initStreamConsumer ($sourceStream);
      
      return Events\Promise::reject ('Use pipeStream() instead of pipe()');
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param Events\ABI\Stream $sourceStream
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $sourceStream) : Events\Promise {
      # TODO: Remove any previously assigned stream
      
      // Assign the stream
      $this->sourceStream = $sourceStream;
      $this->sourceStream->addHook ('eventWritable', [ $this, 'raiseWritable' ]);
      
      // Send initial greeting if we are a SOCKS5-Client
      if (
        ($this->socksVersion == self::SOCKS5) &&
        ($this->socksMode == self::MODE_CLIENT)
      ) {
        // Update our state
        $this->socksState = self::STATE_OPEN;
        
        // Send the initial greeting
        if ($this->offerPasswordAuthentication)
          $this->sourceStream->write ("\x05\x02\x00\x02");
        else
          $this->sourceStream->write ("\x05\x01\x00");
        
        // Create a promise
        $initPromise = $this->createPromiseCallbacks (self::NEGOTIATE_TIMEOUT);
      } else {
        // Update our state
        $this->socksState = self::STATE_AUTH;
        
        // Create a resolved promise
        $initPromise = Events\Promise::resolve ();
      }
      
      // Return the promise
      return $initPromise->then (
        function () use ($sourceStream) {
          // Make sure our stream is still as expected
          if ($this->sourceStream !== $sourceStream)
            throw new \Exception ('Race-condition');
          
          // Run a callback
          $this->___callback ('eventPipedStream', $sourceStream);
        }
      );
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
      // Check if the source matches our stream
      if ($this->sourceStream !== $sourceStream)
        return Events\Promise::resolve ();
      
      // Remove the stream
      $this->sourceStream->removeHook ('eventWritable', [ $this, 'raiseWritable' ]);
      $this->sourceStream = null;
      
      // Close ourself and finish
      return $this->close ()->finally (
        function () use ($sourceStream) {
          $this->___callback ('eventUnpiped', $sourceStream);
        }
      );
    }
    // }}}
    
    // {{{ raiseWritable
    /**
     * Just raise a writable event here if suitable
     * 
     * @access public
     * @return void
     **/
    public function raiseWritable () : void {
      if ($this->sourceStream && ($this->socksState == self::STATE_CONN))
        $this->___callback ('eventWritable');
    }
    // }}}
    
    // {{{ failConnection
    /**
     * Fail the connection due to an error
     * 
     * @param string $failMessage
     * 
     * @access private
     * @return void
     **/
    private function failConnection (string $failMessage) : void {
      $this->close ();
      
      $this->runPromiseCallback (1, $failMessage);
    }
    // }}}
    
    // {{{ createPromiseCallbacks
    /**
     * Create and register a promise, optionally with a timeout
     * 
     * @param int $promiseTimeout (optional)
     * 
     * @access private
     * @return Events\Promise
     **/
    private function createPromiseCallbacks (int $promiseTimeout = null) : Events\Promise {
      return new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) use ($promiseTimeout) {
          // Try to create a timeout-function if requested
          if (
            ($promiseTimeout > 0) &&
            ($this->sourceStream instanceof Events\ABI\Based) &&
            is_object ($eventBase = $this->sourceStream->getEventBase ())
          ) {
            $promiseTimeout = $eventBase->addTimeout ($promiseTimeout);
            
            $promiseTimeout->then (
              function () {
                $this->failConnection ('Operation timed out');
              }
            );
          } else
            $promiseTimeout = null;
          
          // Register the promise
          $this->promiseCallbacks = [ $resolveFunction, $rejectFunction, $promiseTimeout ];
        }
      );
    }
    // }}}
    
    // {{{ runPromiseCallback
    /**
     * Run any pending promise-callback
     * 
     * @param int $callbackID (optional)
     * @param ...
     * 
     * @access private
     * @return void
     **/
    private function runPromiseCallback (int $callbackID = 0) : void {
      // Make sure there is a promise registered
      if (!$this->promiseCallbacks)
        return;
      
      // Stop any timer
      if ($this->promiseCallbacks [2])
        $this->promiseCallbacks [2]->cancel ();
      
      // Get and clear the callbacks
      $promiseCallback = $this->promiseCallbacks [$callbackID];
      $this->promiseCallbacks = null;
      
      // Run the callback
      call_user_func_array ($promiseCallback, array_slice (func_get_args (), 1));
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
    protected function eventPipedStream (Events\ABI\Stream $sourceStream) : void { }
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
    
    // {{{ eventWritable
    /**
     * Callback: A writable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventWritable () : void {
      // No-Op
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
  }
