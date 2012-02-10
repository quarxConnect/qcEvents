<?PHP

  /**
   * qcEvents - HTTP-Stream Response Object
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
   * HTTP-Response
   * -------------
   * Simple object to carry all informations of a HTTP-Response
   * 
   * @class qcEvents_Socket_Stream_HTTP_Response
   * @extends qcEvents_Socket_Stream_HTTP_Header
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Stream_HTTP_Response extends qcEvents_Socket_Stream_HTTP_Header {
    // The response line
    private $Protocol = null;
    private $Code = null;
    private $Message = null;
    
    // {{{ __construct
    /**
     * Setup a new HTTP-Request
     * 
     * @param string $Protocol
     * @param int $Code
     * @param string $Message (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Protocol, $Code, $Message = null) {
      $this->Protocol = $Protocol;
      $this->Code = $Code;
      $this->Message = $Message;
    }
    // }}}
  }

?>