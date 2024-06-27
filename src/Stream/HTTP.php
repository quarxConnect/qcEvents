<?php

  /**
   * quarxConnect Events - HTTP-Stream Implementation
   * Copyright (C) 2012-2022 Bernd Holzmueller <bernd@quarxconnect.de>
   * Copyright (C) 2023 Bernd Holzmueller <bernd@innorize.gmbh>
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

  namespace quarxConnect\Events\Stream;
  use quarxConnect\Events;
  
  /**
   * HTTP-Stream
   * -----------
   * Abstract HTTP-Stream-Handler (common functions for both - client and server)
   * 
   * @class  HTTP
   * @extends HTTP\Header
   * @package quarxConnect\Events
   * @revision 02
   **/
  abstract class HTTP extends HTTP\Header implements Events\ABI\Consumer, Events\ABI\Stream\Consumer, Events\ABI\Source {
    // Just use everything from the trait
    use Events\Feature\Hookable;
    use Events\Feature\Based;
    use Events\Feature\Pipe;
    
    /* HTTP States */
    public const HTTP_STATE_DISCONNECTED = 0; // Stream is disconnected
    public const HTTP_STATE_CONNECTED    = 1; // Stream is connected
    public const HTTP_STATE_HEADER       = 2; // Headers are being sent/received
    public const HTTP_STATE_BODY         = 3; // Body/Payload is being sent/received
    
    /* Our current state */
    private $httpState = HTTP::HTTP_STATE_DISCONNECTED;
    
    /* Source for this HTTP-Stream */
    private $streamSource = null;
    
    /* Don't try to read any body-data */
    private $bodySuppressed = false;
    
    /* Our Read-Buffer */
    private $bufferRead = '';
    
    /* Buffer for header-lines */
    private $bufferHeader = [ ];
    
    /* Received HTTP-Header */
    protected static $remoteHeaderClass = '\quarxConnect\Events\Stream\HTTP\Header';
    
    private $HeaderClass = null;
    private $remoteHeader = null;
    
    /* Buffer for our body */
    private $bufferBody = '';
    
    /* Read-pos on buffer-body */
    private $bufferBodyPos = 0;
    
    /* Keep buffered body on reads */
    private $bufferBodyKeep = false;
    
    /* Use the whole data as body */
    private $bufferCompleteBody = false;
    
    /* Encoding of body */
    private $bodyEncodings = [ ];
    
    // {{{ setHeaderClass
    /**
     * Set the class to use for received headers
     * 
     * @param string $Class
     * 
     * @access public
     * @return bool
     **/
    public function setHeaderClass ($Class) {
      if (!class_exists ($Class) || !(is_subclass_of ($Class, HTTP\Header::class) || (strcasecmp ($Class, HTTP\Header::class) == 0)))
        return false;
      
      $this->HeaderClass = $Class;
      
      return true;
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data
     * @param Events\ABI\Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, Events\ABI\Source $Source) {
      if ($this->httpState == $this::HTTP_STATE_DISCONNECTED)
        return trigger_error ('consume() while disconnected');
      
      // Check if we are just waiting for the connection to be closed
      if ($this->bufferCompleteBody) {
        $this->httpBodyAppend ($Data);
        
        return;
      }
      
      // Append data to our internal buffer
      $this->bufferRead .= $Data;
      unset ($Data);
      
      // Leave connected-state if neccessary
      if ($this->httpState == $this::HTTP_STATE_CONNECTED)
        $this->httpSetState ($this::HTTP_STATE_HEADER);
      
      // Read the header
      if ($this->httpState == $this::HTTP_STATE_HEADER)
        while (($p = strpos ($this->bufferRead, "\n")) !== false) {
          // Retrive the current line
          $Line = substr ($this->bufferRead, 0, $p);
          $this->bufferRead = substr ($this->bufferRead, $p + 1);
          
          if (($p > 0) && ($Line [$p - 1] == "\r"))
            $Line = substr ($Line, 0, -1);
          
          // Check if header is finished
          if (strlen ($Line) == 0) {
            if (count ($this->bufferHeader) == 0)
              continue;
            
            // Create the header
            if ($this->HeaderClass !== null)
              $remoteHeaderClass = $this->HeaderClass;
            else
              $remoteHeaderClass = $this::$remoteHeaderClass;
            
            try {
              $this->remoteHeader = new $remoteHeaderClass ($this->bufferHeader);
            } catch (\Throwable $headerError) {
              $this->___callback ('httpFailed', null, null);
              
              $this->httpSetState (self::HTTP_STATE_DISCONNECTED);
              
              $this->close ();
              $this->reset ();
              
              return;
            }
            
            $this->bufferHeader = [ ];
            
            // Fire a callback for this event
            $this->___callback ('httpHeaderReady', $this->remoteHeader);
            
            // Switch states
            if (!$this->expectBody () || !$this->remoteHeader->hasBody ()) {
              $this->httpSetState (self::HTTP_STATE_CONNECTED);
              
              return $this->___callback ('httpFinished', $this->remoteHeader, null);
            }
            
            $this->httpSetState ($this::HTTP_STATE_BODY);
            
            // Prepare to retrive the body
            if ($this->remoteHeader->hasField ('transfer-encoding'))
              $this->bodyEncodings = explode (' ', trim ($this->remoteHeader->getField ('transfer-encoding')));
            else
              $this->bodyEncodings = [ 'identity' ];
            
            break;
          }
           
          // Push the line to header-buffer
          $this->bufferHeader [] = $Line;  
        }
      
      // Read Payload
      if ($this->httpState == $this::HTTP_STATE_BODY)
        // Handle chunked transfer
        if ($this->bodyEncodings [0] != 'identity') {
          // Check if we see the length of next chunk
          while (($p = strpos ($this->bufferRead, "\r\n")) !== false) {
            // Read the chunk-size
            $Chunk = substr ($this->bufferRead, 0, $p);
            
            if (($p2 = strpos ($Chunk, ';')) !== false)
              $Chunk = substr ($Chunk, 0, $p2);
            
            $Length = hexdec ($Chunk);
            
            // Check if the buffer is long enough
            if (strlen ($this->bufferRead) < $p + $Length + 4)
              return;
            
            // Copy the chunk to body buffer
            if ($Length > 0)
              $this->httpBodyAppend (substr ($this->bufferRead, $p + 2, $Length));
            
            $this->bufferRead = substr ($this->bufferRead, $p + $Length + 4);
            
            if ($Length == 0) {
              $this->httpBodyReceived ();
              
              return;
            }
          }
           
        // Check if there is a content-length givenk
        } elseif (($Length = $this->remoteHeader->getField ('content-length')) !== null) {
          $Length = (int)$Length;
          
          // Check if the buffer is big enough
          if (strlen ($this->bufferRead) < $Length)
            return;
          
          // Copy the buffer to local body
          $this->httpBodyAppend (substr ($this->bufferRead, 0, $Length));
          $this->bufferRead = substr ($this->bufferRead, $Length);   
          
          $this->httpBodyReceived ();
          
          return;
        // Wait until connection is closed
        } else {
          $this->bufferCompleteBody = true;
          $this->httpBodyAppend ($this->bufferRead);
          $this->bufferRead = '';
        }
    }
    // }}}
    
    // {{{ expectBody
    /**
     * Check if this header expects a body in response
     * 
     * @access private
     * @return bool
     **/
    private function expectBody () {
      if ($this->bodySuppressed)
        return false;
      
      if ($this->getType () == $this::TYPE_REQUEST)
        return ($this->getMethod () != 'HEAD');
      
      return true;
    }
    // }}}
    
    // {{{ httpBodyAppend
    /**
     * Append data to our internal buffer
     * 
     * @param string $Data
     * 
     * @access private
     * @return void
     **/
    private function httpBodyAppend ($Data) {
      if (strlen ($Data) == 0)
        return;
      
      $this->bufferBody .= $Data;
      # $this->___callback ('eventReadable');
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
      // Unregister the source
      if ($this->streamSource) {
        $this->streamSource->removeHook ('eventClosed', [ $this, 'httpStreamClosed' ]);
        $this->streamSource = null;
      }
      
      // Call our own handler
      $this->httpStreamClosed ();
      
      // Reset ourself
      $this->reset ();
      
      // Raise the callback
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param Events\ABI\Source $dataSource
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initConsumer (Events\ABI\Source $dataSource) : Events\Promise {
      // Check if this source is already set
      if ($this->streamSource === $dataSource)
        return Events\Promise::resolve ();
      
      // Check if there is an existing source
      if ($this->streamSource)
        $deinitPromise = $this->deinitConsumer ($this->streamSource)->catch (function () { });
      else
        $deinitPromise = Events\Promise::resolve ();
      
      return $deinitPromise->then (
        function () use ($dataSource) {
          // Reset ourself
          $this->reset ();
          $this->httpSetState ($this::HTTP_STATE_CONNECTED);
          
          // Set the new source
          $this->streamSource = $dataSource;
          
          // Register hooks there
          $dataSource->addHook ('eventClosed', [ $this, 'httpStreamClosed' ]);
          
          // Raise an event for this
          $this->___callback ('eventPiped', $dataSource);
        }
      );
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param Events\ABI\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $Source) : Events\Promise {
      // Check if this source is already set
      if ($this->streamSource === $Source)
        return Events\Promise::resolve ();
      
      // Check if there is an existing source
      if ($this->streamSource)
        $Promise = $this->deinitConsumer ($this->streamSource)->catch (function () { });
      else
        $Promise = Events\Promise::resolve ();
      
      return $Promise->then (
        function () use ($Source) {
          // Reset ourself
          $this->reset ();
          $this->httpSetState ($this::HTTP_STATE_CONNECTED);
          
          // Set the new source
          $this->streamSource = $Source;
          
          // Register hooks there
          $Source->addHook ('eventClosed', [ $this, 'httpStreamClosed' ]);
          
          // Raise an event for this
          $this->___callback ('eventPipedStream', $Source);
        }
      );
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
     * 
     * @param Events\ABI\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Events\ABI\Source $Source) : Events\Promise {
      // Check if this is the right source
      if ($this->streamSource !== $Source)
        return Events\Promise::resolve ();
      
      // Remove our hooks
      $Source->removeHook ('eventClosed', [ $this, 'httpStreamClosed' ]);
      
      // Unset the source
      $this->streamSource = null;
      
      // Raise an event for this
      $this->___callback ('eventUnpiped', $Source);
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ getRemoteHost
    /**
     * Retrive the hostname of the remote party
     * 
     * @access public
     * @return string|null
     **/
    public function getRemoteHost (): ?string
    {
      if ($this->streamSource instanceof Events\Socket)
        return $this->streamSource->getRemoteHost ();

      return null;
    }
    // }}}

    // {{{ getRemotePort
    /**
     * Retrive the port we are connected to
     * 
     * @access public
     * @return int
     **/
    public function getRemotePort (): ?int
    {
      if ($this->streamSource instanceof Events\Socket)
        return $this->streamSource->getRemotePort ();

      return null;
    }
    // }}}
    
    // {{{ getBuffer
    /**
     * Retrive everything from our buffer
     * 
     * @access public
     * @return string
     **/
    public function getBuffer () {
      return $this->bufferRead;
    }
    // }}}
    
    // {{{ reset
    /**
     * Reset our internal data
     * 
     * @access protected
     * @return void
     **/
    protected function reset () {
      $this->bodySuppressed = false;
      $this->bufferRead = '';
      $this->bufferHeader = [ ];
      $this->bufferBody = '';
      $this->bufferBodyPos = 0;
      $this->bufferCompleteBody = false;
      $this->remoteHeader = null;
      $this->bodyEncodings = [ ];
      $this->httpSetState ($this->streamSource ? $this::HTTP_STATE_CONNECTED : $this::HTTP_STATE_DISCONNECTED);
    }
    // }}}
    
    // {{{ httpHeaderWrite
    /**
     * Transmit a set of HTTP-Headers over the wire
     * 
     * @param HTTP\Header $Header
     * 
     * @access protected
     * @return Events\Promise
     **/
    protected function httpHeaderWrite (HTTP\Header $Header) : Events\Promise {
      // Make sure we have the right source for this
      if (!$this->streamSource || !($this->streamSource instanceof Events\ABI\Sink))
        return Events\Promise::reject ('No suitable source to write headers');
      
      if ($this->httpState != $this::HTTP_STATE_CONNECTED)
        return Events\Promise::reject ('Invalid state to send headers');
      
      // Try to write out the status
      $this->httpSetState ($this::HTTP_STATE_HEADER);
      
      return $this->streamSource->write (strval ($Header))->then (
        function () use ($Header): void {
          // Run the callback
          $this->___callback ('httpHeadersSent', $Header);
        },
        function (): void {
          $this->httpUnexpectedClose ();
        }
      );
    }
    // }}}
    
    // {{{ getPipeSource
    /**
     * Return the originator of our pipe
     * 
     * @access public
     * @return Events\ABI\Source
     **/
    public function getPipeSource () : ?Events\ABI\Source {
      return $this->streamSource;
    }
    // }}}
    
    // {{{ httpSetState
    /**
     * Change the state of the HTTP-Parser
     * 
     * @param enum $newState
     * 
     * @access private
     * @return void
     **/
    private function httpSetState ($newState) {
      // Check if the state changed
      if ($newState == $this->httpState)
        return;
      
      // Switch the state
      $oldState = $this->httpState;
      $this->httpState = $newState;
      
      // Fire up a callback
      $this->___callback ('httpStateChanged', $newState, $oldState);
    }
    // }}}
    
    // {{{ httpBodyReceived
    /**
     * Internal Callback: Body was received completly
     * 
     * @access private
     * @return void
     **/
    private function httpBodyReceived () {
      // Sanity-Check encodings
      if (count ($this->bodyEncodings) > 1)
        trigger_error ('More than one encoding found, this is unimplemented');
      
      // Change our state
      $this->httpSetState ($this::HTTP_STATE_CONNECTED);
      
      // Fire the callback
      if ($this->isPiped ()) {
        $this->streamSource->removeHook ('eventClosed', [ $this, 'httpStreamClosed' ]);
        $this->streamSource = null;
        $this->___callback ('eventClosed');
      }
      
      $this->___callback ('httpFinished', $this->remoteHeader, $this->bufferBody);
    }
    // }}}
    
    // {{{ httpUnexpectedClose
    /**
     * Internal Callback: Underlying socket was unexpected closed
     * 
     * @access private
     * @return void
     **/
    private function httpUnexpectedClose () {
      // Check if we are processing a request
      if (
        ($this->httpState != $this::HTTP_STATE_CONNECTED) &&
        ($this->httpState != $this::HTTP_STATE_DISCONNECTED)
      )
        $this->___callback ('httpFailed', $this->remoteHeader, $this->bufferBody);
      
      // Reset ourself
      $this->reset ();
    }
    // }}}
    
    // {{{ httpStreamClosed
    /** 
     * Internal Callback: Connection was closed
     * 
     * @access public
     * @return void
     **/
    public function httpStreamClosed () {
      # TODO: Sanity-Check the socket
      
      // Check if the stream closes as expected
      if (
        !$this->bufferCompleteBody ||
        ($this->httpState != $this::HTTP_STATE_BODY)
      ) {
        $this->httpUnexpectedClose ();

        return;
      }
      
      // Finish the request
      $this->httpBodyReceived ();
    }
    // }}}
    
    // {{{ isWatching
    /**
     * Check if we are registered on the assigned Event-Base and watching for events
     * 
     * @param bool $Set (optional) Toogle the state
     * 
     * @access public
     * @return bool  
     **/
    public function isWatching ($Set = null) {
      if (!$this->streamSource)
        return false;
      
      return $this->streamSource->isWatching ($Set);
    }
    // }}}
    
    // {{{ watchRead
    /** 
     * Set/Retrive the current event-watching status
     *    
     * @param bool $Set (optional) Set the status
     * 
     * @access public
     * @return bool  
     **/  
    public function watchRead ($Set = null) {
      if (!$this->streamSource)
        return false;
      
      return $this->streamSource->watchRead ($Set);
    }
    // }}}
    
    // {{{ read
    /**
     * Try to read from our body-buffer
     * 
     * @param int $readLength (optional)
     * 
     * @access public
     * @return string
     **/
    public function read (int $readLength = null) : ?string {
      if ($readLength === null)
        $readLength = max (0, strlen ($this->bufferBody) - $this->bufferBodyPos);
      
      if ($readLength < 1)
        return '';
      
      if ($this->bufferBodyKeep) {
        $Data = substr ($this->bufferBody, $this->bufferBodyPos, $readLength);
        $this->bufferBodyPos += strlen ($Data);
      } else {
        $Data = $this->bufferBody;
        $this->bufferBody = null;
        $this->bufferBodyPos = 0;
      }
      
      return $Data;
    }
    // }}}
    
    // {{{ eventReadable
    /**
     * Callback: A readable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventReadable () { }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: A HTTP-Stream was finished
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    // {{{ httpStateChanged
    /**
     * Callback: The HTTP-State was changed
     * 
     * @param enum $newState
     * @param enum $oldState
     * 
     * @access protected
     * @return void
     **/
    protected function httpStateChanged ($newState, $oldState) { }
    // }}}
    
    // {{{ httpHeadersSend
    /**
     * Callback: Stream is about to send HTTP-Headers to remote peer
     * 
     * @param HTTP\Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected function httpHeadersSend (HTTP\Header $Header) { }
    // }}}
    
    // {{{ httpHeadersSent
    /**
     * Callback: Headers were sent to remote peer
     * 
     * @param HTTP\Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected function httpHeadersSent (HTTP\Header $Header) { }
    // }}}
    
    // {{{ httpHeaderReady
    /**
     * Callback: The header was received completly
     * 
     * @param HTTP\Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected function httpHeaderReady (HTTP\Header $Header) { }
    // }}}
    
    // {{{ httpFinished
    /**
     * Callback: Single HTTP-Request/Response was finished
     * 
     * @param HTTP\Header $responseHeader
     * @param string $responseBody (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function httpFinished (HTTP\Header $responseHeader, string $responseBody = null) : void { }
    // }}}
    
    // {{{ httpFailed
    /**
     * Callback: Sinlge HTTP-Request/Response was not finished properly
     * 
     * @param HTTP\Header $responseHeader (optional)
     * @param string $responseBody (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function httpFailed (HTTP\Header $responseHeader = null, string $responseBody = null) : void { }
    // }}}
  }
