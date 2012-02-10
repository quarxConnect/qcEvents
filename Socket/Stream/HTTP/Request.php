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
  
  require_once ('qcEvents/Socket/Stream/HTTP/Header.php');
  
  /**
   * HTTP-Request
   * ------------
   * Simple object to carry all Informations from a HTTP-Request
   * 
   * @class qcEvents_Socket_Stream_HTTP_Request
   * @extends qcEvents_Socket_Stream_HTTP_Header
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Stream_HTTP_Request extends qcEvents_Socket_Stream_HTTP_Header {
    // Request-Methods
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_HEAD = 'HEAD';
    const METHOD_OPTIONS= 'OPTIONS';
    
    // The full request-line
    private $Method = null;
    private $URI = null;
    private $Protocol = null;
    
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
    
    // {{{ expectPayload
    /**
     * Check if there is payload expected on this request
     * 
     * @access public
     * @return bool
     **/
    public function expectPayload () {
      return ($this->getHeader ('content-length') !== null);
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
      return $this->getContentLength ();
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
  }

?>