<?PHP

  /**
   * qcEvents - GPIO-Interface
   * Copyright (C) 2016 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Loop.php');
  require_once ('qcEvents/Trait/Parented.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/File.php');
  
  class qcEvents_SysFS_GPIO extends qcEvents_Hookable implements qcEvents_Interface_Loop {
    /* Re-use common functions for our interface */
    use qcEvents_Trait_Parented;
    
    /* GPIO-Direction */
    const GPIO_IN = 0;
    const GPIO_OUT = 1;
    
    /* GPIO-Edge */
    const GPIO_EDGE_NONE = 0;
    const GPIO_EDGE_FALLING = 1;
    const GPIO_EDGE_RISING = 2;
    const GPIO_EDGE_BOTH = 3;
    
    /* Number of used GPIO-Pin */
    private $gpioNumber = null;

    /* Direction of GPIO-Pin */
    private $gpioDirection = qcEvents_SysFS_GPIO::GPIO_IN;
    
    /* Edge of GPIO-Pin */
    private $gpioEdge = qcEvents_SysFS_GPIO::GPIO_EDGE_BOTH;
    
    /* Read/Write FD for GPIO-Value */
    private $gpioFD = null;
    
    /* Known GPIO-State */
    private $gpioState = null;
    
    /* Pending State-Changes */
    private $gpioSetState = null;
    
    /* State-Callbacks */
    private $gpioStateCallbacks = array ();
    
    // {{{ __construct
    /**
     * Create a new GPIO-Interface
     * 
     * @param qcEvents_Base $Base (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base = null, $Direction = null, $Edge = null) {
      if ($Base)
        $this->setEventBase ($Base);
      
      if (($Direction !== null) && in_array ($Direction, array ($this::GPIO_IN, $this::GPIO_OUT)))
        $this->gpioDirection = $Direction;
      
      if (($Edge !== null) && in_array ($Edge, array ($this::GPIO_EDGE_NONE, $this::GPIO_EDGE_RISING, $this::GPIO_EDGE_FALLING, $this::GPIO_EDGE_BOTH)))
        $this->gpioEdge = $Edge;
    }
    // }}}
    
    // {{{ setGPIO
    /**
     * Switch the used GPIO-Pin
     * 
     * @param int $Number
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function setGPIO ($Number, callable $Callback = null, $Private = null) {
      // Make sure its a number
      $Number = (int)$Number;
      
      // Check if the GPIO is already exported
      if (is_dir ('/sys/class/gpio/gpio' . $Number))
        return $this->setGPIOAttach ($Number, $this->gpioDirection, $this->gpioEdge, $Callback, $Private);
      
      // Try to export the Pin
      return qcEvents_File::writeFileContents ($this->getEventBase (), '/sys/class/gpio/export', (string)$Number)->then (
        function () use ($Number, $Callback, $Private) {
          // Wait for directiory to appear
          if (!is_dir ('/sys/class/gpio/gpio' . $Number))
            return $this->setGPIOWait (0, $Number, $this->gpioDirection, $this->gpioEdge, $Callback, $Private);
          
          // Check modes
          if (!is_array ($Ctrl = @stat ('/sys/class/gpio/export')))
            return $this->setGPIOAttach ($Number, $this->gpioDirection, $this->gpioEdge, $Callback, $Private);
          
          if (!is_array ($Stat = stat ('/sys/class/gpio/gpio' . $Number . '/direction')) ||
            (!is_writable ('/sys/class/gpio/gpio' . $Number . '/direction') &&
             (($Ctrl ['mode'] != $Stat ['mode']) ||
              ($Ctrl ['gid'] != $Stat ['gid']))))
            return $this->setGPIOWait (0, $Number, $this->gpioDirection, $this->gpioEdge, $Callback, $Private);
          
          // Attach ourself to that GPIO
          return $this->setGPIOAttach ($Number, $this->gpioDirection, $this->gpioEdge, $Callback, $Private);
        },
        function () use ($Callback, $Number, $Private) {
          trigger_error ('Failed to query GPIO-Export');
          
          return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
        }
      );
    }
    // }}}
    
    // {{{ setGPIOWait
    /**
     * Wait for GPIO-Pin to be setup by system
     * 
     * @param int $Iteration
     * @param int $Number
     * @param enum $Direction
     * @param enum $Edge
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access private
     * @return void
     **/
    private function setGPIOWait ($Iteration, $Number, $Direction, $Edge, callable $Callback = null, $Private = null) {
      // Empty cache
      clearstatcache (false, '/sys/class/gpio/gpio' . $Number);
      
      // Check if the directory appeared
      if (!is_dir ('/sys/class/gpio/gpio' . $Number)) {
        // Check for a timeout
        if ($Iteration > 100) {
          trigger_error ('GPIO was not exported');
          
          return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
        }
        
        // Wait a while
        return $this->getEventBase ()->addTimeout (0.01)->then (
          function () use ($Iteration, $Number, $Direction, $Edge, $Callback, $Private) {
            return $this->setGPIOWait (++$Iteration, $Number, $Direction, $Edge, $Callback, $Private);
          }
        );
      }
      
      // Try to check modes
      if (!is_array ($Ctrl = @stat ('/sys/class/gpio/export')))
        return $this->setGPIOAttach ($Number, $this->gpioDirection, $this->gpioEdge, $Callback, $Private);
      
      if (!is_array ($Stat = stat ('/sys/class/gpio/gpio' . $Number . '/direction')) ||
          (!is_writable ('/sys/class/gpio/gpio' . $Number . '/direction') &&
           (($Ctrl ['mode'] != $Stat ['mode']) ||
            ($Ctrl ['gid'] != $Stat ['gid'])))) {
        // Check for a timeout
        if ($Iteration > 100) {
          trigger_error ('GPIO-Modes were not changed');
          
          return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
        }
        
        // Wait a while
        return $this->getEventBase ()->addTimeout (0.01)->then (
          function () use ($Iteration, $Number, $Direction, $Edge, $Callback, $Private) {
            return $this->setGPIOWait (++$Iteration, $Number, $Direction, $Edge, $Callback, $Private);
          }
        );
      }
      
      // Just try to attach
      return $this->setGPIOAttach ($Number, $this->gpioDirection, $this->gpioEdge, $Callback, $Private);
    }
    // }}}
    
    // {{{ setGPIOAttach
    /**
     * Attach ourself to an already exported GPIO-Pin
     * 
     * @param int $Number
     * @param enum $Direction
     * @param enum $Edge
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access private
     * @return void
     **/
    private function setGPIOAttach ($Number, $Direction, $Edge, callable $Callback = null, $Private = null) {
      // Setup the direction of the GPIO
      qcEvents_File::writeFileContents (
        $this->getEventBase (),
        '/sys/class/gpio/gpio' . $Number . '/direction',
        ($Direction == $this::GPIO_IN ? 'in' : 'out')
      )->then (
        function () use ($Number, $Direction, $Edge) {
          // Setup edges
          return qcEvents_File::writeFileContents (
            $this->getEventBase (),
            '/sys/class/gpio/gpio' . $Number . '/edge',
            (($Direction == $this::GPIO_OUT) || ($Edge == $this::GPIO_EDGE_NONE) ? 'none' : ($Edge == $this::GPIO_EDGE_FALLING ? 'falling' : ($Edge == $this::GPIO_EDGE_RISING ? 'rising' : 'both')))
          );
        }
      )->then (
        function () use ($Number, $Direction, $Edge, $Callback, $Private) {
          // Try to open the value-reader
          $gpioFD = fopen ('/sys/class/gpio/gpio' . $Number . '/value', ($Direction == $this::GPIO_IN ? 'r' : 'w+'));
          
          if (!is_resource ($gpioFD)) {
            trigger_error ('Failed to open GPIO-Pin');
            
            return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
          }
          
          // Close old FD
          if (is_resource ($this->gpioFD))
            fclose ($this->gpioFD);
          
          // Setup new FD
          $this->gpioNumber = $Number;
          $this->gpioFD = $gpioFD;
          $this->gpioDirection = $Direction;
          $this->gpioEdge = $Edge;
          
          if ($Base = $this->getEventBase ())
            $Base->updateEvent ($this);
          
          // Raise the callback
          return $this->___raiseCallback ($Callback, $this, $Number, true, $Private);
        },
        function () use ($Callback, $Number, $Private) {
          return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
        }
      );
    }
    // }}}
    
    // {{{ setDirection
    /**
     * Switch Direction of GPIO-Pin
     * 
     * @param enum $Direction
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function setDirection ($Direction, callable $Callback = null, $Private = null) {
      // Make sure the direction is correct
      if (!in_array ($Direction, array ($this::GPIO_IN, $this::GPIO_OUT))) {
        trigger_error ('Invalid direction');
        
        return $this->___raiseCallback ($Callback, false, $Private);
      }
      
      // Check if the direction is already set
      if ($this->gpioDirection == $Direction)
        return $this->___raiseCallback ($Callback, true, $Private);
      
      // Check if we are already bound
      if ($this->gpioNumber)
        return $this->setGPIOAttach ($this->gpioNumber, $Direction, $this->gpioEdge, $Callback, $Private);
      
      // Just store the new direction
      $this->gpioDirection = $Direction;
      
      // Raise callback
      return $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ setEdge
    /**
     * Switch Event-Edge of GPIO-Pin
     * 
     * @param enum $Edge
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function setEdge ($Edge, callable $Callback = null, $Private = null) {
      // Make sure the edge is correct
      if (!in_array ($Edge, array ($this::GPIO_EDGE_NONE, $this::GPIO_EDGE_RISING, $this::GPIO_EDGE_FALLING, $this::GPIO_EDGE_BOTH))) {
        trigger_error ('Invalid edge');
          
        return $this->___raiseCallback ($Callback, false, $Private);
      }
       
      // Check if the edge is already set
      if ($this->gpioEdge == $Edge)
        return $this->___raiseCallback ($Callback, true, $Private);
      
      // Check if we are already bound 
      if ($this->gpioNumber)
        return $this->setGPIOAttach ($this->gpioNumber, $this->gpioDirection, $Edge, $Callback, $Private);
      
      // Just store the new edge
      $this->gpioEdge = $Edge;
      
      // Raise callback
      return $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ setStatus
    /**
     * Set the State of our GPIO-Pin (if direction is OUT)
     * 
     * @param bool $Status
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function setStatus ($Status, callable $Callback = null, $Private = null) {
      // Make sure its a boolean
      $Status = !!$Status;
      
      // Check if the state is already up-to-date
      if ($this->gpioState === $Status)
        return $this->___raiseCallback ($Callback, $Private);
      
      // Enqueue the state
      $this->gpioSetState = $Status;
      $this->gpioStateCallbacks [] = array ($Callback, $Private);
      
      // Try to write
      if ($Base = $this->getEventBase ())
        $Base->updateEvent ($this);
    }
    // }}}
    
    
    // {{{ getReadFD
    /**
     * Retrive the stream-resource to watch for reads
     * 
     * @access public
     * @return resource May return NULL if no reads should be watched
     **/
    public function getReadFD () {
      return null;
    }
    // }}}
    
    // {{{ getWriteFD
    /**
     * Retrive the stream-resource to watch for writes
     * 
     * @access public
     * @return resource May return NULL if no writes should be watched
     **/
    public function getWriteFD () {
      if (($this->gpioSetState !== null) && ($this->gpioDirection == $this::GPIO_OUT))
        return $this->gpioFD;
    }
    // }}}
    
    // {{{ getErrorFD
    /**
     * Retrive an additional stream-resource to watch for errors
     * @remark Read-/Write-FDs are always monitored for errors
     * 
     * @access public
     * @return resource May return NULL if no additional stream-resource should be watched
     **/
    public function getErrorFD () {
      return $this->gpioFD;
    }
    // }}}
    
    // {{{ raiseRead
    /**
     * Callback: The Event-Loop detected a read-event
     * 
     * @access public
     * @return void  
     **/
    public function raiseRead () { /* Not used at any time, but forced by interface */ }
    // }}}
    
    // {{{ raiseWrite
    /**
     * Callback: The Event-Loop detected a write-event
     * 
     * @access public
     * @return void  
     **/
    public function raiseWrite () {
      // Check if there is anything to change
      if ($this->gpioSetState !== null) {
        // Write out the new state
        fseek ($this->gpioFD, 0);
        fwrite ($this->gpioFD, ($this->gpioSetState ? '1' : '0'));
        
        // Remove local write-request
        $this->gpioState = $this->gpioSetState;
        $this->gpioSetState = null;
        
        // Raise callbacks
        $Callbacks = $this->gpioStateCallbacks;
        $this->gpioStateCallbacks = array ();
        
        foreach ($Callbacks as $Callback)
          $this->___raiseCallback ($Callback [0], $Callback [1]);
        
        $this->___callback ('gpioStateChanged', $this->gpioState);
      }
      
      // Remove ourself from write-queue
      if ($Base = $this->getEventBase ())
        $Base->updateEvent ($this);
    }
    // }}}
    
    // {{{ raiseError
    /**
     * Callback: The Event-Loop detected an error-event
     * 
     * @param resource $fd
     * 
     * @access public
     * @return void  
     **/
    public function raiseError ($fd) {
      // Sanity-check the given fd
      if ($fd !== $this->gpioFD)
        return;
      
      // Seek back to begin
      fseek ($fd, 0);
      
      // Read the state
      $State = ((int)fread ($fd, 1024) == 1);
      
      if ($this->gpioState === $State)
        return;
      
      // Store the new state
      $this->gpioState = $State;
      
      // Raise callbacks
      $this->___callback ('gpioStateChanged', $State);
    }
    // }}}
    
    // {{{ gpioStateChanged
    /**
     * Callback: State of our GPIO-Pin changed
     * 
     * @param bool $State
     * 
     * @access protected
     * @return void
     **/
    protected function gpioStateChanged ($State) { }
    // }}}
  }

?>