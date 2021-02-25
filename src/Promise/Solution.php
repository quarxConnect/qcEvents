<?php

  /**
   * quarxConnect Events - Promise Solution
   * Copyright (C) 2018-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
   * along with this program. If not, see <http://www.gnu.org/licenses/>.
   **/
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events\Promise;
  
  class Solution extends \Exception {
    /* All parameters for this solution */
    private $solutionParameters = [ ];
    
    // {{{ __construct
    /**
     * Create a multi-parameter promise-solution
     * 
     * @param array $solutionParameters
     * 
     * @access friendly
     * @return void
     **/
    function __construct (array $solutionParameters) {
      $this->solutionParameters = $solutionParameters;
    }
    // }}}
    
    // {{{ getParameters
    /**
     * Retrive all parameters for this solution
     * 
     * @access public
     * @return array
     **/
    public function getParameters () : array {
      return $this->solutionParameters;
    }
    // }}}
  }
