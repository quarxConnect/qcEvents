<?php

  /**
   * quarxConnect Events - Errors for JSON-RPC-Client
   * Copyright (C) 2018-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Client\JSONRPC;
  
  class Error extends \Exception {
    const CODE_PARSE_ERROR = -32700;
    
    const CODE_RESPONSE_SERVER_ERROR = -32000;
    const CODE_RESPONSE_INVALID_CONTENT = -32001;
    const CODE_RESPONSE_INVALID_AUTH = -32099;
    
    const CODE_RESPONSE_INVALID_REQUEST = -32600;
    const CODE_RESPONSE_METHOD_NOT_FOUND = -32601;
    const CODE_RESPONSE_INVALID_PARAMS = -32602;
    const CODE_RESPONSE_INTERNAL_ERROR = -32603;
    const CODE_RESPONSE_MISSING_PARAMS = -33601;
    
    private $data = null;
    
    function __construct ($code, $message = null, $data = null) {
      $this->data = $data;
      
      return parent::__construct ($message, $code);
    }
  }

?>