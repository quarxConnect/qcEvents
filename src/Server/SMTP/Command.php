<?php

  /**
   * qcEvents - Command for SMTP-Server
   * Copyright (C) 2012-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events\Server\SMTP;
  use \quarxConnect\Events;
  
  class Command {
    /* The SMTP-Server we are running on */
    private $smtpServer = null;
    
    /* The actual Command we are handling */
    private $smtpCommand = '';
    
    /* Parameter for this command */
    private $commandParameter = null;
    
    /* Our response-code */
    private $smtpCode = null;
    
    /* Message for the response */
    private $responseMessage = null;
    
    /* Promise for intermediate */
    private $intermediatePromise = null;
    
    /* Processor for intermediate data */
    private $intermediateCallback = null;
    
    /* Buffered intermediate data */
    private $intermediateData = null;
    
    // {{{ __construct
    /**
     * Create a new Command-Object
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Server\SMTP $smtpServer, string $smtpCommand, string $commandParameter = null) {
      $this->smtpServer = $smtpServer;
      $this->smtpCommand = $smtpCommand;
      $this->commandParameter = $commandParameter;
    }
    // }}}
    
    // {{{ __toString
    /**
     * Cast this object into a string
     * 
     * @access friendly
     * @return string
     **/
    function __toString () : string {
      return $this->smtpCommand;
    }
    // }}}
    
    // {{{ hasParameter
    /**
     * Check if this command has parameter assigned
     * 
     * @access public
     * @return bool
     **/
    public function hasParameter () : bool {
      return (($this->commandParameter !== null) && (strlen ($this->commandParameter) > 0));
    }
    // }}}
    
    // {{{ getParameter
    /**
     * Retrive the parameter for this command
     * 
     * @access public
     * @return string
     **/
    public function getParameter () : ?string {
      return $this->commandParameter;
    }
    // }}}
    
    // {{{ setResponse
    /**
     * Store the response for this command
     *
     * @param int $smtpCode The response-code
     * @param string|array $responseMessage (optional) Message for the response, may be multi-line
     * 
     * @access public
     * @return Events\Promise
     **/
    public function setResponse (int $smtpCode, $responseMessage = null) : Events\Promise {
      if (($smtpCode >= 300) && ($smtpCode < 400))
        throw new \Error ('Use setIntermediateResponse() for intermediate responses');
      
      if ($this->intermediatePromise)
        $this->intermediatePromise-reject ('setResponse() before callback finished');
      
      $this->smtpCode = $smtpCode;
      $this->responseMessage = $responseMessage;
      $this->intermediatePromise = null;
      $this->intermediateCallback = null;
      $this->intermediateData = null;
      
      return $this->smtpServer->smtpCommandReady ($this);
    }
    // }}}
    
    // {{{ setIntermediateResponse
    /**
     * Set intermediate response and wait for additional data
     * 
     * @param int $smtpCode
     * @param string|array $responseMessage (optional) Message for the response, may be multi-line
     * @param callable $intermedateCallback (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function setIntermediateResponse (int $smtpCode, $responseMessage = null, callable $intermediateCallback = null) : Events\Promise {
      $this->smtpCode = $smtpCode;
      $this->responseMessage = $responseMessage;
      $this->intermediatePromise = new Events\Promise\Deferred ();
      $this->intermediateCallback = $intermediateCallback;
      $this->intermediateData = '';
      
      $this->smtpServer->smtpCommandReady ($this);
      
      return $this->intermediatePromise->getPromise ();
    }
    // }}}
    
    // {{{ getCode
    /**
     * Retrive the SMTP-Code of a pending response
     * 
     * @access public
     * @return int
     **/
    public function getCode () : ?int {
      return $this->smtpCode;
    }
    // }}}
    
    // {{{ getMessage
    /**
     * Retrive a message for a pending response
     * 
     * @access public
     * @return string|array|null
     **/
    public function getMessage () {
      return $this->responseMessage;
    }
    // }}}
    
    // {{{ receiveLine
    /**
     * Receive line for intermediate data
     * 
     * @param string $smtpLine
     * 
     * @access public
     + @return void
     **/
    public function receiveLine (string $smtpLine) : void {
      if (!$this->intermediatePromise)
        throw new \Exception ('Intermediate data when not requested');
      
      try {
        if (!$this->intermediateCallback) {
          $this->intermediateData = $smtpLine . "\r\n";
          $intermediateResult = true;
        } else
          $intermediateResult = call_user_func ($this->intermediateCallback, $smtpLine);
      } catch (\Throwable $error) {
        $intermediateResult = $error;
      }
      
      if (is_scalar ($intermediateResult) && !is_bool ($intermediateResult)) {
        $this->intermediateData .= $intermediateResult . "\r\n";
        
        return;
      } elseif ($intermediateResult === false)
        $intermediateResult = new \Exception ('Intermediate callback returned false');
      
      $intermediatePromise = $this->intermediatePromise;
      $intermediateData = $this->intermediateData;
      
      $this->intermediatePromise = null;
      $this->intermediateData = null;
      $this->intermediateCallback = null;
      
      if ($intermediateResult === true)
        $intermediatePromise->resolve ($intermediateData);
      else
        $intermediatePromise->reject ($intermediateData);
    }
    // }}}
  }
