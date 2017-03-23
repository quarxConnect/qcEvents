<?PHP

  /**
   * qcEvents - Timed Events
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Timer.php');
  require_once ('qcEvents/Trait/Timer.php');
  require_once ('qcEvents/Trait/Hookable.php');
  require_once ('qcEvents/Trait/Parented.php');
  
  /**
   * Timer Event
   * -----------
   * Event-Object for timed tasks
   * 
   * @class qcEvents_Timer
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Timer implements qcEvents_Interface_Timer {
    use qcEvents_Trait_Timer, qcEvents_Trait_Hookable, qcEvents_Trait_Parented;
    private $Queued = false;
    
    // {{{ __construct
    /**
     * Create a new Timer
     * 
     * @param qcEvents_Base $Base (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base = null) {
      if ($Base)
        $this->setEventBase ($Base);
    }
    // }}}
    
    // {{{ raiseTimer
    /**
     * Callback: A timer-event was raised
     * 
     * @access public
     * @return void
     **/
    public function raiseTimer () {
      $this->___callback ('eventTimer');
    }
    // }}}
    
    // {{{ eventTimer
    /**
     * Callback: A timer-event was raised
     * 
     * @access protected
     * @return void
     **/
    protected function eventTimer () { }
    // }}}
  }    

?>