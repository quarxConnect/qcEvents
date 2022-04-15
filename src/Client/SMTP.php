<?php

  /**
   * qcEvents - Asyncronous SMTP Client
   * Copyright (C) 2017-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Client;
  use \quarxConnect\Events;
  
  /**
   * SMTP-Client
   * ----------- 
   * Simple SMTP-Client-Implementation (RFC 5321)
   * 
   * @class qSMTP
   * @extends Events\Hookable
   * @package quarxconnect/events
   * @revision 02
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   **/
  class SMTP extends Events\Hookable {
    /* Socket-Pool */
    private $socketPool = null;
    
    /* Hostname or IP to use as SMTP-Server */
    private $remoteHost = '';
    
    /* Port to connect to */
    private $remotePort = 25;
    
    /* Enable/Disable TLS-Encryption (NULL: Auto-detect) */
    private $remoteTLS = null;
    
    /* Username for authentication */
    private $remoteUser = null;
    
    /* Password for authentication */
    private $remotePassword = null;
    
    /* Default originator for e-mails */
    private $defaultOriginator = null;
    
    // {{{ __construct
    /**
     * Create a new SMTP-Client
     * 
     * @param Events\Base $eventBase
     * @param mixed $remoteHost (optional)
     * @param int $remotePort (optional)
     * @param bool $remoteTLS (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase, $remoteHost = null, int $remotePort = null, bool $remoteTLS = null) {
      // Create new socket-pool
      $this->socketPool = new Events\Socket\Pool ($eventBase);
      
      // Setup client-sockets correctly
      $this->socketPool->addHook (
        'socketConnected',
        function (Events\Socket\Pool $socketPool, Events\Socket $clientSocket) {
          // Create a new SMTP-Client
          $smtpStream = new Events\Stream\SMTP\Client ();
          
          // Connect ourself with that socket
          return $clientSocket->pipeStream ($smtpStream, true)->then (
            function () use ($clientSocket, $smtpStream) {
              // Check wheter to enable TLS
              if (
                ($this->remoteTLS === true) &&
                !$smtpStream->hasFeature ('STARTTLS') &&
                !$clientSocket->isTLS ()
              )
                return $this->socketPool->releaseConnection ($clientSocket);
              
              // Try to enable TLS
              if (
                ($this->remoteTLS !== false) &&
                $smtpStream->hasFeature ('STARTTLS') &&
                !$clientSocket->isTLS ()
              )
                $tlsPromise = $smtpStream->startTLS ();
              else
                $tlsPromise = Events\Promise::resolve ();
              
              return $tlsPromise->then (
                function () use ($clientSocket, $smtpStream) {
                  // Check wheter to perform authentication
                  if ($this->remoteUser === null)
                    return $this->socketPool->enableSocket ($clientSocket, $smtpStream);
                  
                  // Try to authenticate
                  return $smtpStream->authenticate (
                    $this->remoteUser,
                    $this->remotePassword
                  )->then (
                    function () use ($clientSocket, $smtpStream) {
                      $this->socketPool->enableSocket ($clientSocket, $smtpStream);
                    },
                  );
                }
              );
            }
          )->catch (
            function () use ($clientSocket) {
              // Release the socket
              $this->socketPool->releaseConnection ($clientSocket);
              
              // Forward the error
              throw new Events\Promise\Solution (func_get_args ());
            }
          );
        }
      );
      
      // Setup ourself
      if ($remoteHost !== null)
        $this->setRemoteHost ($remoteHost, $remotePort, $remoteTLS);
    }
    // }}}
    
    // {{{ setRemoteHost
    /**
     * Store IP/Hostname and port to connect to
     * 
     * @param mixed $remoteHost
     * @param int $remotePort (optional)
     * @param bool $enableTLS (optional)
     * 
     * @access public
     * @return void
     **/
    public function setRemoteHost ($remoteHost, int $remotePort = null, bool $enableTLS = null) : bool {
      $this->remoteHost = $remoteHost;
      $this->remotePort = $remotePort;
      $this->remoteTLS = $enableTLS;
      
      if ($this->remoteTLS === false)
        trigger_error ('Forcing TLS-Encyption OFF is NOT RECOMMENDED!', \E_USER_WARNING);
    }
    // }}}
    
    // {{{ setCredentials
    /**
     * Store username/password for authentication
     * 
     * @param string $userName
     * @param string $userPassword
     * 
     * @access public
     * @return void
     **/
    public function setCredentials (string $userName, string $userPassword) : void {
      $this->remoteUser = $userName;
      $this->remotePassword = $userPassword;
    }
    // }}}
    
    // {{{ getOriginator
    /**
     * Retrive an originator for this e-mail-client
     * 
     * @access public
     * @return string
     **/
    public function getOriginator () : string {
      if ($this->defaultOriginator)
        return $this->defaultOriginator;
      
      if (function_exists ('gethostname'))
        $originDomain = gethostname ();
      else
        $originDomain = 'smtpc.quarxconnect.org';
      
      return 'smtpc@' . $originDomain;
    }
    // }}}
    
    // {{{ setOriginator
    /**
     * Set the default originator for this e-mail-client
     * 
     * @param string $mailOriginator
     * 
     * @access public
     * @return void
     **/
    public function setOriginator (string $mailOriginator) : void {
      $this->defaultOriginator = $mailOriginator;
    }
    // }}}
    
    // {{{ sendMail
    /**
     * Compose and try to send an e-mail
     * 
     * @param array $mailReceivers List of receivers for this e-mail
     * @param string $mailSubject Subject of the e-mail (may be overriden by additionalHeaders)
     * @param string $mailBody Body of the e-mail
     * @param array $additionalHeaders (optional) Additional headers for the e-mail
     * 
     * @access public
     * @return Events\Promise
     **/
    public function sendMail (array $mailReceivers, string $mailSubject, string $mailBody, array $additionalHeaders = null) : Events\Promise {
      // Preprocess receivers
      $mailReceiversNew = [ ];
      
      foreach ($mailReceivers as $receiverIndex=>$mailReceiver) {
        # mail@exmaple.com
        # <mail@exmaple.com>
        # Displayname <mail@example.com>
        
        # mailbox = [display-name] angle-addr
        # angle-addr = [CFWS] "<" addr-spec ">" [CFWS]
        # display-name = 1*word / quoted-string
        # addr-spec = local-part "@" domain
        # local-part = dot-atom / quoted-string
        # domain = dot-atom / domain-literal
        # dot-atom = 1*atext *("." 1*atext)
        
        // Parse the receiver-text
        $displayName = null;
        $mailAddress = null;
        $mailTokens = [ ];
        $tokenStart = null;
        
        for ($p = 0; $p <= strlen ($mailReceiver); $p++)
          // Check for whitespace
          if (($p == strlen ($mailReceiver)) || ($mailReceiver [$p] == ' ') || ($mailReceiver [$p] == "\t")) {
            if ($tokenStart !== null)
              $mailTokens [] = substr ($mailReceiver, $tokenStart, $p - $tokenStart);
            
            $tokenStart = null;
          } elseif ($tokenStart === null) {
            // Check for quoted string
            if ($mailReceiver [$p] == '"') {
              $tokenStart = $p;
              $p = strpos ($mailReceiver, $mailReceiver [$tokenStart], $tokenStart + 1);
              
              $mailTokens [] = substr ($mailReceiver, $tokenStart + 1, $p - $tokenStart - 1);
              
              $tokenStart = null;
            } else
              $tokenStart = $p;
          }
        
        foreach ($mailTokens as $mailToken)
          if ((strlen ($mailToken) > 3) && ($mailToken [0] == '<') && ($mailToken [strlen ($mailToken) - 1] == '>'))
            $mailAddress = substr ($mailToken, 1, -1);
        
        if (($mailAddress === null) && (count ($mailTokens) == 1))
          $mailAddress = $mailTokens [0];
        
        if (!is_numeric ($receiverIndex))
          $mailReceiversNew [$receiverIndex] = $mailReceiver;
        elseif ($mailAddress !== null)
          $mailReceiversNew [$mailAddress] = $mailReceiver;
        else
          return Events\Promise::reject ('Invalid receiver', $mailReceiver);
      }
      
      $mailReceivers = $mailReceiversNew;
      unset ($mailReceiversNew);
      
      // Prepare an empty e-mail-header
      $mailHeader = '';
      
      // Push receivers to header
      if (
        !$additionalHeaders ||
        (!isset ($additionalHeaders ['To']) && !isset ($additionalHeaders ['Cc']) && !isset ($additionalHeaders ['Bcc']))
      )
        $mailHeader .= 'To: ' . implode (', ', $mailReceivers) . "\r\n";
      
      // Push Subject to header
      if (!$additionalHeaders || !isset ($additionalHeaders ['Subject']))
        $mailHeader .= 'Subject: ' . $mailSubject . "\r\n";
      
      if ($additionalHeaders)
        foreach ($additionalHeaders as $headerName=>$additionalHeader) {
          if (!is_array ($additionalHeader))
            $additionalHeader = [ $additionalHeader ];
          
          foreach ($additionalHeader as $headerValue)
            $mailHeader .= $headerName . ': ' . $headerValue . "\r\n";
        }
      
      return $this->sendMailNative (
        $this->getOriginator (),
        array_keys ($mailReceivers),
        $mailHeader . "\r\n" . $mailBody
      );
    }
    // }}}
    
    // {{{ sendMailNative
    /**
     * @param string $mailOriginator
     * @param array $mailReceivers
     * @param string $mailBody
     * 
     * @access public
     * @return Events\Promise
     **/
    public function sendMailNative (string $mailOriginator, array $mailReceivers, string $mailBody) : Events\Promise {
      return $this->socketPool->createConnection (
        $this->remoteHost,
        $this->remotePort ?? 25,
        Events\Socket::TYPE_TCP,
        ($this->remotePort == 465)
      )->then (
        function (Events\Socket $clientSocket, Events\ABI\Stream\Consumer $smtpStream = null)
        use ($mailOriginator, $mailReceivers, $mailBody) {
          // Make sure we got our SMTP-Stream
          if (!$smtpClient || !($smtpClient instanceof Events\Stream\SMTP\Client)) {
            // Release the socket
            if ($clientSocket)
              $this->socketPool->releaseConnection ($clientSocket);
            
            // Indicate an error
            throw new \Exception ('No SMTP-Client was acquired');
          }
          
          // Try to send the mail
          return $smtpStream->sendMail ($mailOriginator, $mailReceivers, $mailBody)->then (
            function () use ($clientSocket) {
              // Release the socket
              $this->socketPool->releaseConnection ($clientSocket);
            },
            function () use ($clientSocket) {
              // Release the socket
              $this->socketPool->releaseConnection ($clientSocket);
              
              // Forward the error
              throw new Events\Promise\Solution (func_get_args ());
            }
          );
        }
      );
    }
    // }}}
  }
