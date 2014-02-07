<?PHP

  /**
   * qcEvents - HTTP Client Request
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Socket/Stream/HTTP/Header.php');
  
  class qcEvents_Socket_Client_HTTP_Request extends qcEvents_Socket_Stream_HTTP_Header {
    private $useTLS = false;
    private $Body = null;
    
    // {{{ __construct
    /**
     * Create a new HTTP-Request
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($URL = null) {
      // Setup the header using a dummy
      parent::__construct (array ('GET / HTTP/1.0'));
      
      // Store the requested URL
      if ($URL !== null)
        $this->setURL ($URL);
    }
    // }}}
    
    // {{{ __toString
    /**
     * Convert the header into a string
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      // Let our parent create the header
      $buf = parent::__toString ();
      
      // Append the body
      if ($this->Body !== null)
        $buf .= $this->Body . "\r\n";
      
      return $buf;
    }
    // }}}
    
    // {{{ getHostname
    /**
     * Retrive the hostname for this request
     * 
     * @access public
     * @return string
     **/
    public function getHostname () {
      // Retrive the hostname from headers
      $Host = $this->getField ('Host');
      
      // Check if there is a port
      if (($p = strpos ($Host, ':')) === false)
        return $Host;
      
      // Truncate the port
      return substr ($Host, 0, $p);
    }
    // }}}
    
    // {{{ getPort
    /**
     * Retrive the port for this request
     * 
     * @access public
     * @return int
     **/
    public function getPort () {
      // Check if there is a port given on header
      if (($Host = $this->getField ('Host')) && (($p = strpos ($Host, ':')) !== false))
        return intval (substr ($Host, $p + 1));
      
      // Return port based on TLS-Status
      return ($this->useTLS () ? 443 : 80);
    }
    // }}}
    
    // {{{ useTLS
    /**
     * Check wheter to use TLS for this request
     * 
     * @access public
     * @return bool
     **/
    public function useTLS () {
      return $this->useTLS;
    }
    // }}}
    
    // {{{ setURL
    /**
     * Set a URL for this request
     * 
     * @param string $URL
     * 
     * @access public
     * @return bool
     **/
    public function setURL ($URL) {
      // Make sure we have a parsed URL
      if (!is_array ($URL) && !($URL = parse_url ($URL)))
        return false;
      
      // Store the TLS-Status
      $this->useTLS = ($URL ['scheme'] == 'https');
      
      // Forward to our parent
      return parent::setURL ($URL);
    }
    // }}}
    
    // {{{ setBody
    /**
     * Store a body for this request
     * 
     * @param string $Body
     * @param string $Mime (optional)
     * 
     * @access public
     * @return bool
     **/
    public function setBody ($Body, $Mime = null) {
      // Store the body
      $this->Body = $Body;
      
      // Set fields on header
      $this->setField ('Content-Length', strlen ($Body));
      
      if ($Mime !== null)
        $this->setField ('Content-Type', $Mime);
      elseif (!$this->hasField ('Content-Type'))
        $this->setField ('Content-Type', 'application/octet-stream');
      
      return true;
    }
    // }}}
  }

?>