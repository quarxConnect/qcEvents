<?PHP

  /**
   * qcEvents - inotify-Event-Handler
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  // Make sure the inotify-extension was loaded
  if (!extension_loaded ('inotify')) {
    trigger_error (E_USER_ERROR, 'inotify-extension not loaded');
    return;
  }
  
  require_once ('qcEvents/Event.php');
  
  /**
   * inotify
   * -------
   * Event-Handler to monitor inode-changes
   * 
   * @class qcEvents_inotify
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_inotify extends qcEvents_Event{
    /* File-Operations */
    const MASK_ACCESS = 1; // File was accessed (read)
    const MASK_MODIFY = 2; // File was modified
    const MASK_ATTRIB = 4; // Metadata of file was changed
    const MASK_CLOSE_WRITE = 8; // Write-Handle was closed
    const MASK_CLOSE_READ = 16; // Read-Handle was closed
    const MASK_OPEN = 32; // File was openend
    const MASK_DELETE = 1024; // Watched file or directory was removed
    const MASK_MOVE = 2048; // Watched file or directory was moved
    
    /* File-Meta-Operations */
    const MASK_CLOSE = 24; // qcEvents_inotify::MASK_CLOSE_WRITE | qcEvents_inotify::MASK_CLOSE_READ;
    
    /* Directory-Operations */
    const MASK_MOVE_FROM = 64; // File was moved out of watched directory
    const MASK_MOVE_TO = 128; // File was moved into watched directory
    const MASK_CREATE = 256; // File was created in watched directory
    const MASK_REMOVE = 512; // File was removed from watched directory
    
    /* Directory-Meta-Operations */
    const MASK_MOVE_ANY = 192; // qcEvents_inotify::MASK_MOVE_FROM | qcEvents_inotify::MASK_MOVE_TO;
    
    /* Meta-Operations */
    const MASK_ALL = 4095; // Watch all events
    
    private static $inotifyGenerations = 0;
    private static $inotifyEvents = array ();
    
    // Internal inotify-handle
    private $inotify = null;
    private $inotifyGeneration = 0;
    
    // Path to watch
    private $inotifyPath = '';
    
    // Mask for watch
    private $inotifyMask = 0;
    
    // Inotify-Reference for this handle
    private $inotifyDescriptor = null;
    
    // {{{ __construct
    /**
     * Create a new inotify-Handler for a given path
     * 
     * @param string $Path
     * @param int $Mask (optional) Type of events to watch
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Path, $Mask = self::MASK_ALL) {
      $this->inotifyPath = $Path;
      $this->inotifyMask = $Mask;
    }
    // }}}
    
    // {{{ setHandler
    /**
     * Store handle of our event-base
     * 
     * @param object $Handler
     * 
     * @access public
     * @return bool
     **/
    public function setHandler ($Handler, $markBound = false) {
      // Save our old handler
      $oh = $this->getHandler ();
      
      // Call our parent first
      if (!($rc = parent::setHandler ($Handler, $markBound)))
        return $rc;
      
      // Don't do anything if the handler didn't change
      if ($oh === $Handler)
        return $rc;
      
      // Unbind from the old inotify
      if ($this->inotifyDescriptor !== null) {
        inotify_rm_watch ($this->inotify, $this->inotifyDescriptor);
        unset (self::$inotifyEvents [$this->inotifyGeneration][$this->inotifyDescriptor]);
        
        if (count (self::$inotifyEvents [$this->inotifyGeneration]) == 0)
          fclose ($this->inotify);
      }
      
      $this->inotifyDescriptor = null;
      $this->inotify = null;
      
      // Find a new inotify
      foreach ($Handler->getEvents () as $Event)
        if (($Event instanceof qcEvents_inotify) && is_resource ($Event->inotify)) {
          $this->inotify = $Event->inotify;
          $this->inotifyGeneration = $Event->inotifyGeneration;
          break;
        }
      
      if (!is_resource ($this->inotify)) {
        $this->inotify = inotify_init ();
        $this->inotifyGeneration = self::$inotifyGenerations++;
        self::$inotifyEvents [$this->inotifyGeneration] = array ();
      }
      
      $this->setFD ($this->inotify, true, false);
      
      // Register our watch
      $this->inotifyDescriptor = inotify_add_watch ($this->inotify, $this->inotifyPath, $this->inotifyMask);
      self::$inotifyEvents [$this->inotifyGeneration][$this->inotifyDescriptor] = $this;
      
      return $rc;
    }
    // }}}
    
    // {{{ readEvent 
    /**
     * Handle a read-event
     * 
     * @access public
     * @return void
     **/  
    public final function readEvent () {
      foreach (inotify_read ($this->inotify) as $Event) {
        // Check if we have an event-handle for this watch-descriptor
        if (!isset (self::$inotifyEvents [$this->inotifyGeneration][$Event ['wd']])) {
          trigger_error ('Missing watch-descriptor for captured event');
          continue;
        }
        
        // Fire up the event
        if (self::$inotifyEvents [$this->inotifyGeneration][$Event ['wd']]->inotifyEvent ($Event ['mask'], $Event ['cookie'], $Event ['name']) === true)
          continue;
        
        // Directory-Events
        if ((($Event ['mask'] & self::MASK_MOVE_FROM) == self::MASK_MOVE_FROM) ||
            (($Event ['mask'] & self::MASK_REMOVE) == self::MASK_REMOVE))
          $this->directoryRemove ($Event ['name'], $Event ['cookie']);
        
        if ((($Event ['mask'] & self::MASK_MOVE_TO) == self::MASK_MOVE_TO) ||
            (($Event ['mask'] & self::MASK_CREATE) == self::MASK_CREATE))
          $this->directoryCreate ($Event ['name'], $Event ['cookie']);
        
        // File-Events
        if (($Event ['mask'] & self::MASK_ACCESS) == self::MASK_ACCESS)
          $this->fileAccess ($Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_MODIFY) == self::MASK_MODIFY)
          $this->fileModify ($Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_ATTRIB) == self::MASK_ATTRIB)
          $this->fileMetaChange ($Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_CLOSE_WRITE) == self::MASK_CLOSE_WRITE)
          $this->fileClose ($Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_CLOSE_READ) == self::MASK_CLOSE_READ)
          $this->fileClose ($Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_OPEN) == self::MASK_OPEN)
          $this->fileOpen ($Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_DELETE) == self::MASK_DELETE)
          $this->fileDelete ($Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_MOVE) == self::MASK_MOVE)
          $this->fileMove ($Event ['name'], $Event ['cookie']);
      }
    }
    // }}}
    
    // {{{ inotifyEvent
    /**
     * Capture an generic inotify-Event
     * 
     * @param int $Mask
     * @param int $Cookie
     * @param string $Name
     * 
     * @access protected
     * @return bool If TRUE no further don't fire up other callbacks
     **/
    protected function inotifyEvent ($Mask, $Cookie, $Name) { }
    // }}}
    
    // {{{ directoryCreate
    /**
     * A file was created in the watched directory (created or moved to)
     * 
     * @param string $Name
     * @param int $Cookie
     * 
     * @access protected
     * @return void
     **/
    protected function directoryCreate ($Name, $Cookie) { }
    // }}}
    
    // {{{ directoryRemove
    /**
     * A file was removed from the watched directory (deleted or moved away)
     * 
     * @param string $Name
     * @param int $Cookie
     * 
     * @access protected
     * @return void
     **/
    protected function directoryRemove ($Name, $Cookie) { }
    // }}}
    
    // {{{ fileAccess
    /**
     * The watched file was accessed
     *
     * @param string $Name
     * @param int $Cookie
     * 
     * @access protected
     * @return void
     **/
    protected function fileAccess ($Name, $Cookie) { }
    // }}}
    
    // {{{ fileModify
    /**
     * The watched file was modified
     * 
     * @param string $Name
     * @param int $Cookie 
     * 
     * @access protected
     * @return void
     **/
    protected function fileModify ($Name, $Cookie) { }
    // }}}
    
    // {{{ fileMetaChange
    /**
     * Meta-Data of watched file was changed
     * 
     * @param string $Name
     * @param int $Cookie 
     * 
     * @access protected
     * @return void
     **/
    protected function fileMetaChange ($Name, $Cookie) { }
    // }}}
    
    // {{{ fileClose
    /**
     * The watched file was closed
     * 
     * @param string $Name
     * @param int $Cookie 
     * 
     * @access protected
     * @return void
     **/
    protected function fileClose ($Name, $Cookie) { }
    // }}}
    
    // {{{ fileOpen
    /**
     * The watched file was opened
     *
     * @param string $Name
     * @param int $Cookie 
     * 
     * @access protected
     * @return void
     **/
    protected function fileOpen ($Name, $Cookie) { }
    // }}}
    
    // {{{ fileDelete
    /**
     * The watched file was deleted
     * 
     * @param string $Name
     * @param int $Cookie 
     * 
     * @access protected
     * @return void
     **/
    protected function fileDelete ($Name, $Cookie) { }
    // }}}
    
    // {{{ fileMove
    /**
     * The watched file was moved to another location
     * 
     * @param string $Name
     * @param int $Cookie 
     * 
     * @access protected
     * @return void
     **/
    protected function fileMove ($Name, $Cookie) { }
    // }}}
  }

?>