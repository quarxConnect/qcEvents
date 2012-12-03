<?PHP

  /**
   * qcEvents - Watchdog for long running MySQL Queries
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
  
  require_once ('qcEvents/Timer.php');
  
  /**
   * MySQL-Query Watchdog
   * --------------------
   * Watch queries on a mysql-server and kill long-running queries
   * 
   * @class qcEvents_Watchdog_MySQL_Query
   * @extends qcEvents_Timer
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Watchdog_MySQL_Query extends qcEvents_Timer {
    // MySQL-Connection
    private $DB = null;
    
    // Maximum execution-time for MySQL-Queries
    private $Limit = 120;
    
    // Ignored users
    private $ignoreUsers = array ('root');
    
    // {{{ __construct
    /**
     * Create a new mySQL-Query-Watchdog
     * 
     * @param int $Interval (optional) Check-Interval (default: 10)
     * @param int $Limt (optional) Time-Limit for MySQL-Queries (default: 120)
     * @param string $Host (optional) MySQL-Server
     * @param string $User (optional) MySQL-User
     * @param string $Password (optional) MySQL-Password
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Interval = null, $Limit = null, $Host = null, $User = null, $Password = null) {
      // Validate parameters
      if ($Interval === null)
        $Interval = 10;
      else
        $Interval = intval ($Interval);
      
      if ($Limit === null)
        $Limit = 120;
      else
        $Limit = intval ($Limit);
      
      // Setup ourself
      $this->Limit = $Limit;
      $this->DB = mysqli_init ();
      
      if (!$this->DB->real_connect ($Host, $User, $Password))
        return false;
      
      ini_set ('mysqli.reconnect', '1');
      
      // Setup our parent
      parent::__construct ($Interval, true);
    }
    // }}}
    
    // {{{ timerEvent
    /**
     * Check if any mySQL-Query is over limit
     * 
     * @access public
     * @return void
     **/
    public function timerEvent () {
      // Load processlist from Server
      if (!$this->DB->ping ())
        return false;
      
      if (!($List = $this->DB->query ('SHOW PROCESSLIST')))
        return false;
      
      // Check all processes
      while ($Process = $List->fetch_array (MYSQLI_ASSOC)) {
        // Check if the query is over limit
        if ($Process ['Time'] < $this->Limit)
          continue;
        
        // Check if this is a query or just an idle client
        if ($Process ['Command'] == 'Sleep')
          continue;
        
        // Check wheter to ignore this user
        if (in_array ($Process ['User'], $this->ignoreUsers))
          continue;
        
        // Stop the query
        $this->DB->query ('KILL QUERY ' . $Process ['Id']);
      }
      
      $List->free ();
    }
    // }}}
  }

?>