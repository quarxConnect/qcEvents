<?php

  /**
   * quarxConnect Events - Pipe Trait
   * Copyright (C) 2015-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Feature;
  use quarxConnect\Events;
  
  trait Pipe {
    public static $pipeBlockSize = 40960;
    
    /* Pipe-References */
    private $activePipes = [ ];
    
    // {{{ isPiped
    /**
     * Check if this handle is piped to others
     * 
     * @access public
     * @return bool
     **/
    public function isPiped () {
      return (count ($this->activePipes) > 0);
    }
    // }}}
    
    // {{{ getPipeConsumers
    /**
     * Retrive all handles that we are piped to
     * 
     * @access public
     * @return array
     **/
    public function getPipeConsumers () : array {
      $Result = [ ];
      
      foreach ($this->activePipes as $Pipe)
        $Result [] = $Pipe [0];
      
      return $Result;
    }
    // }}}
    
    // {{{ pipe
    /**
     * Forward any data received from this source to another handler
     * 
     * @param Events\ABI\Consumer $dataReceiver
     * @param bool $forwardClose (optional) Raise close on the handler if we are finished (default)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function pipe (Events\ABI\Consumer $dataReceiver, bool $forwardClose = true) : Events\Promise {
      // Check if there is already such pipe
      if (($key = $this->getPipeHandlerKey ($dataReceiver)) !== false) {
        $this->activePipes [$key][1] = $forwardClose;
        
        return Events\Promise::resolve ();
      }
      
      // Make sure we are receiving data
      if (!$this->isPiped ()) {
        $this->addHook ('eventReadable', [ $this, '___pipeDo' ]);
        $this->addHook ('eventClosed', [ $this, '___pipeClose' ]);
      }
      
      // Make sure we are being informed about changes on the handler itself
      $dataReceiver->addHook ('eventClosed', [ $this, '___pipeHandlerClose' ]);
      
      // Register a that new pipe
      $this->activePipes [] = [ $dataReceiver, $forwardClose, null ];
      
      // Try to initialize consumer
      return $dataReceiver->initConsumer ($this)->then (
        function (callable $customConsumer = null) use ($dataReceiver) {
          // Register a that new pipe
          if ($customConsumer)
            $this->activePipes [$this->getPipeHandlerKey ($dataReceiver)][2] = $customConsumer;
        }
      );
    }
    // }}}
    
    // {{{ pipeStream
    /**
     * Create a bidrectional pipe
     * Forward any data received from this source to another handler and
     * allow the handler to write back to this stream
     * 
     * @param Events\ABI\Consumer $dataReceiver
     * @param bool $forwardClose (optional) Raise close on the handler if we are finished (default)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function pipeStream (Events\ABI\Stream\Consumer $dataReceiver, bool $forwardClose = true) : Events\Promise {
      // Check if there is already such pipe
      if (($key = $this->getPipeHandlerKey ($dataReceiver)) !== false) {
        $this->activePipes [$key][1] = $forwardClose;
        
        return Events\Promise::resolve ();
      }
      
      // Make sure we are receiving data
      if (!$this->isPiped ()) {
        $this->addHook ('eventReadable', [ $this, '___pipeDo' ]);
        $this->addHook ('eventClosed', [ $this, '___pipeClose' ]);
      }
      
      // Make sure we are being informed about changes on the handler itself
      $dataReceiver->addHook ('eventClosed', [ $this, '___pipeHandlerClose' ]);
          
      // Register a that new pipe
      $this->activePipes [] = [ $dataReceiver, $forwardClose, null ];
      
      // Try to initialize consumer
      return $dataReceiver->initStreamConsumer ($this)->then (
        function (callable $customConsumer = null) use ($dataReceiver) {
          // Register a that new pipe
          if ($customConsumer)
            $this->activePipes [$this->getPipeHandlerKey ($dataReceiver)][2] = $customConsumer;
        }
      );
    }
    // }}}
    
    // {{{ unpipe
    /**
     * Remove a handler that is currently being piped
     * 
     * @param Events\ABI\Consumer\Common $dataReceiver
     * 
     * @access public
     * @return Events\Promise
     **/
    public function unpipe (Events\ABI\Consumer\Common $dataReceiver) : Events\Promise {
      // Check if there is already such pipe
      if (($key = $this->getPipeHandlerKey ($dataReceiver)) === false)
        return Events\Promise::resolve ();
      
      // Remove the pipe-reference
      unset ($this->activePipes [$key]);
      
      // Raise an event at the handler
      return $dataReceiver->deinitConsumer ($this);
    }
    // }}}
    
    // {{{ getPipeHandlerKey
    /**
     * Search the internal key for a given handler
     * 
     * @param object $dataReceiver
     * 
     * @access private
     * @return int
     **/
    private function getPipeHandlerKey ($dataReceiver) {
      foreach ($this->activePipes as $key=>$Pipe)
        if ($Pipe [0] === $dataReceiver)
          return $key;
      
      return false;
    }
    // }}}
    
    
    // {{{ ___pipeDo
    /**
     * Callback: Data is available, procees all pipes
     * 
     * @access protected
     * @return public
     **/
    public function ___pipeDo () {
      // Check if there are pipes to process
      if (count ($this->activePipes) == 0) {
        if ($this instanceof Events\ABI\Hookable) {
          $this->removeHook ('eventReadable', [ $this, '___pipeDo' ]);
          $this->removeHook ('eventClosed', [ $this, '___pipeClose' ]);
        }
        
        return;
      }
      
      // Try to read the data
      if (!is_string ($Data = $this->read ($this::$pipeBlockSize)) || (strlen ($Data) < 1))
        return;
      
      // Process all pipes
      foreach ($this->activePipes as $Pipe)
        if ($Pipe [2])
          call_user_func ($Pipe [2], $Data, $this);
        else
          $Pipe [0]->consume ($Data, $this);
    }
    // }}}
    
    // {{{ ___pipeClose
    /**
     * Callback: The readable stream was/is being closed
     * 
     * @access public
     * @return void
     **/
    public function ___pipeClose () {
      // Forward the close to all piped handles
      foreach ($this->activePipes as $Pipe) {
        if ($Pipe [1]) {
          if (is_callable ([ $Pipe [0], 'finishConsume' ]))
            $Pipe [0]->finishConsume ();
          else
            $Pipe [0]->close ();
        }
        
        $Pipe [0]->removeHook ('eventClosed', [ $this, '___pipeHandlerClose' ]);
        $Pipe [0]->deinitConsumer ($this);
      }
      
      // Reset the local register
      $this->activePipes = [ ];
      
      // Unregister hooks
      $this->removeHook ('eventReadable', [ $this, '___pipeDo' ]);
      $this->removeHook ('eventClosed', [ $this, '___pipeClose' ]);
    }
    // }}}
    
    // {{{ ___pipeHandlerClose
    /**
     * Callback: A piped handler was closed
     * 
     * @param object $dataReceiver
     * 
     * @access public
     * @return void
     **/
    public function ___pipeHandlerClose ($dataReceiver) {
      // Make sure the given handler is a consumer
      if (!($dataReceiver instanceof Events\ABI\Consumer) &&
          !($dataReceiver instanceof Events\ABI\Stream\Consumer))
        return;
      
      // Lookup the handler and remove
      if (($key = $this->getPipeHandlerKey ($dataReceiver)) !== false) {
        $dataReceiver->removeHook ('eventClosed', [ $this, '___pipeHandlerClose' ]);
        
        unset ($this->activePipes [$key]);
      }
      
      // Check if there are consumers left
      if ((count ($this->activePipes) == 0) && ($this instanceof Events\ABI\Hookable)) {
        $this->removeHook ('eventReadable', [ $this, '___pipeDo' ]);
        $this->removeHook ('eventClosed', [ $this, '___pipeClose' ]);
      }
    }
    // }}}
  }
