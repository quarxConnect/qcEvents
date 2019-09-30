<?PHP

  /**
   * qcEvents - Stratum-Client
   * Copyright (C) 2018-2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Stream/Stratum.php');
  require_once ('qcEvents/Promise.php');
  
  class qcEvents_Client_Stratum extends qcEvents_Stream_Stratum {
    /* Mining-Difficulty */
    private $Difficulty = 1;
    
    /* Version-String of this client (set via subscribe()) */
    private $Version = null;
    
    /* Authenticated username */
    private $Username = null;
    
    // {{{ subscribe
    /**
     * Try to subscribe to service
     * 
     * @param string $clientVersion (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function subscribe ($Version = 'qcEvents/Stratum') : qcEvents_Promise {
      switch ($this->getProtocolVersion ()) {
        // Ethereum-GetWork does not support this and negotiates version later
        case $this::PROTOCOL_ETH_GETWORK:
          $this->Version = $Version;
          
          return qcEvents_Promise::resolve (array (), null, null);
        // Ethereum-Stratum has empty parameters?!
        case $this::PROTOCOL_ETH_STRATUM:
          $Params = array ();
          
          break;
        // Ethereum-Nicehash has protocol-version right after client-version
        case $this::PROTOCOL_ETH_STRATUM_NICEHASH:
          $Params = array ($Version, 'EthereumStratum/1.0.0');
          
          break;
        default:
          $Params = array ($Version);
      }
      
      return $this->sendRequest (
        'mining.subscribe',
        $Params
      )->then (
        function ($Result)
        use ($Version) {
          // Sanatize the result
          if (!$Result || !is_array ($Result) || (count ($Result) <= 2))
            throw new exception ('Invalid result received');
          
          // Store the version
          $this->Version = $Version;
          
          // Transform the result
          $Result = array (
            'Services' => $Result [0],
            'ExtraNonce1' => hexdec ($Result [1]),
            'ExtraNonce2Length' => $Result [2],
          );
          
          // Raise the callback
          $this->___callback ('stratumSubscribed', $Result ['Services'], $Result ['ExtraNonce1'], $Result ['ExtraNonce2Length']);
          
          // Forward the result
          return new qcEvents_Promise_Solution (array ($Result ['Services'], $Result ['ExtraNonce1'], $Result ['ExtraNonce2Length']));
        }
      );
    }
    // }}}
    
    // {{ authenticate
    /**
     * Try to authenticate at the pool
     * 
     * @param string $Username
     * @param string $Password (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function authenticate ($Username, $Password = null) : qcEvents_Promise {
      return $this->sendRequest (
        ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK ? 'eth_submitLogin' : 'mining.authorize'),
        ($Password !== null ? array ($Username, $Password) : array ($Username)),
        ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK ? array ('worker' => $this->Version) : null)
      )->then (
        function ($Result)
        use ($Username) {
          // Make sure its a bool
          $Result = !!$Result;
          
          if ($Result) {
            $this->Username = $Username;
            $this->___callback ('stratumAuthenticated', $Username);
            
            if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK)
              $this->sendMessage (array (
                'id' => 0,
                'method' => 'eth_getWork',
                'params' => array (),
              ));
          }
          
          // Forward the result
          return $Result;
        }
      );
    }
    // }}}
    
    // {{{ getUsername
    /**
     * Retrive the last authenticated username
     * 
     * @access public
     * @return string
     **/
    public function getUsername () {
      return $this->Username;
    }
    // }}}
    
    // {{{ submitWork
    /**
     * Submit work
     * 
     * @param array $Work
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function submitWork (array $Work) : qcEvents_Promise {
      // Preprocess Work for ethereum
      if ($this->getAlgorithm () == $this::ALGORITHM_ETH) {
        # GetWork:
        #   Result
        #   Headerhash
        #   MixHash
        # Stratum:
        #   Username
        #   JobID
        #   Result
        #   Headerhash
        #   MixHash
        # Nicehash
        #   Username
        #   JobID
        #   Result
      }
      
      // Send out the message
      return $this->sendRequest (
        ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK ? 'eth_submitWork' : 'mining.submit'),
        $Work
      );
    }
    // }}}
    
    // {{{ processRequest
    /**
     * Process a Stratum-Request
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function processRequest ($Message) {
      // Pool sets a new difficulty
      if ($Message->method == 'mining.set_difficulty') {
        $this->Difficulty = $Message->params [0];
        
        return $this->___callback ('stratumSetDifficulty', $this->Difficulty);
      
      // Pool sends new job
      } elseif ($Message->method == 'mining.notify')
        return $this->___callback ('stratumNewJob', $Message->params);
      
      parent::processRequest ($Message);
    }
    // }}}
    
    // {{{ processNotify
    /**
     * Process a Stratum-Notify
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function processNotify ($Message) {
      if (($this->getProtocolVersion () != $this::PROTOCOL_ETH_GETWORK) || (count ($Message->result) != 3))
        return parent::processNotify ($Message);
      
      return $this->___callback ('stratumNewJob', $Message->result);
    }
    // }}}
    
    protected function stratumSubscribed ($Services, $ExtraNonce1, $ExtraNonce2Length) { }
    protected function stratumAuthenticated ($Username) { }
    protected function stratumSetDifficulty ($Difficulty) { }
    protected function stratumNewJob ($Job) { }
  }

?>
