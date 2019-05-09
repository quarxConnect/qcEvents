<?PHP

  /**
   * qcEvents - Watchdog for long running MySQL Queries
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
  
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Trait/Parented.php');
  require_once ('qcEvents/Client/MySQL.php');
  
  /**
   * MySQL-Query Watchdog
   * --------------------
   * Watch queries on a mysql-server and kill long-running queries
   * 
   * @class qcEvents_Watchdog_MySQL_Query
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Watchdog_MySQL_Query extends qcEvents_Hookable {
    use qcEvents_Trait_Parented;
    
    // MySQL-Connection
    private $Client = null;
    
    // Maximum execution-time for MySQL-Queries
    private $Limit = 120;
    
    // Ignored users
    private $ignoreUsers = array ('root');
    
    // Duty-indicator
    private $Busy = false;
    
    // {{{ __construct
    /**
     * Create a new mySQL-Query-Watchdog
     * 
     * @param qcEvents_Base $Base (optional)
     * @param int $Interval (optional) Check-Interval (default: 10)
     * @param int $Limt (optional) Time-Limit for MySQL-Queries (default: 120)
     * @param string $Host (optional) MySQL-Server
     * @param string $User (optional) MySQL-User
     * @param string $Password (optional) MySQL-Password
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base, $Interval = null, $Limit = null, $Host = null, $User = null, $Password = null) {
      $this->setEventBase ($Base);
      
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
      $this->Client = new qcEvents_Client_MySQL ($Base);
      
      $this->Client->connect ($Host, 3306, $User, $Password, 1)->then (
        function () use ($Base, $Interval) {
          $Base->addTimeout ($Interval, true)->then (
            // Check if we are busy right now
            if ($this->Busy)
              return;
            
            // Mark ourself as busy
            $this->Busy = true;
            
            // Run the query
            $this->Client->query ('SHOW PROCESSLIST')->then (
              function ($Rows) {
                foreach ($Rows as $Process) {
                  // Check if the query is over limit
                  if ($Process ['Time'] < $this->Limit)
                    return;
                  
                  // Check if this is a query or just an idle client
                  if ($Process ['Command'] == 'Sleep')
                    continue;
                  
                  // Check wheter to ignore this user
                  if (in_array ($Process ['User'], $this->ignoreUsers))
                    continue;
                  
                  // Stop the query
                  $Client->exec ('KILL QUERY ' . $Process ['Id']);
                }
              }
            )->finally (
              function () {
                // Remove the busy-status when finished
                $this->Busy = false;
              }
            );
          );
        }
      );
      
      $this->Client->addHook ('mysqlDisconnected', function () use ($Host, $User, $Password) {
        $this->Client->connect ($Host, 3306, $User, $Password, 1);
      });
    }
    // }}}
  }

?>