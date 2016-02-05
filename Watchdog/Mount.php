<?PHP

  /**
   * qcEvents - Watch the system's mount-points
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
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
  require_once ('qcEvents/Interface/Hookable.php');
  require_once ('qcEvents/Trait/Parented.php');
  require_once ('qcEvents/Trait/Hookable.php');
  
  class qcEvents_Watchdog_Mount implements qcEvents_Interface_Loop, qcEvents_Interface_Hookable {
    use qcEvents_Trait_Parented;
    use qcEvents_Trait_Hookable;
    
    private $fd = null;
    private $mounts = array ();
    
    // {{{ __construct
    /**
     * Create a new mountpoint-watchdog
     * 
     * @param qcEvents_Base $Base
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base) {
      // Try to open mounts
      if (!is_resource ($this->fd = fopen ('/proc/mounts', 'r')))
        throw new Exception ('Could not open mounts');
      
      // Read mounts initially
      $this->readMounts ();
      
      // Register the event-base
      $this->setEventBase ($Base);
    }
    // }}}
    
    // {{{ readMounts
    /**
     * Re-Read mountpoints and synchronize with our local state
     * 
     * @access private
     * @return void
     **/
    private function readMounts () {
      // Make sure our fd is valid
      if ($this->fd === null)
        return;
      
      // Seek back to BOF
      if (fseek ($this->fd, 0, SEEK_SET) != 0) {
        fclose ($this->fd);
        $this->fd = null;
        
        return;
      }
      
      // Re-Read mounts
      $Mounts = array ();
      $Added = array ();  
      $Changed = array ();
      
      while ($line = fgets ($this->fd)) {
        // Split up
        $Mount = explode (' ', $line);
        
        unset ($line);
        
        // Parse options
        $Opts = array ();
        
        foreach (explode (',', $Mount [3]) as $Opt)
          if (($p = strpos ($Opt, '=')) !== false)
            $Opts [substr ($Opt, 0, $p)] = substr ($Opt, $p + 1);
          else
            $Opts [$Opt] = true;
        
        // Create structure
        $Mounts [] = $Mount = array (
          'fs_spec' => str_replace ('\\040', ' ', $Mount [0]),
          'fs_file' => str_replace ('\\040', ' ', $Mount [1]),
          'fs_vfstype' => $Mount [2],
          'fs_mntops' => $Opts,
        );
        
        // Check if this is known
        foreach ($this->mounts as $id=>$oMount)
          // Check if source, destination and fstype match
          if (($oMount ['fs_spec'] == $Mount ['fs_spec']) &&
              ($oMount ['fs_file'] == $Mount ['fs_file']) &&
              ($oMount ['fs_vfstype'] == $Mount ['fs_vfstype'])) {
            
            unset ($this->mounts [$id]);
            
            if (count ($oMount ['fs_mntops']) == count ($Mount ['fs_mntops'])) {
              foreach ($oMount ['fs_mntops'] as $mid=>$val)
                if (!isset ($Mount ['fs_mntops'][$mid]) || ($Mount ['fs_mntops'][$mid] != $val)) {
                  $Changed [] = $Mount;
                  
                  continue (2);
                } else
                  unset ($oMount ['fs_mntops'][$mid]);
              
              if (count ($oMount ['fs_mntops']) != 0)
                $Changed [] = $Mount;
            } else
              $Changed [] = $Mount;
            
            continue (2);
          }
        
        // If we get here the mount is added
        $Added [] = $Mount;
      }
      
      // All remaining mountpoints were removed
      $Removed = $this->mounts;
      
      // Store new mounts
      $this->mounts = $Mounts;
      
      // Fire callbacks
      foreach ($Removed as $Mount)
        $this->___callback ('mountpointRemoved', $Mount ['fs_spec'], $Mount ['fs_file'], $Mount ['fs_vfstype'], $Mount ['fs_mntops']);
      
      foreach ($Added as $Mount)
        $this->___callback ('mountpointAdded', $Mount ['fs_spec'], $Mount ['fs_file'], $Mount ['fs_vfstype'], $Mount ['fs_mntops']);
      
      foreach ($Changed as $Mount)
        $this->___callback ('mountpointChanged', $Mount ['fs_spec'], $Mount ['fs_file'], $Mount ['fs_vfstype'], $Mount ['fs_mntops']);
      
      $this->___callback ('mountpointsRead', $this->mounts);
    }
    // }}}
    
    // {{{ getReadFD
    /**
     * Retrive the FD-handle to watch for read-events (none in this implementation)
     * 
     * @access public
     * @return void
     **/
    public function getReadFD () { return null; }
    // }}}
    
    // {{{ getWriteFD
    /**
     * Retrive the FD-handle to watch for write-events and errors
     * 
     * @access public
     * @return resource
     **/
    public final function getWriteFD () { return $this->fd; }
    // }}}
    
    // {{{ raiseRead
    /**
     * Callback: Our event-base caught an read-event for our FD
     * 
     * @access public
     * @return void
     **/
    public function raiseRead () { }
    // }}}
    
    // {{{ raiseWrite
    /**
     * Callback: Our event-base caught an write-event for our FD
     * 
     * @access public
     * @return void
     **/
    public function raiseWrite () { }
    // }}}
    
    // {{{ raiseError
    /**
     * Callback: Our event-base caught an error-event for our FD
     * 
     * @access public
     * @return void
     **/
    public final function raiseError () { $this->readMounts (); }
    // }}}
    
    
    // {{{ mountpointsRead
    /**
     * Callback: Mountpoints were re-read
     * 
     * @param array $Mountpoints
     * 
     * @access protected
     * @return void
     **/
    protected function mountpointsRead (array $Mountpoints) { }
    // }}}
    
    // {{{ mountpointAdded
    /**
     * Callback: A mountpoit was added to our namespace
     * 
     * @param string $fsSpec
     * @param string $fsFile
     * @param string $fsType
     * @param array $fsOpts
     * 
     * @access protected
     * @return void
     **/
    protected function mountpointAdded ($fsSpec, $fsFile, $fsType, array $fsOpts) { }
    // }}}
    
    // {{{ mountpointChanged
    /**
     * Callback: Options ($fsOpts) of a mountpoint were changed
     * 
     * @param string $fsSpec
     * @param string $fsFile
     * @param string $fsType
     * @param array $fsOpts
     *  
     * @access protected
     * @return void
     **/
    protected function mountpointChanged ($fsSpec, $fsFile, $fsType, array $fsOpts) { }
    // }}}
    
    // {{{ mountpointRemoved
    /**
     * Callback: A mountpoint was removed from our namespace
     * 
     * @param string $fsSpec
     * @param string $fsFile
     * @param string $fsType
     * @param array $fsOpts
     *  
     * @access protected
     * @return void
     **/
    protected function mountpointRemoved ($fsSpec, $fsFile, $fsType, array $fsOpts) { }
    // }}}
  }

?>