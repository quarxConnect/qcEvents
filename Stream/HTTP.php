<?PHP

  /**
   * qcEvents - HTTP-Stream Implementation
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Consumer.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Interface/Source.php');
  require_once ('qcEvents/Trait/Hookable.php');
  require_once ('qcEvents/Trait/Pipe.php');
  require_once ('qcEvents/Stream/HTTP/Header.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * HTTP-Stream
   * -----------
   * Abstract HTTP-Stream-Handler (common functions for both - client and server)
   * 
   * @class qcEvents_Stream_HTTP
   * @extends qcEvents_Stream_HTTP_Header
   * @package qcEvents
   * @revision 02
   **/
  abstract class qcEvents_Stream_HTTP extends qcEvents_Stream_HTTP_Header implements qcEvents_Interface_Consumer, qcEvents_Interface_Stream_Consumer, qcEvents_Interface_Source {
    // Just use everything from the trait
    use qcEvents_Trait_Hookable, qcEvents_Trait_Pipe;
    
    /* HTTP States */
    const HTTP_STATE_CONNECTING = 0;
    const HTTP_STATE_WAITING    = 1;
    const HTTP_STATE_HEADER     = 2;
    const HTTP_STATE_BODY       = 3;
    const HTTP_STATE_FINISHED   = 4;
    
    /* Source for this HTTP-Stream */
    private $Source = null;
    
    /* Our current state */
    private $State = qcEvents_Stream_HTTP::HTTP_STATE_CONNECTING;
    
    /* Don't try to read any body-data */
    private $bodySuppressed = false;
    
    /* Our Read-Buffer */
    private $bufferRead = '';
    
    /* Buffer for header-lines */
    private $bufferHeader = array ();
    
    /* Received HTTP-Header */
    protected static $remoteHeaderClass = 'qcEvents_Stream_HTTP_Header';
    
    private $HeaderClass = null;
    private $Header = null;
    
    /* Buffer for our body */
    private $bufferBody = '';
    
    /* Read-pos on buffer-body */
    private $bufferBodyPos = 0;
    
    /* Keep buffered body on reads */
    private $bufferBodyKeep = false;
    
    /* Use the whole data as body */
    private $bufferCompleteBody = false;
    
    /* Encoding of body */
    private $bodyEncodings = array ();
    
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
      if (!class_exists ($Class) || !(is_subclass_of ($Class, 'qcEvents_Stream_HTTP_Header') || (strncmp ($Class, 'qcEvents_Stream_HTTP_Header') == 0)))
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
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source) {
      // Check if we are just waiting for the connection to be closed
      if ($this->bufferCompleteBody) {
        $this->httpBodyAppend ($Data);
        
        return;
      }
       
      // Append data to our internal buffer
      $this->bufferRead .= $Data;
      unset ($Data);
      
      // Leave waiting-state if neccessary
      if (($this->State == $this::HTTP_STATE_CONNECTING) || ($this->State == $this::HTTP_STATE_WAITING))
        $this->httpSetState ($this::HTTP_STATE_HEADER);
      
      // Read the header
      if ($this->State == $this::HTTP_STATE_HEADER)
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
              $Class = $this->HeaderClass;
            else
              $Class = $this::$remoteHeaderClass;
            
            $this->Header = new $Class ($this->bufferHeader);
            $this->bufferHeader = array ();
            
            // Fire a callback for this event
            $this->___callback ('httpHeaderReady', $this->Header);
            
            // Switch states
            if (!$this->expectBody () || !$this->Header->hasBody ()) {
              $this->httpSetState (self::HTTP_STATE_FINISHED);
              
              return $this->___callback ('httpFinished', $this->Header, null);
            }
            
            $this->httpSetState ($this::HTTP_STATE_BODY);
            
            // Prepare to retrive the body
            if (!$this->Header->hasField ('transfer-encoding'))
              $this->bodyEncodings = array ('identity');
            else
              $this->bodyEncodings = explode (' ', trim ($this->Header->getField ('transfer-encoding')));
            
            break;
          }
           
          // Push the line to header-buffer
          $this->bufferHeader [] = $Line;  
        }
      
      // Read Payload
      if ($this->State == $this::HTTP_STATE_BODY)
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
            
            if ($Length == 0)
              return $this->httpBodyReceived ();
          }
           
        // Check if there is a content-length givenk
        } elseif (($Length = $this->Header->getField ('content-length')) !== null) {
          // Check if the buffer is big enough
          if (strlen ($this->bufferRead) < $Length)
            return;
          
          // Copy the buffer to local body
          $this->httpBodyAppend (substr ($this->bufferRead, 0, $Length));
          $this->bufferRead = substr ($this->bufferRead, $Length);   
          
          return $this->httpBodyReceived ();
        
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
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      // Unregister the source
      $this->Source->removeHook ('socketDisconnected', array ($this, 'httpStreamClosed'));
      $this->Source = null;
      
      // Reset ourself
      $this->reset ();
      
      // Raise the callback
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Check if this source is already set
      if ($this->Source === $Source) {
        $this->___raiseCallback ($Callback, true, $Private);
        
        return false;
      }
      
      // Check if there is an existing source
      if ($this->Source)
        $Promise = $this->deinitConsumer ($this->Source);
      else
        $Promise = qcEvents_Promise::resolve ();
      
      $Promise->finally (
        function () use ($Source, $Callback, $Private) {
          // Reset ourself
          $this->reset ();
          
          // Set the new source
          $this->Source = $Source;
          
          // Register hooks there
          $Source->addHook ('socketDisconnected', array ($this, 'httpStreamClosed'));
          
          // Raise an event for this
          $this->___raiseCallback ($Callback, true, $Private);
          $this->___callback ('eventPiped', $Source);
        }
      );
      
      return true;
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
      // Check if this source is already set
      if ($this->Source === $Source)
        return qcEvents_Promise::resolve ();
      
      // Check if there is an existing source
      if ($this->Source)
        $Promise = $this->deinitConsumer ($this->Source)->catch (function () { });
      else
        $Promise = qcEvents_Promise::resolve ();
      
      return $Promise->then (
        function () use ($Source) {
          // Reset ourself
          $this->reset ();
          
          // Set the new source
          $this->Source = $Source;
          
          // Register hooks there
          $Source->addHook ('socketDisconnected', array ($this, 'httpStreamClosed'));
          
          // Raise an event for this
          $this->___callback ('eventPipedStream', $Source);
          
          return true;
        }
      );
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      // Check if this is the right source
      if ($this->Source !== $Source)
        return qcEvents_Promise::reject ('Invalid source');
      
      // Remove our hooks
      $Source->removeHook ('socketDisconnected', array ($this, 'httpStreamClosed'));
      
      // Unset the source
      $this->Source->removeHook ('socketDisconnected', array ($this, 'httpStreamClosed'));
      $this->Source = null;
      
      // Raise an event for this
      $this->___callback ('eventUnpiped', $Source);
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ getRemoteHost
    /**
     * Retrive the hostname of the remote party
     * 
     * @access public
     * @return string
     **/
    public function getRemoteHost () {
      if ($this->Source instanceof qcEvents_Socket)
        return $this->Source->getRemoteHost ();
    }
    // }}}

    // {{{ getRemotePort
    /**
     * Retrive the port we are connected to
     * 
     * @access public
     * @return int
     **/
    public function getRemotePort () {
      if ($this->Source instanceof qcEvents_Socket)
        return $this->Source->getRemotePort ();
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
      $this->bufferHeader = array ();
      $this->bufferBody = '';
      $this->bufferBodyPos = 0;
      $this->bufferCompleteBody = false;
      $this->Header = null;
      $this->bodyEncodings = array ();
      $this->httpSetState ($this::HTTP_STATE_CONNECTING);
    }
    // }}}
    
    // {{{ httpHeaderWrite
    /**
     * Transmit a set of HTTP-Headers over the wire
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access protected
     * @return qcEvents_Promise
     **/
    protected function httpHeaderWrite (qcEvents_Stream_HTTP_Header $Header) : qcEvents_Promise {
      // Make sure we have the right source for this
      if (!$this->Source || !($this->Source instanceof qcEvents_Interface_Sink))
        return qcEvents_Promise::reject ('No suitable source to write headers');
      
      // Try to write out the status
      return $this->Source->write (strval ($Header))->then (
        function () use ($Header) {
          // Update the status
          if ($this->State == $this::HTTP_STATE_CONNECTING)
            $this->httpSetState (qcEvents_Stream_HTTP::HTTP_STATE_WAITING);
          
          // Run the callback
          $this->___callback ('httpHeadersSent', $Header);
        },
        function () {
          return $this->httpUnexpectedClose ();
        }
      );
    }
    // }}}
    
    // {{{ getPipeSource
    /**
     * Return the originator of our pipe
     * 
     * @access public
     * @return qcEvents_Interface_Source
     **/
    public function getPipeSource () {
      return $this->Source;
    }
    // }}}
    
    // {{{ httpSetState
    /**
     * Change the state of the HTTP-Parser
     * 
     * @param enum $State
     * 
     * @access private
     * @return void
     **/
    private function httpSetState ($State) {
      // Check if the state changed
      if ($State == $this->State)
        return;
      
      // Switch the state
      $oState = $this->State;
      $this->State = $State;
      
      // Fire up a callback
      $this->___callback ('httpStateChanged', $State, $oState);
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
      $this->httpSetState ($this::HTTP_STATE_FINISHED);
      
      // Fire the callback
      if ($this->isPiped ()) {
        $this->Source->removeHook ('socketDisconnected', array ($this, 'httpStreamClosed'));
        $this->Source = null;
        $this->___callback ('eventClosed');
      }
      
      $this->___callback ('httpFinished', $this->Header, $this->bufferBody);
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
      if (($this->State != $this::HTTP_STATE_FINISHED) && ($this->State != $this::HTTP_STATE_CONNECTING))
        $this->___callback ('httpFailed', $this->Header, $this->bufferBody);
      
      // Reset ourself
      $this->reset ();
    }
    // }}}
    
    // {{{ httpStreamClosed
    /** 
     * Internal Callback: Connection was closed
     * 
     * @param qcEvents_Socket $Socket
     * 
     * @access public
     * @return void
     **/
    public function httpStreamClosed (qcEvents_Socket $Socket) {
      # TODO: Sanity-Check the socket
      
      // Check if the stream closes as expected
      if (!$this->bufferCompleteBody || ($this->State != $this::HTTP_STATE_WAITING))
        return $this->httpUnexpectedClose ();
      
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
      if (!$this->Source)
        return false;
      
      return $this->Source->isWatching ($Set);
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
      if (!$this->Source)
        return false;
      
      return $this->Source->watchRead ($Set);
    }
    // }}}
    
    public function getEventBase () { }
    
    public function setEventBase (qcEvents_Base $Base) { }
    
    // {{{ read
    /**
     * Try to read from our body-buffer
     * 
     * @param int $Size (optional)
     * 
     * @access public
     * @return string
     **/
    public function read ($Size = null) {
      if ($Size === null)
        $Size = max (0, strlen ($this->bufferBody) - $this->bufferBodyPos);
      
      if ($Size < 1)
        return '';
      
      if ($this->bufferBodyKeep) {
        $Data = substr ($this->bufferBody, $this->bufferBodyPos, $Size);
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
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected function httpHeadersSend (qcEvents_Stream_HTTP_Header $Header) { }
    // }}}
    
    // {{{ httpHeadersSent
    /**
     * Callback: Headers were sent to remote peer
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected function httpHeadersSent (qcEvents_Stream_HTTP_Header $Header) { }
    // }}}
    
    // {{{ httpHeaderReady
    /**
     * Callback: The header was received completly
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected function httpHeaderReady (qcEvents_Stream_HTTP_Header $Header) { }
    // }}}
    
    // {{{ httpFinished
    /**
     * Callback: Single HTTP-Request/Response was finished
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected function httpFinished (qcEvents_Stream_HTTP_Header $Header, $Body) { }
    // }}}
    
    // {{{ httpFailed
    /**
     * Callback: Sinlge HTTP-Request/Response was not finished properly
     * 
     * @param qcEvents_Stream_HTTP_Header $Header (optional)
     * @param string $Body (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function httpFailed (qcEvents_Stream_HTTP_Header $Header = null, $Body = null) { }
    // }}}
  }

?>