<?PHP

  /**
   * qcEvents - HTTP-Stream Request Object
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
   * HTTP-Request
   * ------------
   * Simple object to carry all Informations from a HTTP-Request
   * 
   * @class qcEvents_Socket_Stream_HTTP_Request
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Stream_HTTP_Request {
    // Request-Methods
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_HEAD = 'HEAD';
    const METHOD_OPTIONS= 'OPTIONS';
    
    // The full request-line
    private $Method = null;
    private $URI = null;
    private $Protocol = null;
    private $Complete = false;
    
    // Additional headers
    private $Headers = array ();
    
    // Payload of this request
    private $Payload = null;
    
    // {{{ __construct
    /**
     * Setup a new HTTP-Request
     * 
     * @param string $Method
     * @param string $URI
     * @param string $Protocol
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Method, $URI, $Protocol) {
      switch ($Method) {
        case self::METHOD_GET:
        case self::METHOD_POST:
        case self::METHOD_HEAD:
        case self::METHOD_OPTIONS:
          $this->Method = $Method;
      }
      
      $this->URI = $URI;
      $this->Protocol = $Protocol;
    }
    // }}}
    
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
    
    // {{{ getMethod
    /**
     * Retrive the HTTP-Method of this Request
     * 
     * @access public
     * @return enum
     **/
    public function getMethod () {
      return $this->Method;
    }
    // }}}
    
    // {{{ getURI
    /**
     * Retrive the requested URI
     * 
     * @access public
     * @return string
     **/
    public function getURI () {
      return $this->URI;
    }
    // }}}
    
    // {{{ getProtocol
    /**
     * Retrive the Protocol from the request
     * 
     * @access public
     * @return string
     **/
    public function getProtocol () {
      return $this->Protocol;
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
    
    // {{{ expectPayload
    /**
     * Check if there is payload expected on this request
     * 
     * @access public
     * @return bool
     **/
    public function expectPayload () {
      return isset ($this->Headers ['content-length']);
    }
    // }}}
    
    // {{{ getPayloadLength
    /**
     * Retrive the length of the expected payload
     * 
     * @access public
     * @return int
     **/
    public function getPayloadLength () {
      if (isset ($this->Headers ['content-length']))
        return intval ($this->Headers ['content-length']);
      
      return 0;
    }
    // }}}
    
    // {{{ setPayload
    /**
     * Store the payload
     * 
     * @param string $Data
     * 
     * @access public
     * @return void
     **/
    public function setPayload ($Data) {
      $this->Payload = $Data;
    }
    // }}}
    
    // {{{ getPayload
    /**
     * Retrive the payload of this request
     * 
     * @access public
     * @return string
     **/
    public function getPayload () {
      return $this->Payload;
    }
    // }}}
    
    // {{{ hasPayload
    /**
     * Check if we have payload assigned
     * 
     * @access public
     * @return bool
     **/ 
    public function hasPayload () {
      return ($this->Payload !== null) && (strlen ($this->Payload) > 0);
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
  }

?>