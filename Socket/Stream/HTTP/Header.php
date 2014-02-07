<?PHP

  /**
   * qcEvents - HTTP-Stream Header Object
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  /**
   * HTTP Header
   * -----------
   * Simple object to carry HTTP-Headers
   * 
   * @class qcEvents_Socket_Stream_HTTP_Header
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Socket_Stream_HTTP_Header {
    /* Request-Types */
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    
    /* Type of this header */
    const TYPE_REQUEST = 0;
    const TYPE_RESPONSE = 1;
    
    private $Type = qcEvents_Socket_Stream_HTTP_Header::TYPE_REQUEST;
    
    /* Version of this header */
    private $Version = '';
    
    /* Properties for a request */
    private $Method = qcEvents_Socket_Stream_HTTP_Header::METHOD_GET;
    private $URI = '';
    
    /* Properties for a response */
    private $Code = '';
    private $Message = '';
    
    /* All header-values */
    private $Headers = array ();
    
    // {{{ __construct
    /**
     * Create a new HTTP-Header
     * 
     * @param array $Data
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Data) {
      // Check the type of this header
      $Identifier = array_shift ($Data);
      
      if (substr ($Identifier, 0, 5) == 'HTTP/') {
        $this->Type = self::TYPE_RESPONSE;
        
        $this->Version = substr ($Identifier, 0, 8);
        $this->Code = intval (substr ($Identifier, 9, 3));
        $this->Message = substr ($Identifier, 13);
      } else {
        $this->Type = self::TYPE_REQUEST;
        
        $this->Method = substr ($Identifier, 0, ($p = strpos ($Identifier, ' ')));
        $this->Version = substr ($Identifier, ($p2 = strrpos ($Identifier, ' ')) + 1);
        $this->URI = substr ($Identifier, $p + 1, $p2 - $p - 1);
      }
      
      // Parse all additional lines
      foreach ($Data as $Line) {
        // Check for colon (this should always be present)
        if (($p = strpos ($Line, ':')) === false)
          continue;
        
        // Store the header
        $Name = substr ($Line, 0, $p);
        $this->Headers [strtolower ($Name)] = array ($Name, trim (substr ($Line, $p + 1)));
      }
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
      if ($this->Type == self::TYPE_RESPONSE)
        $buf = $this->Version . ' ' . $this->Code . ' ' . $this->Message . "\r\n";
      else
        $buf = $this->Method . ' ' . $this->URI . ' ' . $this->Version . "\r\n";
      
      foreach ($this->Headers as $Header)
        $buf .= $Header [0] . ': ' . $Header [1] . "\r\n";
      
      return $buf . "\r\n";
    }
    // }}}
    
    // {{{ getType
    /**
     * Retrive the type of this header
     * 
     * @access public
     * @return enum
     **/
    public function getType () {
      return $this->Type;
    }
    // }}}
    
    // {{{ isRequest
    /**
     * Check if this header is a http-request
     * 
     * @access public
     * @return bool
     **/
    public function isRequest () {
      return ($this->Type == self::TYPE_REQUEST);
    }
    // }}}
    
    // {{{ setMethod
    /**
     * Set the method of a request-header
     * 
     * @param enum $Method
     * 
     * @access public
     * @return bool
     **/
    public function setMethod ($Method) {
      switch ($Method) {
        case self::METHOD_GET:
        case self::METHOD_POST:
          $this->Method = $Method;
          
          return true;
      }
      
      return false;
    }
    // }}}
    
    // {{{ setURL
    /**
     * Setup this header by a given URL
     * 
     * @param mixed $URL
     * 
     * @access public
     * @return bool
     **/
    public function setURL ($URL) {
      // Make sure we have a parsed URL
      if (!is_array ($URL) && !($URL = parse_url ($URL)))
        return false;
      
      // Store the URI
      $this->URI = $URL ['path'] . (isset ($URL ['query']) && ($URL ['query'] !== null) ? '?' . $URL ['query'] : '');
      
      // Setup host-entry
      if (!isset ($URL ['host']) || ($URL ['host'] === null)) {
        $this->unsetField ('Host');
        $this->Version = 'HTTP/1.0';
      } else {
        $this->setField ('Host', $URL ['host'] . (isset ($URL ['port']) && ($URL ['port'] !== null) ? ':' . $URL ['port'] : ''));
        $this->Version = 'HTTP/1.1';
      }
      
      // Set credentials (if applicable)
      if (isset ($URL ['user']))
        $this->setCredentials ($URL ['user'], $URL ['pass']);
      
      return true;
    }
    // }}}
    
    // {{{ setCredentials
    /**
     * Store HTTP-Credentials
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return bool
     **/
    public function setCredentials ($Username, $Password) {
      return $this->setField ('Authenticate', 'Basic ' . base64_encode ($Username . ':' . $Password));
    }
    // }}}
    
    // {{{ hasField
    /**
     * Check if a field is present on this header
     * 
     * @param string $Field
     * 
     * @access public
     * @return bool
     **/
    public function hasField ($Field) {
      return isset ($this->Headers [strtolower ($Field)]);
    }
    // }}}
    
    // {{{ getField
    /**
     * Retrive a field from this header
     * 
     * @param string $Field
     * 
     * @access public
     * @return string
     **/
    public function getField ($Field) {
      // Retrive the key for that field
      $Key = strtolower ($Field);
      
      // Check if the field is present
      if (isset ($this->Headers [$Key]))
        return $this->Headers [$Key][1];
      
      return null;
    }
    // }}}
    
    // {{{ setField
    /**
     * Set the content of a field on this header
     * 
     * @param string $Name
     * @param string $Value
     * 
     * @access public
     * @return bool
     **/
    public function setField ($Name, $Value) {
      // Retrive the key for that field
      $Key = strtolower ($Name);
      
      // Store the value
      $this->Headers [$Key] = array ($Name, $Value);
      
      return true;
    }
    // }}}
    
    // {{{ unsetField
    /**
     * Remove a field from this header
     * 
     * @param string $Name
     * 
     * @access public
     * @return void
     **/
    public function unsetField ($Name) {
      unset ($this->Headers [strtolower ($Name)]);
    }
    // }}}
    
    // {{{ hasBody
    /**
     * Check if a body is expected
     * 
     * @access public
     * @return bool
     **/
    public function hasBody () {
      // Check rules as of RFC 1945 7.2 / RFC 2616 4.3
      if ($this->Type == self::TYPE_REQUEST)
        return ($this->hasField ('content-length') || $this->hasField ('transfer-encoding'));
      
      // Decide depending on Status-Code
      # TODO: This does not honor Responses to HEAD-Requests (as we do not have this information here)
      return (($this->Code > 199) && ($this->Code != 204) && ($this->Code != 304));
    }
    // }}}
  }

?>