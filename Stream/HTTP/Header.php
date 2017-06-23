<?PHP

  /**
   * qcEvents - HTTP Header Object
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
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
   * Generic HTTP Header
   * -------------------
   * Simple object to carry HTTP-like Headers
   * 
   * @class qcEvents_Stream_HTTP_Header
   * @package qcEvents
   * @revision 03
   **/
  class qcEvents_Stream_HTTP_Header {
    /* Type of this header */
    const TYPE_REQUEST = 0;
    const TYPE_RESPONSE = 1;
    
    private $Type = qcEvents_Stream_HTTP_Header::TYPE_REQUEST;
    
    /* Version of this header */
    protected static $protoName = 'HTTP';
    private $Version = '';
    
    /* Properties for a request */
    private $Method = '';
    private $URI = '';
    
    protected static $Methods = array (
      'GET',
      'POST',
      'PUT',
      'PATCH',
      'DELETE',
      'HEAD',
      'OPTIONS',
      
      // WebDAV-Stuff
      'REPORT',
      'PROPFIND',
    );
    
    /* Properties for a response */
    private $Code = null;
    private $Message = '';
    
    /* All header-values */
    private $Headers = array ();
    
    // {{{ __construct
    /**
     * Create a new generic HTTP-Header
     * 
     * @param array $Data
     * 
     * @access friendly
     * @return void
     **/
    function __construct (array $Data) {
      // Check the type of this header
      $Identifier = array_shift ($Data);
      
      if (substr ($Identifier, 0, strlen ($this::$protoName) + 1) == $this::$protoName . '/') {
        $this->Type = self::TYPE_RESPONSE;
        
        $this->Version = substr ($Identifier, 0, ($p = strpos ($Identifier, ' ')));
        $this->Code = intval (substr ($Identifier, $p + 1, 3));
        $this->Message = substr ($Identifier, $p + 5);
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
        $lName = strtolower ($Name);
        
        if (isset ($this->Headers [$lName]))
          $this->Headers [$lName][] = array ($Name, trim (substr ($Line, $p + 1)));
        else
          $this->Headers [$lName] = array (array ($Name, trim (substr ($Line, $p + 1))));
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
        foreach ($Header as $Entity)
          if (is_array ($Entity [1]))
            foreach ($Entity [1] as $Value)
              $buf .= $Entity [0] . ': ' . $Value . "\r\n";
          else
            $buf .= $Entity [0] . ': ' . $Entity [1] . "\r\n";
      
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
    
    // {{{ getMethod
    /**
     * Retrive the HTTP-Method if this is a request-header
     * 
     * @access public
     * @return enum
     **/
    public function getMethod () {
      return $this->Method;
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
      if (!in_array ($Method, $this::$Methods))
        return false;
      
      $this->Method = $Method;
      
      return true;
    }
    // }}}
    
    // {{{ getStatus
    /**
     * Retrive the status-code from a http-response
     * 
     * @access public
     * @return int
     **/
    public function getStatus () {
      return $this->Code;
    }
    // }}}
    
    // {{{ setStatus
    /**
     * Set a new status code for a http-response
     * 
     * @param int $Code
     * 
     * @access public
     * @return void
     **/
    public function setStatus ($Code) {
      $this->Code = (int)$Code;
    }
    // }}}
    
    // {{{ isError
    /**
     * Check if this header indicates an error-status
     * 
     * @access public
     * @return bool
     **/
    public function isError () {
      return ($this->Code >= 400);
    }
    // }}}
    
    // {{{ getMessage
    /**
     * Retrive the message that was associated with the status-code
     * 
     * @access public
     * @return string
     **/
    public function getMessage () {
      return $this->Message;
    }
    // }}}
    
    // {{{ setMessage
    /**
     * Store a message associated with the status-code
     * 
     * @param string $Message
     * 
     * @access public
     * @return void
     **/
    public function setMessage ($Message) {
      $this->Message = $Message;
    }
    // }}}
    
    // {{{ getVersion
    /**
     * Retrive the version of this header
     * 
     * @param bool $asString (optional)
     * 
     * @access public
     * @return mixed
     **/
    public function getVersion ($asString = false) {
      if ($asString)
        return substr ($this->Version, strlen ($this::$protoName) + 1);
      
      return floatval (substr ($this->Version, strlen ($this::$protoName) + 1));
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
    
    // {{{ getURI
    /**
     * Retrive the request-uri
     * 
     * @access public
     * @return string
     **/
    public function getURI () {
      return $this->URI;
    }
    // }}}
    
    // {{{ getURL
    /**
     * Retrive the URL from this header (only if it is a request)
     * 
     * @access public
     * @return string
     **/
    public function getURL () {
      # TODO: This is HTTP
      return 'http://' . $this->getField ('Host') . $this->URI;
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
      
      # TODO: Add support for full URIs
      
      // Store the URI
      $this->URI = $URL ['path'] . (isset ($URL ['query']) && ($URL ['query'] !== null) ? '?' . $URL ['query'] : '');
      
      // Setup host-entry
      if (!isset ($URL ['host']) || ($URL ['host'] === null)) {
        $this->unsetField ('Host');
        $this->Version = $this::$protoName . '/1.0'; # TODO: This is HTTP
      } else {
        $this->setField ('Host', $URL ['host'] . (isset ($URL ['port']) && ($URL ['port'] !== null) ? ':' . $URL ['port'] : ''));
        $this->Version = $this::$protoName . '/1.1'; # TODO: This is HTTP
      }
      
      // Set credentials (if applicable)
      if (isset ($URL ['user']))
        $this->setCredentials ($URL ['user'], (isset ($URL ['pass']) ? $URL ['pass'] : ''));
      
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
      return $this->setField ('Authorization', 'Basic ' . base64_encode ($Username . ':' . $Password));
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
     * @param bool $allowMulti (optional)
     * 
     * @access public
     * @return string
     **/
    public function getField ($Field, $allowMulti = false) {
      // Retrive the key for that field
      $Key = strtolower ($Field);
      
      // Check if the field is present
      if (!isset ($this->Headers [$Key]))
        return null;
      
      // Collect all values
      $Values = array ();
      
      foreach ($this->Headers [$Key] as $Header) {
        if (is_array ($Header [1]))
          $Values = array_merge ($Values, $Header [1]);
        else
          $Values [] = $Header [1];
        
        if (!$allowMulti)
          return array_shift ($Values);
      }
      
      if ($allowMulti)
        return $Values;
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
    public function setField ($Name, $Value, $Replace = true) {
      // Retrive the key for that field
      $Key = strtolower ($Name);
      
      // Store the value
      if ($Replace || !isset ($this->Headers [$Key]))
        $this->Headers [$Key] = array (array ($Name, $Value));
      else
        $this->Headers [$Key][] = array ($Name, $Value);
      
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
    
    // {{{ getFields
    /**
     * Retrive all fields from this header
     * 
     * @access public
     * @return array
     **/
    public function getFields () {
      $Result = array ();
      
      foreach ($this->Headers as $Entities)
        foreach ($Entities as $Entity)
          $Result [$Entity [0]] = $Entity [1];
      
      return $Result;
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