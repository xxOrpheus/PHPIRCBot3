<?php

/**
 * PHPIRCBot
 *
 * PHP version 5
 *
 * Copyright (c) 2013, David Harris
 * All rights reserved.
 * Redistribution and use in source and binary forms,
 * with or without modification, are permitted provided
 * that the following conditions are met:
 * Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 * Neither the name of the <ORGANIZATION> nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 * 
 * @category  Utility
 * @package   IRCBot
 * @author    David Harris <lolidunno@live.co.uk>
 * @copyright 2013-2013 David Harris
 * @license   http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version   GIT: $Id$
 * @link      https://github.com/xxOrpheus/PHPIRCBot3
 */

/**
 * PHPIRCBot3
 * 
 * @category  Utility
 * @package   IRCBot
 * @author    David Harris <lolidunno@live.co.uk>
 * @copyright 2013-2013 David Harris
 * @license   http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version   Release: 2.0
 * @link      https://github.com/xxOrpheus/PHPIRCBot3
 */

session_start();
chdir(dirname(__FILE__));
date_default_timezone_set('America/Vancouver');

class IRCBot {
    protected $IRC_SOCKET;
    protected $IRC_DATA;
    protected $IRC_DATA_ARGS;
    protected $IRC_ARGS = array();
    
    protected $IRCBOT_MODULES = array();
    protected $EVENT_HANDLERS;

    
    /** 
     * The contructor. Can be sent an array, which will fill the IRC arguments.
     * 
     * @param array $args IRC arguments. Channel, server, port etc all can be set here..
     *
     */
    public function __construct(array $args = array(), $dir = null) {
        if (count($args) > 0) {
            $this->IRC_ARGS = array_merge($args, $this->IRC_ARGS);
        } else {
            $default = array(
                'IRC_PORT' => 6667,
                'IRC_NICK' => 'PHPIRCBot',
                'IRC_USER' => 'PHPIRCBot',
                'OWNER' => 'PHPIRCBot', 
                'auto-reconnect' => false,
                'verbose' => false
            );
            $this->IRC_ARGS = array_merge($default, $this->IRC_ARGS);
        }

        if(!is_dir('modules')) {
            mkdir('modules');
        }
        $this->loadModules($dir);
    }
    
    /**
     * All work is done here. All handlers, modules etc will be executed here.
     *
     */
    public function start() {
        $this->IRC_SOCKET = @fsockopen($this->getArg('IRC_SERVER'), intval($this->getArg('IRC_PORT')), $SOCK_ERR_NUM, $SOCK_ERR_STR, 5);
        if (!$this->IRC_SOCKET) {
            Throw new Exception($SOCK_ERR_STR);
        }
        $this->sendCommand('USER ' . $this->getArg('IRC_USER') . ' 0 * ' . $this->getArg('IRC_USER'));
        $this->sendCommand('NICK ' . $this->getArg('IRC_NICK'));
        while ($this->IRC_SOCKET) {
            $this->IRC_DATA = fgets($this->IRC_SOCKET, 1024);
            $this->IRC_DATA_ARGS = $this->parseMessage($this->IRC_DATA);
            $this->handleCommand($this->IRC_DATA_ARGS['command']);
            if (substr($this->IRC_DATA_ARGS['trail'], 0, 1) == '!') {
                $this->CURRENT_COMMAND = preg_replace('/(\s*)([^\s]*)(.*)/', '$2', $this->IRC_DATA_ARGS['trail']);
                $this->handleModule(substr($this->CURRENT_COMMAND, 1));
            }
            usleep(10000);
        }
        if($this->getArg('auto-reconnect') == true) {
        	$this->start();
    	}
    }
    
    /** 
     * Returns the result set. Mainly used by modules.
     * 
     * @return array
     *
     */
    
    public function getResultSet() {
        return $this->IRC_DATA_ARGS;
    }
    
    /**
     * Sends a command to the active server.
     * 
     * @param string $cmd The command to be sent.
     *
     */
    public function sendCommand($cmd) {
        if (!$this->IRC_SOCKET) {
            Throw new Exception('No connection opened');
        }
        fwrite($this->IRC_SOCKET, $cmd . "\r\n");
        fflush($this->IRC_SOCKET);
        return true;
    }
    
    /**
     * Alias for sendCommand(.....), sends a message to a user or channel.
     * 
     * @param  string $msg The message being sent.
     * @param  string $chanuser User or channel. If set to false, will default to channel origin.
     * @return null
     * 
     */
    public function sendMessage($msg, $user = false) {
        if ($user == false) {
            $user = $this->IRC_DATA_ARGS['args'];
        }
        $this->sendCommand('PRIVMSG ' . $user . ' :' . $msg);
    }
    
    /**
     * Parse data sent by server.
     * 
     * @param  string $str The data received by server.
     * @return array
     *
     */
    public function parseMessage($str) {
        preg_match('/^(?:[:@]([^\\s]+) )?([^\\s]+)(?: ((?:[^:\\s][^\\s]* ?)*))?(?: ?:(.*))?$/', $str, $args);
        if (isset($args[1])) {
            if (strrpos($args[1], '!')) {
                $username = substr($args[1], 0, strrpos($args[1], '!'));
            } else {
                $username = $args[1];
            }
        } else {
            $username = '';
        }
        if (isset($args[3])) {
            $isPM = trim(strtolower($args[3])) == strtolower($this->IRC_ARGS['IRC_NICK']) ? true : false;
        } else {
            $isPM = false;
        }
        return array(
            'username' => $username,
            'command' => isset($args[2]) ? $args[2] : '',
            'trail' => isset($args[4]) ? trim($args[4]) : '',
            'args' => isset($args[3]) ? $args[3] : '',
            'isPM' => $isPM
        );
    }
    
    /**
     * Load modules in specified directory.
     * 
     * @param string $dir The directory to be searched for modules.
     *
     */
    public function loadModules($dir = null) {
        if (is_null($dir)) {
            $dir = dirname(__FILE__) . '\\modules';
        } else if (!is_dir($dir)) {
            Throw new Exception($dir . ' is not a valid directory');
        }
        $modules = glob($dir . '\\*.php');
        foreach ($modules as $module) {
            $module_name = basename($module, '.php');
            if (!class_exists($module_name)) {
                require_once($module);
            } else {
                echo 'Module "' . $module_name . '"" already loaded!' . PHP_EOL;
            }

            if (class_exists($module_name)) {
                $this->IRCBOT_MODULES[strtolower($module_name)] = new $module_name($this);
            } else {
                echo 'Module "' . $dir . '\\' . $module_name . '.php" could not be loaded! Class name must match that of the filename!' . PHP_EOL;
            }
        }
    }
    
    /**
     * Get active modules.
     * 
     * @return Array of objects.
     *
     */
    public function getModules() {
        return $this->IRCBOT_MODULES;
    }
    
    /**
     * Handle command sent by server.
     * 
     * @param string $command The command
     *
     */
    public function handleCommand($command) {
        if ($command == 'PING') {
            $this->sendCommand('PONG ' . substr($this->IRC_DATA, 5));
        }
        $handled = false;
        if(isset($this->EVENT_HANDLERS[$command])) {
            foreach ($this->EVENT_HANDLERS[$command] as $func) {
                $handled = true;
                $func($this);
            }
        }
        foreach ($this->IRCBOT_MODULES as $mod) {
            if (method_exists($mod, 'EVENT_' . $command)) {
                $m = 'EVENT_' . $command;
                $mod->$m($this->getResultSet());
                $handled = true;
            } else if(method_exists($mod, 'EVENT_UNHANDLED')) {
                $mod->EVENT_UNHANDLED($this->getResultSet());
            }
        }
        if(!$handled) {
            $ds = $this->getResultSet();
            if(!empty($ds['username']) || !empty($ds['command']) || !empty($ds['trail'])) {
                if(strtolower($ds['command']) != "ping") {
                    $e = (!empty($ds['args']) && strtolower($ds['args']) != $ds['args'] ? trim($ds['args']) : '');
                    echo '[' . date('h:i') . ']: ' . trim($ds['trail']) . PHP_EOL;
                }
            }
        }
        return true;
    }
    
    /**
     * Get the current command. (!thesethings)
     * 
     * @return string Current command
     *
     */
    public function getCurrentCommand() {
        return $this->CURRENT_COMMAND;
    }
    
    /**
     * Handle a module.
     * 
     * @param string $mod The module.
     *
     */
    public function handleModule($mod) {
    	$mod = strtolower($mod);
        if (isset($this->IRCBOT_MODULES[$mod])) {
            $args = $this->IRC_DATA_ARGS['trail'];
            $args = substr($args, strlen($this->CURRENT_COMMAND) + 1);
            if ($args != false) {
                $args = explode(" ", $args);
            } else {
                $args = array();
            }
            $mod = $this->IRCBOT_MODULES[$mod];
            $methods = array(
                'pre_execute',
                'execute',
                'post_execute'
            ); // the order of this array is very important!
            $ds = $this->getResultSet();
            foreach ($methods as $method) {
                if (method_exists($mod, $method)) {
                    $mod->$method($this, $args, $ds);
                }
            }
            return true;
        } else
            return false;
    }
    
    /**
     * Add a handler for a command.
     * 
     * @param  string $command The command to handle.
     * @param  closure $func The callback for the command.
     * @return int The ID of the handle.
     *
     */
    public function addHandler($command, closure $func) {
        if (!isset($this->EVENT_HANDLERS[$command])) {
            $this->EVENT_HANDLERS[$command] = array();
        }
        $this->EVENT_HANDLERS[$command][] = $func;
        
        return key(end($this->EVENT_HANDLERS));
    }
    
    /**
     * Remove a handler.
     * 
     * @param string $command The command
     * @param int $id The index of the handle. It is returned when you add a new handler.
     *
     */
    public function removeHandler($command, $id) {
        if (!isset($this->EVENT_HANDLERS[$command][$id])) {
            return false;
        }
        unset($this->EVENT_HANDLERS[$command][$id]);
        
        return true;
    }
    
    /**
     * Check if username is the owner of the bot
     *
     * @param string $user The username in question
     * @return boolean
     *
     */
    public function isOwner($user) {
        return $this->IRC_ARGS['OWNER'] == $user;
    }
    
    /**
     * Set an argument for the IRC Bot.
     * 
     * @param string $arg The argument we're setting
     * @param string $value and the value it's set to.
     *
     */
    public function setArg($arg, $value) {
        $this->IRC_ARGS[$arg] = $value;
        return $this->IRC_ARGS[$arg];
    }
    
    /**
     * Get an argument value.
     * 
     * @return mixed
     *
     */
    public function getArg($arg) {
        if (isset($this->IRC_ARGS[$arg])) {
            return $this->IRC_ARGS[$arg];
        }
        return null;
    }
    
    /**
     * Behaves like file_get_contents(...)
     * 
     * @return string The contents it retrieves.
     * @param  string $url The URL to retrieve
     * 
     */
    public function file_get_contents_2($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; rv:16.0) Gecko/20100101 Firefox/16.0',
            CURLOPT_REFERER => $url
        ));
        $res = curl_exec($ch);
        
        return $res;
    }
}
?>
