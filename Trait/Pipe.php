<?PHP

  /**
   * qcEvents - Pipe Trait
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
  
  require_once ('qcEvents/Promise.php');
  
  trait qcEvents_Trait_Pipe {
    public static $pipeBlockSize = 40960;
    
    /* Pipe-References */
    private $Pipes = array ();
    
    // {{{ isPiped
    /**
     * Check if this handle is piped to others
     * 
     * @access public
     * @return bool
     **/
    public function isPiped () {
      return (count ($this->Pipes) > 0);
    }
    // }}}
    
    // {{{ getPipeConsumers
    /**
     * Retrive all handles that we are piped to
     * 
     * @access public
     * @return array
     **/
    public function getPipeConsumers () {
      $Result = array ();
      
      foreach ($this->Pipes as $Pipe)
        $Result [] = $Pipe [0];
      
      return $Result;
    }
    // }}}
    
    // {{{ pipe
    /**
     * Forward any data received from this source to another handler
     * 
     * @param qcEvents_Interface_Consumer $Handler
     * @param bool $Finish (optional) Raise close on the handler if we are finished (default)
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Source $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function pipe (qcEvents_Interface_Consumer $Handler, $Finish = true, callable $Callback = null, $Private = null) {
      // Check if there is already such pipe
      if (($key = $this->getPipeHandlerKey ($Handler)) !== false) {
        $this->Pipes [$key][1] = $Finish;
        $this->___raiseCallback ($Callback, true, $Private);
        
        return true;
      }
      
      // Make sure we are receiving data
      $this->addHook ('eventReadable', array ($this, '___pipeDo'));
      $this->addHook ('eventClosed', array ($this, '___pipeClose'));
      
      // Raise an event at the handler
      if (($rc = $Handler->initConsumer ($this, function (qcEvents_Interface_Consumer $Self, $Status) use ($Callback, $Private) {
        $this->___raiseCallback ($Callback, $Status, $Private);
      })) === false)
        return false;
      
      // Make sure we are being informed about changes on the handler itself
      $Handler->addHook ('eventClosed', array ($this, '___pipeHandlerClose'));
      
      // Register a new pipe
      $this->Pipes [] = array ($Handler, $Finish, is_callable ($rc) ? $rc : null);
      
      return true;
    }
    // }}}
    
    // {{{ pipeStream
    /**
     * Create a bidrectional pipe
     * Forward any data received from this source to another handler and
     * allow the handler to write back to this stream
     * 
     * @param qcEvents_Interface_Consumer $Handler
     * @param bool $Finish (optional) Raise close on the handler if we are finished (default)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function pipeStream (qcEvents_Interface_Stream_Consumer $Handler, $Finish = true) {
      // Check if there is already such pipe
      if (($key = $this->getPipeHandlerKey ($Handler)) !== false) {
        $this->Pipes [$key][1] = $Finish;
        
        return qcEvents_Promise::resolve ();
      }
      
      // Raise an event at the handler
      $Promise = $Handler->initStreamConsumer ($this)->catch (
        function () use ($Handler) {
          // Clean up
          $this->removeHook ('eventReadable', array ($this, '___pipeDo'));
          $this->removeHook ('eventClosed', array ($this, '___pipeClose'));
          
          # $Handler->removeHook ('eventReadable', array ($this, '___pipeHandlerDo'));
          $Handler->removeHook ('eventClosed', array ($this, '___pipeHandlerClose'));
          
          // Forward the error
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
      
      // Make sure we are receiving data
      $this->addHook ('eventReadable', array ($this, '___pipeDo'));
      $this->addHook ('eventClosed', array ($this, '___pipeClose'));
      
      // Make sure we are being informed about changes on the handler itself
      # $Handler->addHook ('eventReadable', array ($this, '___pipeHandlerDo'));
      $Handler->addHook ('eventClosed', array ($this, '___pipeHandlerClose'));
      
      // Register a new pipe
      $this->Pipes [] = array ($Handler, $Finish, null);
      
      return $Promise;
    }
    // }}}
    
    // {{{ unpipe
    /**
     * Remove a handler that is currently being piped
     * 
     * @param qcEvents_Interface_Consumer_Common $Handler
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function unpipe (qcEvents_Interface_Consumer_Common $Handler) {
      // Check if there is already such pipe
      if (($key = $this->getPipeHandlerKey ($Handler)) === false)
        return qcEvents_Promise::reject ('Consumer not found');
      
      // Remove the pipe-reference
      unset ($this->Pipes [$key]);
      
      // Raise an event at the handler
      return $Handler->deinitConsumer ($this);
    }
    // }}}
    
    // {{{ getPipeHandlerKey
    /**
     * Search the internal key for a given handler
     * 
     * @param object $Handler
     * 
     * @access private
     * @return int
     **/
    private function getPipeHandlerKey ($Handler) {
      foreach ($this->Pipes as $key=>$Pipe)
        if ($Pipe [0] === $Handler)
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
      if (count ($this->Pipes) == 0) {
        if ($this instanceof qcEvents_Interface_Hookable) {
          $this->removeHook ('eventReadable', array ($this, '___pipeDo'));
          $this->removeHook ('eventClosed', array ($this, '___pipeClose'));
        }
        
        return;
      }
      
      // Try to read the data
      if (!is_string ($Data = $this->read ($this::$pipeBlockSize)) || (strlen ($Data) < 1))
        return;
      
      // Process all pipes
      foreach ($this->Pipes as $Pipe)
        if ($Pipe [2])
          call_user_func ($Pipe [2], $Data, $this);
        else
          $Pipe [0]->consume ($Data, $this);
    }
    // }}}
    
    // {{{ ___pipeHandlerDo
    /**
     * Callback: Data is available at piped stream side, try to fetch and write back
     * 
     * @param qcEvents_Interface_Stream_Consumer $Handler
     * 
     * @access public
     * @return void
     **/
    #public function ___pipeHandlerDo (qcEvents_Interface_Stream_Consumer $Handler) {
    #  // Check if there are pipes to process
    #  if (count ($this->Pipes) == 0) {
    #    if ($this instanceof qcEvents_Interface_Hookable) {
    #      $this->removeHook ('eventReadable', array ($this, '___pipeDo'));
    #      $this->removeHook ('eventClosed', array ($this, '___pipeClose'));
    #    }
    #  
    #    return;
    #  }
    #  
    #  // Check if there is already such pipe
    #  if (($key = $this->getPipeHandlerKey ($Handler)) === false) {
    #    # $Handler->removeHook ('eventReadable', array ($this, '___pipeHandlerDo'));
    #    $Handler->removeHook ('eventClosed', array ($this, '___pipeHandlerClose'));
    #    
    #    return;
    #  }
    #  
    #  // Try to read the data
    #  if (!is_string ($Data = $Handler->read ($this::$pipeBlockSize)) || (strlen ($Data) < 1))
    #    return;
    #  
    #  $this->write ($Data);
    #}
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
      foreach ($this->Pipes as $Pipe) {
        if ($Pipe [1]) {
          if (is_callable (array ($Pipe [0], 'finishConsume')))
            $Pipe [0]->finishConsume ();
          else
            $Pipe [0]->close ();
        }
        
        $Pipe [0]->removeHook ('eventClosed', array ($this, '___pipeHandlerClose'));
        $Pipe [0]->deinitConsumer ($this);
      }
      
      // Reset the local register
      $this->Pipes = array ();
      
      // Unregister hooks
      $this->removeHook ('eventReadable', array ($this, '___pipeDo'));
      $this->removeHook ('eventClosed', array ($this, '___pipeClose'));
    }
    // }}}
    
    // {{{ ___pipeHandlerClose
    /**
     * Callback: A piped handler was closed
     * 
     * @param object $Handler
     * 
     * @access public
     * @return void
     **/
    public function ___pipeHandlerClose ($Handler) {
      // Make sure the given handler is a consumer
      if (!($Handler instanceof qcEvents_Interface_Consumer) &&
          !($Handler instanceof qcEvents_Interface_Stream_Consumer))
        return;
      
      // Lookup the handler and remove
      if (($key = $this->getPipeHandlerKey ($Handler)) !== false) {
        $Handler->removeHook ('eventClosed', array ($this, '___pipeHandlerClose'));
        
        unset ($this->Pipes [$key]);
      }
      
      // Check if there are consumers left
      if ((count ($this->Pipes) == 0) && ($this instanceof qcEvents_Interface_Hookable)) {
        $this->removeHook ('eventReadable', array ($this, '___pipeDo'));
        $this->removeHook ('eventClosed', array ($this, '___pipeClose'));
      }
    }
    // }}}
  }

?>