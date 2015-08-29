<?php

//
// RouterOS API class
// Author: Denis Basta
//
// read() function altered by Nick Barnes to take into account the placing
// of the "!done" reply and also correct calculation of the reply length.
//

class routeros_api {

	var $debug = false;			// Show debug information
	var $error_no;				// Variable for storing connection error number, if any
	var $error_str;				// Variable for storing connection error text, if any

	var $attempts = 5;			// Connection attempt count
	var $connected = false;		// Connection state
	var $delay = 3;				// Delay between connection attempts in seconds
	var $port = 8728;			// Port to connect to
	var $timeout = 3;			// Connection attempt timeout and data read timeout

	var $socket;				// Variable for storing socket resource

	/**************************************************
	 *
	 *************************************************/

	function debug($text) {

		if ($this->debug)
			echo $text . "\n";

	}

	/**************************************************
	 *
	 *************************************************/

	function encode_length($length) {

		if ($length < 0x80) {

			$length = chr($length);

		}
		else
		if ($length < 0x4000) {

			$length |= 0x8000;

			$length = chr( ($length >> 8) & 0xFF) . chr($length & 0xFF);

		}
		else
		if ($length < 0x200000) {

			$length |= 0xC00000;

			$length = chr( ($length >> 8) & 0xFF) . chr( ($length >> 8) & 0xFF) . chr($length & 0xFF);

		}
		else
		if ($length < 0x10000000) {

			$length |= 0xE0000000;

			$length = chr( ($length >> 8) & 0xFF) . chr( ($length >> 8) & 0xFF) . chr( ($length >> 8) & 0xFF) . chr($length & 0xFF);

		}
		else
		if ($length >= 0x10000000)
			$length = chr(0xF0) . chr( ($length >> 8) & 0xFF) . chr( ($length >> 8) & 0xFF) . chr( ($length >> 8) & 0xFF) . chr($length & 0xFF);

		return $length;

	}

	/**************************************************
	 *
	 *************************************************/

	function connect($ip, $login, $password) {

		for ($ATTEMPT = 1; $ATTEMPT <= $this->attempts; $ATTEMPT++) {

			$this->connected = false;

			$this->debug('Connection attempt #' . $ATTEMPT . ' to ' . $ip . ':' . $this->port . '...');

			if ($this->socket = @fsockopen($ip, $this->port, $this->error_no, $this->error_str, $this->timeout) ) {

				socket_set_timeout($this->socket, $this->timeout);

				$this->write('/login');

				$RESPONSE = $this->read(false);

				if ($RESPONSE[0] == '!done') {

					if (preg_match_all('/[^=]+/i', $RESPONSE[1], $MATCHES) ) {

						if ($MATCHES[0][0] == 'ret' && strlen($MATCHES[0][1]) == 32) {

							$this->write('/login', false);
							$this->write('=name=' . $login, false);
							$this->write('=response=00' . md5(chr(0) . $password . pack('H*', $MATCHES[0][1]) ) );

							$RESPONSE = $this->read(false);

							if ($RESPONSE[0] == '!done') {

								$this->connected = true;

								break;

							}

						}

					}

				}

				fclose($this->socket);

			}

			sleep($this->delay);

		}

		if ($this->connected)
			$this->debug('Connected...');
		else
			$this->debug('Error...');

		return $this->connected;

	}

	/**************************************************
	 *
	 *************************************************/

	function disconnect() {

		fclose($this->socket);

		$this->connected = false;

		$this->debug('Disconnected...');

	}

	/**************************************************
	 *
	 *************************************************/

	function parse_response($response) {

		if (is_array($response) ) {

			$PARSED = array();
			$CURRENT = null;

			for ($i = 0, $imax = count($response); $i < $imax; $i++) {

				if (in_array($response[$i], array('!fatal', '!re', '!trap') ) ) {

					if ($response[$i] == '!re')
						$CURRENT = &$PARSED[];
					else
						$CURRENT = &$PARSED[$response[$i]][];

				}
				else
				if ($response[$i] != '!done') {

					if (preg_match_all('/[^=]+/i', $response[$i], $MATCHES) )
						$CURRENT[$MATCHES[0][0]] = (isset($MATCHES[0][1]) ? $MATCHES[0][1] : '');

				}

			}

			return $PARSED;

		}
		else
			return array();

	}

	/**************************************************
	 *
	 *************************************************/

   function read($parse = true) {

      $RESPONSE = array();

      while (true) {

         // Read the first byte of input which gives us some or all of the length
         // of the remaining reply.
         $BYTE = ord(fread($this->socket, 1) );
         $LENGTH = 0;
         
         echo "$BYTE\n";

         // If the first bit is set then we need to remove the first four bits, shift left 8
         // and then read another byte in.
         // We repeat this for the second and third bits.
         // If the fourth bit is set, we need to remove anything left in the first byte
         // and then read in yet another byte.
         if ($BYTE & 128) {
            if (($BYTE & 192) == 128) {
               $LENGTH = (($BYTE & 63) << 8 ) + ord(fread($this->socket, 1)) ;
            } else {
               if (($BYTE & 224) == 192) {
                  $LENGTH = (($BYTE & 31) << 8 ) + ord(fread($this->socket, 1)) ;
                  $LENGTH = ($LENGTH << 8 ) + ord(fread($this->socket, 1)) ;
               } else {
                  if (($BYTE & 240) == 224) {
                     $LENGTH = (($BYTE & 15) << 8 ) + ord(fread($this->socket, 1)) ;
                     $LENGTH = ($LENGTH << 8 ) + ord(fread($this->socket, 1)) ;
                     $LENGTH = ($LENGTH << 8 ) + ord(fread($this->socket, 1)) ;
                  } else {
                     $LENGTH = ord(fread($this->socket, 1)) ;
                     $LENGTH = ($LENGTH << 8 ) + ord(fread($this->socket, 1)) ;
                     $LENGTH = ($LENGTH << 8 ) + ord(fread($this->socket, 1)) ;
                     $LENGTH = ($LENGTH << 8 ) + ord(fread($this->socket, 1)) ;
                  }
               }
            }
         } else {
            $LENGTH = $BYTE;
         }

         // If we have got more characters to read, read them in.
         if ($LENGTH > 0) {
            $_ = "";
            $retlen=0;
            while ($retlen < $LENGTH) {
               $toread = $LENGTH - $retlen ;
               $_ .= fread($this->socket, $toread);
               $retlen = strlen($_);
            }
            $RESPONSE[] = $_ ;
            $this->debug('>>> [' . $retlen . '/' . $LENGTH . ' bytes read.');
         }

         // If we get a !done, make a note of it.
         if ($_ == "!done")
            $receiveddone=true;

         $STATUS = socket_get_status($this->socket);

         
         if ($LENGTH > 0)
            $this->debug('>>> [' . $LENGTH . ', ' . $STATUS['unread_bytes'] . '] ' . $_);

         if ( (!$this->connected && !$STATUS['unread_bytes']) ||
            ($this->connected && !$STATUS['unread_bytes'] && $receiveddone) )
            break;

      }

      if ($parse)
         $RESPONSE = $this->parse_response($RESPONSE);

      return $RESPONSE;

   }
	/**************************************************
	 *
	 *************************************************/

	function write($command, $param2 = true) {

		if ($command) {

			fwrite($this->socket, $this->encode_length(strlen($command) ) . $command);

			$this->debug('<<< [' . strlen($command) . '] ' . $command);

			if (gettype($param2) == 'integer') {

				fwrite($this->socket, $this->encode_length(strlen('.tag=' . $param2) ) . '.tag=' . $param2 . chr(0) );

				$this->debug('<<< [' . strlen('.tag=' . $param2) . '] .tag=' . $param2);

			}
			else
			if (gettype($param2) == 'boolean')
				fwrite($this->socket, ($param2 ? chr(0) : '') );

			return true;

		}
		else
			return false;

	}

}

?>
