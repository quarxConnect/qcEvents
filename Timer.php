<?PHP

  /**
   * qcEvents - Timer Promise
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Promise.php');
  
  /**
   * Timer Event
   * -----------
   * Event-Object for timed tasks
   * 
   * @class qcEvents_Timer
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Timer extends qcEvents_Promise {
    /* Interval for this timer */
    private $Interval = 1.00;
    
    /* Repeat the timer */
    private $Repeat = false;
    
    // {{{ __construct
    /**
     * Create a new timer
     * 
     * @param qcEvents_Base $eventBase
     * @param float $Interval
     * @param bool $Repeat
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase, $Interval, $Repeat = false) {
      // Store our settings
      $this->Interval = (float)$Interval;
      $this->Repeat = !!$Repeat;
      $this->resetCallbacks = false;
      
      // Inherit to our parent
      parent::__construct (null, $eventBase);
      
      // Arm the timer
      $eventBase->addTimer ($this);
    }
    // }}}
    
    // {{{ __debugInfo
    /**
     * Return information about this instance to be dumped by var_dump()
     * 
     * @access public
     * @return array
     **/
    public function __debugInfo () {
      // Retrive info for our parent promise
      $Result = parent::__debugInfo ();
      
      // Patch in our own informations
      $Result ['timerInterval'] = $this->Interval;
      $Result ['timerRepeat'] = $this->Repeat;
      
      // Forward the result      
      return $Result;
    }
    // }}}
    
    // {{{ getInterval
    /**
     * Retrive the interval of this timer
     * 
     * @access public
     * @return float
     **/
    public function getInterval () {
      return $this->Interval;
    }
    // }}}
    
    // {{{ setInterval
    /**
     * Set a new interval for this timer
     * 
     * @param float $Interval
     * 
     * @access public
     * @return void
     **/
    public function setInterval ($Interval) {
      // Store the new interval
      $this->Interval = (float)$Interval;
      
      // Try to restart the timer
      if ($this->getEventBase ()->clearTimer ($this))
        $this->getEventBase ()->addTimer ($this);
    }
    // }}}
    
    // {{{ run
    /**
     * Run the timer
     * This function is intended to be executed exclusively by qcEvents_Base.
     * 
     * @access public
     * @return void
     **/
    public function run () {
      // Store our repeat-setting
      $Repeat = $this->Repeat;
      
      // Fullfill the promise
      $this->promiseFullfill ();
      
      // Re-Arm the timer
      if ($this->Repeat) {
        $this->reset ();
        $this->getEventBase ()->addTimer ($this);
      } elseif ($Repeat)
        $this->Repeat = true;
    }
    // }}}
    
    // {{{ restart
    /**
     * Restart this timer
     * 
     * @access public
     * @return void
     **/
    public function restart () {
      $this->cancel ();
      $this->getEventBase ()->addTimer ($this);
    }
    // }}}
    
    // {{{ cancel
    /**
     * Cancel the timer
     * 
     * @access public
     * @return void
     **/
    public function cancel () {
      // Signal that we were canceled
      if ($this->getDone () != $this::DONE_NONE)
        $this->Repeat = false;
      
      // Try to remove at our parent
      if ($this->getEventBase ()->clearTimer ($this))
        return;
      
      // Reject the promise
      $this->promiseReject ('canceled');
      
      // Reset our state
      $this->reset ();
    }
    // }}}
  }

?>
