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
  
  require_once ('qcEvents/Stream/HTTP/Header.php');
  
  class qcEvents_Socket_Client_HTTP_Request extends qcEvents_Stream_HTTP_Header {
    // Use TLS for this request
    private $useTLS = false;
    
    // Values to upload with this request
    private $Values = array ();
    
    // Files to upload with this request
    private $Files = array ();
    
    // User-defined body for this request
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
      parent::__construct (array ('GET / HTTP/1.0', 'Connection: keep-alive'));
      
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
    public function __toString () {
      return $this->toString (false);
    }
    // }}}
    
    /**
     * Convert the header into a string
     * 
     * @param bool $Human (optional) Generate human readable output
     * 
     * @access public
     * @return string
     **/
    public function toString ($Human = false) {
      // Make sure that files and values are transfered properly
      $haveFiles = (count ($this->Files) > 0);
      $haveValues = (count ($this->Values) > 0);
      $Body = null;
      
      if ($haveFiles || $haveValues) {
        // Generate a boundary for formdata
        $Boundary = '----qcEvents-' . md5 (time ());
        
        // Always transfer files and values using POST
        $this->setMethod (self::METHOD_POST);
        $this->setField ('Content-Type', 'multipart/form-data; boundary="' . $Boundary . '"');
        
        // Generate body of request
        $Body = '';
        
        foreach ($this->Values as $Name=>$Value)
           $Body .=
             '--' . $Boundary . "\r\n" .
             'Content-Disposition: form-data; name="' . $Name . '"' . "\r\n" .
             ($Value [1] !== null ? 'Content-Type: ' . $Value [1] . "\r\n" : '') .
             'Content-Transfer-Encoding: binary' . "\r\n\r\n" .
             $Value [0] . "\r\n";
        
        foreach ($this->Files as $Name=>$Fileinfo)
          $Body .=
            '--' . $Boundary . "\r\n" .
            'Content-Disposition: form-data; name="' . $Name . '"; filename="' . $Fileinfo [1] . '"' . "\r\n" .
            'Content-Type: ' . $Fileinfo [2] . "\r\n" .
            'Content-Transfer-Encoding: binary' . "\r\n\r\n" .
            ($Human ? '[' . filesize ($Fileinfo [0]) . ' binary octets]' : file_get_contents ($Fileinfo [0])) . "\r\n";
        
        $Body .= '--' . $Boundary . '--' . "\r\n";
        
        // Store length of body
        $this->setField ('Content-Length', strlen ($Body));
        
      // Use a user-defined body
      } elseif ($this->Body !== null) {
        $this->setMethod (self::METHOD_POST);
        
        $Body =& $this->Body;
      
      // Make sure we are not in POST-Mode if no body is present
      } elseif ($this->getMethod () == self::METHOD_POST)
        $this->setMethod (self::METHOD_GET);
      
      // Let our parent create the header
      $buf = parent::__toString ();
      
      // Append the body
      if ($Body !== null)
        $buf .= $Body . "\r\n";
      
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
      if ($Body === null) {
        $this->unsetField ('Content-Length');
        $this->unsetField ('Content-Type');
        
        return;
      }
      
      $this->setField ('Content-Length', strlen ($Body));
      
      if ($Mime !== null)
        $this->setField ('Content-Type', $Mime);
      elseif (!$this->hasField ('Content-Type'))
        $this->setField ('Content-Type', 'application/octet-stream');
      
      return true;
    }
    // }}}
    
    // {{{ attachValue
    /**
     * Attach a simple value to this request
     * 
     * @param string $Name
     * @param string $Value
     * @param string $Mime (optional) MIME-Type to send to the server
     * 
     * @access public
     * @return bool
     **/
    public function attachValue ($Name, $Value, $Mime = null) {
      // Check if there is already a file by this name
      if (isset ($this->Files [$Name]))
        return false;
      
      // Attach the value
      $this->Values [$Name] = array ($Value, $Mime);
      
      return true;
    }
    // }}}
    
    // {{{ attachFile
    /**
     * Attach a file to this request
     * 
     * @param string $Path Path to file
     * @param string $Name (optional) Name of the file
     * @param string $Filename (optional) Filename to send to the server
     * @param string $Mime (optional) MIME-Type to send to the server
     * 
     * @access public
     * @return bool
     **/
    public function attachFile ($Path, $Name = null, $Filename = null, $Mime = null) {
      // Make sure the file exists
      if (!is_file ($Path))
        return false;
      
      // Bail out a warning if there is a body
      if ($this->Body !== null)
        trigger_error ('Uploading a file will override any user-defined body of the request', E_USER_WARNING);
      
      // Retrive the basename of the file
      $Basename = basename ($Path);
      
      // Check which name to use
      if ($Name === null)
        $Name = $Basename;
      
      if ($Filename === null)
        $Filename = $Basename;
      
      // Check if there is a mime-type given
      if ($Mime === null)
        # TODO: Auto-detect?
        $Mime = 'application/octet-stream';
      
      // Enqueue the file
      $this->Files [$Name] = array ($Path, $Filename, $Mime);
      unset ($this->Values [$Name]);
      
      return true;
    }
    // }}}
  }

?>