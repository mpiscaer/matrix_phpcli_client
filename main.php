#!/usr/bin/php
<?php

function Die_program_not_correct_parameter (){
  global $argc, $argv;
  echo $argv[0] . '  [sendmessage] room' . "\n";
  die (3);
}

function main() {
  global $argc, $argv;

  if (!file_exists("config.php"))
  {
    echo "File config.php doesn't exists, please configure this matrix client\n";
    die (10);
  }
  include_once("config.php");
  include_once("matrixlib.php");


  $matrix_client = new MatrixConnector($username, $password, $matrix_server);

  if (isset($argv[1])) {  
    switch ($argv[1]){
      case 'sendmessage':
          $room = $argv[2];
          $message = file_get_contents("php://stdin", "r");
          //var_dump($message);
          $result = $matrix_client->sendMessage($room, $message);

          if ($result)
          {
            echo "Message send\n";
          } else {
            echo "Message not send\n";
          }
        break;


      default:
        break;
    }
  }
}

main();