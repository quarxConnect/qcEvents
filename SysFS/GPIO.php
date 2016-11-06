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
    const GPIO_EDGE_FALLING = 0;
    const GPIO_EDGE_RISING = 1;
    const GPIO_EDGE_BOTH = 2;
    
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
      
      if ($Direction !== null)
        $this->gpioDirection = $Direction;
      
      if ($Edge !== null)
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
      $File = new qcEvents_File ($this->getEventBase (), '/sys/class/gpio/export', false, true);
      
      return $File->write ((string)$Number, function (qcEvents_File $File, $Status) use ($Number, $Callback, $Private) {
        // Forget about the file
        $File->close ();
        
        // Make sure the GPIO was exported
        if (!$Status) {
          trigger_error ('Failed to query GPIO-Export');
          
          return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
        } elseif (!is_dir ('/sys/class/gpio/gpio' . $Number)) {
          trigger_error ('GPIO was not exported');
          
          return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
        }
        
        // Attach ourself to that GPIO
        return $this->setGPIOAttach ($Number, $this->gpioDirection, $this->gpioEdge, $Callback, $Private);
      });
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
        ($Direction == $this::GPIO_IN ? 'in' : 'out'),
        
        function ($Status) use ($Number, $Direction, $Edge, $Callback, $Private) {
          // Make sure the write was successfull
          if (!$Status) {
            trigger_error ('Failed to set direction');
            
            return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
          }
          
          // Setup edges
          qcEvents_File::writeFileContents (
            $this->getEventBase (),
            '/sys/class/gpio/gpio' . $Number . '/edge',
            ($Direction == $this::GPIO_OUT ? 'none' : ($Edge == $this::GPIO_EDGE_FALLING ? 'falling' : ($Edge == $this::GPIO_EDGE_RISING ? 'rising' : 'both'))),
            
            function ($Status) use ($Number, $Direction, $Edge, $Callback, $Private) {
              // Make sure it was successfull
              if (!$Status) {
                trigger_error ('Failed to set edge');
                
                return $this->___raiseCallback ($Callback, $this, $Number, false, $Private);
              }
              
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
            }
          ); // qcEvents_File::writeFileContents () - GPIO-Edge
        }
      ); // qcEvents_File::writeFileContents () - GPIO-Direction
    }
    // }}}
    
    // {{{ setDirection
    /**
     * Setup the direction of the used GPIO-Pin
     * 
     * @param enum $Direction
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function setDirection ($Direction, callable $Callback = null, $Private = null) {
    
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
        
        echo 'GPIO ', $this->gpioNumber, ': WRITE ', ($this->gpioSetState ? '1' : '0'), "\n";
        
        // Remove local write-request
        $this->gpioState = $this->gpioSetState;
        $this->gpioSetState = null;
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
    protected function gpioStateChanged ($State) { echo 'GPIO ', $this->gpioNumber, ': State change', "\n"; }
    // }}}
  }

?>