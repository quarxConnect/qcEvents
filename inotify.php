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
  
  require_once ('qcEvents/Interface/Loop.php');
  require_once ('qcEvents/Trait/Parented.php');
  
  /**
   * inotify
   * -------
   * Event-Handler to monitor inode-changes
   * 
   * @class qcEvents_inotify
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_inotify implements qcEvents_Interface_Loop {
    use qcEvents_Trait_Parented;
    
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
    
    // {{{ getWatchedPath
    /**
     * Retrive the path being watched
     * 
     * @access public
     * @return string
     **/
    public function getWatchedPath () {
      return $this->inotifyPath;
    }
    // }}}
    
    // {{{ setWatchedPath
    /**
     * Change the path being watched
     * 
     * @param string $Path
     * 
     * @access public
     * @return bool
     **/
    public function setWatchedPath ($Path) {
      // Make sure the path exists
      if (!file_exists ($Path))
        return false;
      
      // Unregister any current watcher
      if ($this->inotifyDescriptor !== null)
        inotify_rm_watch ($this->inotify, $this->inotifyDescriptor);
      
      // Change the path
      $this->inotifyPath = $Path;
      
      // Register again (if possible)
      if (is_resource ($this->inotify))
        $this->inotifyDescriptor = inotify_add_watch ($this->inotify, $this->inotifyPath, $this->inotifyMask);
      
      return true;
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
      return $this->inotify;
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
      return null;
    }
    // }}}
    
    // {{{ scanCreated
    /**
     * Scan a watched directory for dirs and files and fake callback-events
     * 
     * @access public
     * @return void
     **/
    public function scanCreated () {
      // Make sure this is a directory
      if (!is_dir ($this->inotifyPath))
        return;
      
      // Open Directory
      if (!is_object ($d = dir ($this->inotifyPath)))
        return;
      
      while ($f = $d->read ())
        if (($f != '.') && ($f != '..')) {
          if ($this->___callback ('inotifyEvent', self::MASK_CREATE, 0, $f) === true)
            continue;
          
          $this->___callback ('directoryCreate', $f, 0);
        }
      
      $d->close ();
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Set a new event-loop-handler
     * 
     * @param qcEvents_Base $Base
     * 
     * @access public
     * @return void  
     **/
    public function setEventBase (qcEvents_Base $Base) {
      // Save our event-base
      $oEventBase = $this->getEventBase ();
      
      // Call our parent first
      if (!($rc = parent::setEventBase ($Base)))
        return $rc;
      
      // Don't do anything if the handler didn't change
      if ($oEventBase === $Handler)
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
      
      // Check if our target exists
      if (!file_exists ($this->inotifyPath)) {
        trigger_error ('Watched filesystem-object does not exist: ' . $this->inotifyPath);
        
        return $rc;
      }
      
      // Find a new inotify
      foreach ($Base->getEvents () as $Event)
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
      
      // Register our watch
      $this->inotifyDescriptor = inotify_add_watch ($this->inotify, $this->inotifyPath, $this->inotifyMask);
      self::$inotifyEvents [$this->inotifyGeneration][$this->inotifyDescriptor] = $this;
      
      return $Base->updateEvent ($this);
    }
    // }}}
    
    // {{{ raiseRead
    /**
     * Handle a read-event
     * 
     * @access public
     * @return void
     **/  
    public final function raiseRead () {
      foreach (inotify_read ($this->inotify) as $Event) {
        // Check if we have an event-handle for this watch-descriptor
        if (!isset (self::$inotifyEvents [$this->inotifyGeneration][$Event ['wd']])) {
          trigger_error ('Missing watch-descriptor for captured event');
          
          continue;
        }
        
        // Fire up the event
        $H = self::$inotifyEvents [$this->inotifyGeneration][$Event ['wd']];
        
        if ($H->___callback ('inotifyEvent', $Event ['mask'], $Event ['cookie'], $Event ['name']) === true)
          continue;
        
        // Directory-Events
        if ((($Event ['mask'] & self::MASK_MOVE_FROM) == self::MASK_MOVE_FROM) ||
            (($Event ['mask'] & self::MASK_REMOVE) == self::MASK_REMOVE))
          $H->___callback ('directoryRemove', $Event ['name'], $Event ['cookie']);
        
        if ((($Event ['mask'] & self::MASK_MOVE_TO) == self::MASK_MOVE_TO) ||
            (($Event ['mask'] & self::MASK_CREATE) == self::MASK_CREATE))
          $H->___callback ('directoryCreate', $Event ['name'], $Event ['cookie']);
        
        // File-Events
        if (($Event ['mask'] & self::MASK_ACCESS) == self::MASK_ACCESS)
          $H->___callback ('fileAccess', $Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_MODIFY) == self::MASK_MODIFY)
          $H->___callback ('fileModify', $Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_ATTRIB) == self::MASK_ATTRIB)
          $H->___callback ('fileMetaChange', $Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_CLOSE_WRITE) == self::MASK_CLOSE_WRITE)
          $H->___callback ('fileClose', $Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_CLOSE_READ) == self::MASK_CLOSE_READ)
          $H->___callback ('fileClose', $Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_OPEN) == self::MASK_OPEN)
          $H->___callback ('fileOpen', $Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_DELETE) == self::MASK_DELETE)
          $H->___callback ('fileDelete', $Event ['name'], $Event ['cookie']);
        
        if (($Event ['mask'] & self::MASK_MOVE) == self::MASK_MOVE)
          $H->___callback ('fileMove', $Event ['name'], $Event ['cookie']);
      }
    }
    // }}}
    
    // {{{ raiseWrite
    /**
     * Callback: The Event-Loop detected a write-event
     * 
     * @access public
     * @return void  
     **/
    public function raiseWrite () { }
    // }}}
    
    // {{{ raiseError
    /**
     * Callback: The Event-Loop detected an error-event
     * 
     * @access public
     * @return void  
     **/
    public function raiseError () { }
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