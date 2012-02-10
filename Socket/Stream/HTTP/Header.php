<?PHP

  /**
   * qcEvents - HTTP-Stream Header Object
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
  
  /**
   * HTTP Header
   * -----------
   * Simple object to carry HTTP-Headers
   * 
   * @class qcEvents_Socket_Stream_HTTP_Header
   * @package qcEvents
   * @revision 01
   **/
  abstract class qcEvents_Socket_Stream_HTTP_Header {
    // All headers
    private $Headers = array ();
    private $Complete = false;
    
    // Payload-Buffers
    private $Encodings = null;
    private $Buffer = '';
    
    // {{{ appendHeader
    /**
     * Store a Header/Value on this request
     * 
     * @param string $Name
     * @param string $Value
     * 
     * @access public
     * @return void  
     **/
    public function appendHeader ($Name, $Value) {
      $this->Headers [strtolower ($Name)] = $Value;
    }
    // }}}
    
    // {{{ getHeaders
    /**
     * Retrive all HTTP-headers
     * 
     * @access public
     * @return array
     **/
    public function getHeaders () {
      return $this->Headers;
    }
    // }}}
    
    // {{{ getHeader
    /**
     * Retrive a header from this request
     * 
     * @param string $Name
     * 
     * @access public
     * @return string
     **/
    public function getHeader ($Name) {
      $Name = strtolower ($Name);
      
      if (!isset ($this->Headers [$Name]))
        return null;
      
      return $this->Headers [$Name];
    }
    // }}}
    
    // {{{ getContentLength
    /**
     * Retrive the length of the payload
     * 
     * @access public
     * @return int
     **/
    public function getContentLength () {
      if (!isset ($this->Headers ['content-length']))
        return null;
      
      return intval ($this->Headers ['content-length']);
    }
    // }}}
    
    // {{{ headerComplete
    /**
     * Check if the header was received completely
     * 
     * @param bool $Set (optional) Mark the header as complete
     * 
     * @access public
     * @return bool  
     **/
    public function headerComplete ($Set = null) {
      if ($Set === true)
        $this->Complete = true;
      
      return $this->Complete;
    }
    // }}}
    
    // {{{ receivePayload
    /**
     * Try to receive any payload from a buffer
     * 
     * @param string $Buffer
     * @param bool $Final (optional) Do we have to expect more data
     * 
     * @access public
     * @return bool
     **/
    public function receivePayload (&$Buffer, $Final = false) {
      // Check if we are ready to receive
      if (!$this->headerComplete ())
        return false;
      
      // Check if we were set up
      if ($this->Encodings === null) {
        if (!isset ($this->Headers ['transfer-encoding']))
          $this->Encodings = array ('identity');
        else
          $this->Encodings = explode (' ', trim ($this->Headers ['transfer-encoding']));
        
        $this->Buffer = '';
      }
      
      // Check if we can receive payload
      $Ready = false;
      
      switch ($this->Encodings [0]) {
        // Use chunked transfer
        case 'chunked':
          // Keep on reading
          while (($p = strpos ($Buffer, "\n")) !== false) {
            // Get the length from buffer
            $Length = substr ($Buffer, 0, $p);
            
            if (($p1 = strpos ($Length, ' ')) !== false)
              $Length = substr ($Length, 0, $p1);
            
            $Length = hexdec ($Length);
            
            // Check if this is the last chunk
            if ($Ready = ($Length == 0))
              break;
            
            // Check if there is enough data available
            if (strlen ($Buffer) < $Length + $p + 1)
              break;
            
            $this->Buffer .= substr ($Buffer, $p + 1, $Length);
            $Buffer = substr ($Buffer, $p + $Length + 3);
          }
          
          break;
        case 'gzip':
        case 'compress':
        case 'deflate':
        case 'identity':
          // Try to determine the length of the payload
          if (($L = $this->getContentLength ()) === null) {
            // Expect more data
            if (!$Final)
              return null;
            
            // Take the whole buffer
            $L = strlen ($Buffer);
          }
          
          // Get the payload
          $this->Buffer = substr ($Buffer, 0, $L);
          $Buffer = substr ($BUffer, $L);
          $Ready = true;
      }
      
      // Check if we are ready
      if (!$Ready)
        return null;
      
      // Check wheter to apply more transfer-encodings
      if (count ($this->Encodings) > 0) {
        # TODO
      }
      
      $this->Payload = $this->Buffer;
      $this->Buffer = null;
      
      return true;
    }
    // }}}
  }

?>