<?php

class MatrixConnector
{
  private $matrix_server;
  private $matrix_user;
  private $matrix_password;
  private $access_token;
  private $refresh_token;
  private $userid;
  private $deviceid;
  private $next_batch;


  public function __construct($user, $password, $server)
  {
    $this->matrix_server = $server;
    $this->matrix_user = $user;
    $this->matrix_password = $password;

    $this->initialEnvironment();

    $this->matrixSync();
  }


  // Private functions

  private function initialEnvironment() 
  {
    $this->matrixDB = new SQLite3('db/matrix.db');

    $this->matrixDB->exec('
      CREATE TABLE IF NOT EXISTS properties
         (
            key TEXT NOT NULL PRIMARY KEY,
            value INT NOT NULL
         );
      ');

    $this->matrixDB->exec('
      CREATE TABLE IF NOT EXISTS roomsAliases
         (
            ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            roomId TEXT    NOT NULL,
            roomAlias INT NOT NULL
         );
      ');

    $this->matrixDB->exec('
      CREATE TABLE IF NOT EXISTS rooms
         (
            roomId TEXT NOT NULL PRIMARY KEY,
            type TEXT    NOT NULL DEFAULT "default",
            name TEXT
         );
      ');

    $this->matrixDB->exec('
      CREATE TABLE IF NOT EXISTS messages
         (
            eventID TEXT NOT NULL PRIMARY KEY,
            roomId TEXT NOT NULL,
            sender TEXT NOT NULL,
            message TEXT NOT NULL,
            timestamp INT
         );
      ');

    $properties = $this->matrixDB->query('
      SELECT key, value FROM properties WHERE key = "userid" or key = "deviceid" or key = "refresh_token" or key = "access_token";
    ');

    $properties = $properties->fetchArray(SQLITE3_ASSOC);

    if ($properties) {
      $properties = $this->matrixDB->query('
        SELECT key, value FROM properties WHERE key = "userid" or key = "deviceid" or key = "refresh_token" or key = "access_token";
      ');

      while ($entry = $properties->fetchArray(SQLITE3_ASSOC)) {
        switch ($entry['key']) {
          case 'access_token':
            $this->access_token = $entry['value'];
            break;
          case 'refresh_token':
            $this->refresh_token = $entry['value'];
            break;
          case 'userid':
            $this->userid = $entry['value'];
            break;
          case 'deviceid':
            $this->deviceid = $entry['value'];
            break;
        }
      }
    } else {
      $this->loginToMatrixServer();
      $this->updateDeviceInformation($this->userid, $this->deviceid, $this->refresh_token, $this->access_token);
    }
  }

  private function loginToMatrixServer()
  {
    $ch=curl_init();
    $query_data['type'] = "m.login.password";
    $query_data['user'] = $this->matrix_user;
    $query_data['password'] = $this->matrix_password;

    //curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    //curl -XPOST -d '{"type":"m.login.password", "user":"zabbix", "password":"wordpass"}' 
    curl_setopt($ch, CURLOPT_URL, $this->matrix_server . "_matrix/client/r0/login" );
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query_data));
    $result_json = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status == '200') {
      $result = json_decode($result_json);
      $this->access_token = $result->access_token;
//      $this->refresh_token = $result->refresh_token;
      $this->userid = $result->user_id;
      $this->deviceid = $result->device_id;
      return true;
    }

    if ($http_status != '200') {
      echo("Login Failed\n");
      printf($result_json . "\n");
      die(1);
    }
  }

  private function updateDeviceInformation($userid, $deviceid, $refresh_token, $access_token)
  {
    $this->matrixDB->exec('
      INSERT INTO properties 
        (key, value)
        VALUES (
          "userid", "' . $this->matrixDB->escapeString($userid) . '"
        );
    ');

    $this->matrixDB->exec('
      INSERT INTO properties 
        (key, value)
        VALUES (
          "deviceid", "' . $this->matrixDB->escapeString($deviceid) . '"
        );
    ');

    $this->matrixDB->exec('
      INSERT INTO properties 
        (key, value)
        VALUES (
          "refresh_token", "' . $this->matrixDB->escapeString($refresh_token) . '"
        );
    ');

    $this->matrixDB->exec('
      INSERT INTO properties 
        (key, value)
        VALUES (
          "access_token", "' . $this->matrixDB->escapeString($access_token) . '"
        );
    ');
  }

  public function matrixSync ($timeout = false)
  {

    $next_batch = $this->getNext_batch();

    if ($next_batch)
    {
      $since = "since=" . $next_batch . "&";
    } else {
      $since = "";
    }

    if($timeout)
    {
      $timeout = "timeout=" . $timeout . "&";
    }
 
    $url = $this->matrix_server . "_matrix/client/r0/sync?" . $since . $timeout . "access_token=" . $this->access_token;

    $ch=curl_init();
    curl_setopt($ch,CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
    $result_json = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($result_json);

    file_put_contents('/tmp/result.json', $result_json);

    $this->saveNext_batch($result->next_batch);

    $this->roomsRoute($result->rooms);
    $this->account_dataRoute($result->account_data);
    $this->to_deviceRoute($result->to_device);
    $this->presenceRoute($result->presence);
  }

  private function saveNext_batch ($next_batch)
  {

    // do an update if the value exist else een insert 
    if ($this->getNext_batch()) {
       $query = '
                  UPDATE properties 
                    SET value = ' . '\'' . $this->matrixDB->escapeString($next_batch) . '\'' . '
                    WHERE key = ' . '\'' . $this->matrixDB->escapeString('next_batch') . '\'' . ';
                ';
    } else {
       $query = '
                   INSERT INTO properties (key, value)
                      VALUES (
                         "next_batch",
                         "' . $this->matrixDB->escapeString($next_batch) . '");
                ';
    }
    $this->matrixDB->query($query);
  }

  private function getNext_batch()
  {
    $next_batch = $this->matrixDB->query('
      SELECT key, value FROM properties WHERE key = "next_batch";
    ');

    $next_batch = $next_batch->fetchArray(SQLITE3_ASSOC);
    if($next_batch)
    {
      return $next_batch['value'];
    } else {
      return false;
    }
  }

  private function roomStateEventRouter($room, $event)
  {
    switch ($event->type) {
      case 'm.room.aliases':
        $this->roomStateEventAliases($room,$event);
        break;
      default:
        printf('Event not found: ' . $event->type . "\n");
        break;
    }
  }

  private function roomTimelineEventRouter($room, $event)
  {
    switch ($event->type) {
      case 'm.room.create':
        $this->roomTimelineEventCreate($room,$event);
        break;
      case 'm.room.member':
        $this->roomTimelineEventMember($room,$event);
        break;
      case 'm.room.power_levels':
        echo "m.room.power_levels\n";
        break;
      case 'm.room.join_rules':
        echo "m.room.join_rules\n";
        break;
      case 'm.room.history_visibility':
        echo "m.room.history_visibility\n";
        break;
      case 'm.room.guest_access':
        echo "m.room.guest_access\n";
        break;
      case 'm.room.message':
        $this->roomEventMessage($room,$event);
        break;
      
      default:
        printf('Event not found: ' . $event->type . "\n");
        break;
    }
  }

  public function sendMessage($room, $message)
  {

    $roomId = $this->lookupOrCreateRoomId($room);

    $query_data['msgtype'] = 'm.text';
    $query_data['body'] = $message;

    if ($roomId == false)
    {
      return false;
    }

    $ch=curl_init();
    $url = $this->matrix_server . "_matrix/client/r0/rooms/" . $roomId . "/send/m.room.message?access_token=" . $this->access_token;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query_data));
    $result_json = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status == '200')
    {
      return true;
    } else
    {
      return false;
    }
  }

  private function lookupOrCreateRoomId($room)
  {
    // Detect what type of room is it
    switch (true) {
      case preg_match("/^!/i", $room, $matches):
        //This is A room ID
        $roomId = $room;
        break;
      case preg_match("/^@/i", $room, $matches):
        //This is a direct user
        //echo ('directRoom: ' . $room . "\n");
        $roomId = $this->lookupDirectRoom($room);

        if (!$roomId)
        {
          // invite the other User
          $roomId = $this->inviteUserForDirectChat($room);
        }
        break;
      case preg_match("/^#/i", $room, $matches):
        //This a alias name for a room
        echo ('AliasRoom: ' . $room . "\n");
        $roomId = $this->lookupAliasRoom($room);
        break;
    }

      return $roomId;
  }

  private function roomTimelineEventCreate($room,$event)
  {
    echo "INSERT INTO rooms ";
    //var_dump($event);
  }

  private function roomTimelineEventMember($room,$event)
  {
    //echo "--------------- roomEventMember: $room \n";
    //var_dump($event);    
  }

  private function roomEventMessage($room, $event)
  {
    var_dump($event);
  }
  private function roomStateEventAliases($room,$event)
  {

    foreach ($event->content->aliases as $key => $roomAliasName) {
      $aliasquery = $this->matrixDB->query('
        SELECT roomId, roomAlias
        FROM roomsAliases
        WHERE 
          roomId = "' . $this->matrixDB->escapeString($room) . '" 
          AND 
          roomAlias  = "' . $this->matrixDB->escapeString($roomAliasName) . '";
      ');

      $aliasquery = $aliasquery->fetchArray(SQLITE3_ASSOC);
      if (!$aliasquery) {
        $this->matrixDB->exec(
          'INSERT INTO roomsAliases
            (roomId, roomAlias)
            VALUES (
              "' . $this->matrixDB->escapeString($room) . '", "' . $this->matrixDB->escapeString($roomAliasName) . '"
            );
          ');
      }
    }
  }

  private function roomJoin($room, $type = 'default', $roomName = false)
  {
    $roomQuery = $this->matrixDB->query('
      SELECT roomId
      FROM rooms
      WHERE
        roomId = "' . $this->matrixDB->escapeString($room) . '" 
    ');

    $roomQuery = $roomQuery->fetchArray(SQLITE3_ASSOC);
    if (!$roomQuery) {
      $sqlQuery =             'INSERT INTO rooms';

      if ($roomName ==  false) {
        $sqlQuery = $sqlQuery . '(roomId, type)';
      } else {
        $sqlQuery = $sqlQuery . '(roomId, type, name)';
      }

      $sqlQuery = $sqlQuery . ' VALUES (';

      if ($roomName ==  false) {
        $sqlQuery = $sqlQuery . '"' . $this->matrixDB->escapeString($room) . '", ' . '"' . $this->matrixDB->escapeString($type) . '"';
      } else {
        $sqlQuery = $sqlQuery . '"' . $this->matrixDB->escapeString($room) . '", ' . '"' . $this->matrixDB->escapeString($type) . '", ' . '"' . $this->matrixDB->escapeString($roomName) . '"';
      }

      $sqlQuery = $sqlQuery . ');';


    } else {
      if ($type != 'default') {
        
        $sqlQuery = 'UPDATE rooms';
        $sqlQuery = $sqlQuery . ' SET type = ' . '"' . $this->matrixDB->escapeString($type) . '"';

        if (!$roomName ==  false) {
          $sqlQuery = $sqlQuery . ', name = "' . $this->matrixDB->escapeString($roomName) . '"';
        }

        $sqlQuery = $sqlQuery . ' WHERE roomId = "' . $this->matrixDB->escapeString($room) . '";';
      }
    }
      $this->matrixDB->exec($sqlQuery);
  }

  private function roomsRoute($data='')
  {
    foreach ($data->join as $room => $value)
    {
      // verwerken welke rooms gejoint zijn
      $this->roomJoin($room);
      // uitlezen van de room informatie (state)
      foreach ($value->state->events as $eventId => $event) {
        $this->roomStateEventRouter($room, $event);
      }
      foreach ($value->timeline->events as $eventId => $event) {
        $this->roomTimelineEventRouter($room, $event);
      }
    }
  }

  private function account_dataRoute($data='')
  {
    foreach ($data->events as $key => $value) {
      switch ($value->type) {
        case 'm.direct':
          $this->account_dataContentTypeMDirect($value->content);
          break;
      }
    }
  }

  private function to_deviceRoute($data='')
  {
    # TODO
  }

  private function presenceRoute($data='')
  {
    # TODO
  }

  private function account_dataContentTypeMDirect($value)
  {
    foreach ($value as $roomName => $arrayRoomId) {
      foreach ($arrayRoomId as $roomId) {
        $this->roomJoin($roomId, 'direct', $roomName);
        var_dump($roomId);
      }
    }
  }

  private function lookupDirectRoom($room)
  {
    $rooms = $this->matrixDB->query('
      SELECT roomId FROM rooms WHERE type = "direct" and name = \'' . $this->matrixDB->escapeString($room) . '\';
    ');

    while ($roomId = $rooms->fetchArray(SQLITE3_ASSOC)) {
      return $roomId['roomId']; 
    }

    return false;
  }

  private function lookupAliasRoom($room)
  {
    $rooms = $this->matrixDB->query('
      SELECT roomId FROM roomsAliases WHERE roomAlias = \'' . $this->matrixDB->escapeString($room) . '\';
    ');

    while ($roomId = $rooms->fetchArray(SQLITE3_ASSOC)) {
      return $roomId['roomId'];
    }

    return false;
  }

  private function inviteUserForDirectChat($room) {
    var_dump($room);
  }
}
