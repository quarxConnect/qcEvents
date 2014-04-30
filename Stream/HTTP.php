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
  
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Stream/HTTP/Header.php');
  
  /**
   * HTTP-Stream
   * -----------
   * Abstract HTTP-Stream-Handler (common functions for both - client and server)
   * 
   * @class qcEvents_Stream_HTTP
   * @extends qcEvents_Socket
   * @package qcEvents
   * @revision 02
   **/
  abstract class qcEvents_Stream_HTTP extends qcEvents_Socket {
    /* HTTP States */
    const HTTP_STATE_WAITING = 0;
    const HTTP_STATE_HEADER = 1;
    const HTTP_STATE_BODY = 2;
    const HTTP_STATE_FINISHED = 3;
    
    /* Our current state */
    private $State = qcEvents_Stream_HTTP::HTTP_STATE_WAITING;
    
    /* Don't try to read any body-data */
    private $bodySuppressed = false;
    
    /* Our Read-Buffer */
    private $bufferRead = '';
    
    /* Buffer for header-lines */
    private $bufferHeader = array ();
    
    /* Buffer for our body */
    private $bufferBody = '';
    
    /* Use the whole data as body */
    private $bufferCompleteBody = false;
    
    /* Encoding of body */
    private $bodyEncodings = array ();
    
    // {{{ __construct   
    /**
     * Create a new HTTP-Stream
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Inherit to our parent
      call_user_func_array ('parent::__construct', func_get_args ());
        
      // Register hooks
      $this->addHook ('socketDisconnected', array ($this, 'httpStreamClosed'));
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
      $this->State = qcEvents_Stream_HTTP::HTTP_STATE_WAITING;
      $this->bodySuppressed = false;
      $this->bufferRead = '';
      $this->bufferHeader = array ();
      $this->bufferBody = '';
      $this->bufferCompleteBody = false;
      $this->bodyEncodings = array ();
    }
    // }}}
    
    // {{{ httpHeaderWrite
    /**
     * Transmit a set of HTTP-Headers over the wire
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected function httpHeaderWrite (qcEvents_Stream_HTTP_Header $Header) {
      $this->write (strval ($Header));
    }
    // }}}
    
    // {{{ socketReceive
    /**
     * Internal Callback: Data was received over the wire
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function socketReceive ($Data) {
      // Check if we are just waiting for the connection to be closed
      if ($this->bufferCompleteBody) {
        $this->bufferBody .= $Data;
        
        return;
      }
      
      // Append data to our internal buffer
      $this->bufferRead .= $Data;
      
      // Leave waiting-state if neccessary
      if ($this->State == self::HTTP_STATE_WAITING)
        $this->httpSetState (self::HTTP_STATE_HEADER);
      
      // Read the header
      if ($this->State == self::HTTP_STATE_HEADER)
        while (($p = strpos ($this->bufferRead, "\r\n")) !== false) {
          // Retrive the current line
          $Line = substr ($this->bufferRead, 0, $p);
          $this->bufferRead = substr ($this->bufferRead, $p + 2);
          
          // Check if header is finished
          if (strlen ($Line) == 0) {
            // Create the header
            $this->Header = new qcEvents_Stream_HTTP_Header ($this->bufferHeader);
            $this->bufferHeader = array ();
            
            // Fire a callback for this event
            $this->___callback ('httpHeaderReady', $this->Header);
            
            // Switch states
            if ($this->bodySuppressed || !$this->Header->hasBody ()) {
              $this->httpSetState (self::HTTP_STATE_FINISHED);
              
              return $this->___callback ('httpFinished', $this->Header, null);
            }
            
            $this->httpSetState (self::HTTP_STATE_BODY);
            
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
      if ($this->State == self::HTTP_STATE_BODY)
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
              $this->bufferBody .= substr ($this->bufferRead, $p + 2, $Length);
            
            $this->bufferRead = substr ($this->bufferRead, $p + $Length + 4);
            
            if ($Length == 0)
              return $this->httpBodyReceived ();
          }
        
        // Check if there is a content-length given
        } elseif (($Length = $this->Header->getField ('content-length')) !== null) {
          // Check if the buffer is big enough
          if (strlen ($this->bufferRead) < $Length)
            return;
          
          // Copy the buffer to local body
          $this->bufferBody = substr ($this->bufferRead, 0, $Length);
          $this->bufferRead = substr ($this->bufferRead, $Length);
          
          return $this->httpBodyReceived ();
        
        // Wait until connection is closed
        } else {
          $this->bufferCompleteBody = true;
          $this->bufferBody = $this->bufferRead;
          $this->bufferRead = '';
        }
    }
    // }}}
    
    // {{{ socketReadable
    /**
     * Internal Callback: Data is available on local buffer
     * 
     * @access protected
     * @return void
     **/
    protected function socketReadable () {
      if ($this->State != self::HTTP_STATE_FINISHED)
        return $this->socketReceive ($this->readBuffer ());
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
      // Switch the state
      $oState = $this->State;
      $this->State = $State;
      
      // Fire up a callback
      $this->___callback ('httpStateChanged', $State, $oState);
    }
    // }}}
    
    // {{{ httpBodyReceived
    /**
     * Body was received completly
     * 
     * @access private
     * @return void
     **/
    private function httpBodyReceived () {
      // Sanity-Check encodings
      if (count ($this->bodyEncodings) > 1)
        trigger_error ('More than one encoding found, this is unimplemented');
      
      // Change our state
      $this->httpSetState (self::HTTP_STATE_FINISHED);
      
      // Fire the callback
      $this->___callback ('httpFinished', $this->Header, $this->bufferBody);
      
      // Release the buffer
      $this->bufferBody = '';
    }
    // }}}
    
    // {{{ httpStreamClosed
    /** 
     * Hook: Connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected function httpStreamClosed () {
      // Check wheter to use this function
      if (!$this->bufferCompleteBody)
        return;
      
      $this->httpBodyReceived ();
    }
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
    
    // {{{ httpHeaderReady
    /**
     * Callback: The header was received completly
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected function httpHeaderReady ($Header) { }
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
  }

?>