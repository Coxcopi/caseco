<?php
/* vim: set noexpandtab tabstop=2 softtabstop=2 shiftwidth=2: */
global $aseco;

/**
 * This script saves record into a local database.
 * You can modify this file as you want, to advance
 * the information stored in the database!
 *
 * @author    Florian Schnell
 * @version   2.0
 * Updated by Xymph
 *
 * Dependencies: requires plugin.panels.php on TMF
 */

$aseco->registerEvent('onStartup', 'ldb_loadSettings');
$aseco->registerEvent('onStartup', 'ldb_connect');
$aseco->registerEvent('onEverySecond', 'ldb_reconnect');
$aseco->registerEvent('onSync', 'ldb_sync');
$aseco->registerEvent('onNewChallenge', 'ldb_newChallenge');
$aseco->registerEvent('onPlayerConnect', 'ldb_playerConnect');
$aseco->registerEvent('onPlayerDisconnect', 'ldb_playerDisconnect');
$aseco->registerEvent('onPlayerFinish', 'ldb_playerFinish');
$aseco->registerEvent('onPlayerWins', 'ldb_playerWins');

// called @ onStartup
function ldb_loadSettings($aseco) {
	global $ldb_settings;

	$aseco->console('[LocalDB] Load config file [localdatabase.xml]');
	if (!$settings = $aseco->xml_parser->parseXml('localdatabase.xml')) {
		trigger_error('Could not read/parse Local database config file localdatabase.xml !', E_USER_ERROR);
	}
	$settings = $settings['SETTINGS'];

	// read mysql server settings
	$ldb_settings['mysql']['host'] = $settings['MYSQL_SERVER'][0];
	$ldb_settings['mysql']['login'] = $settings['MYSQL_LOGIN'][0];
	$ldb_settings['mysql']['password'] = $settings['MYSQL_PASSWORD'][0];
	$ldb_settings['mysql']['database'] = $settings['MYSQL_DATABASE'][0];
	$ldb_settings['mysql']['connection'] = false;

	// display records in game?
	if (strtoupper($settings['DISPLAY'][0]) == 'TRUE')
		$ldb_settings['display'] = true;
	else
		$ldb_settings['display'] = false;

	// set highest record still to be displayed
	$ldb_settings['limit'] = $settings['LIMIT'][0];

	$ldb_settings['messages'] = $settings['MESSAGES'][0];
}  // ldb_loadSettings

// called @ onStartup
function ldb_connect($aseco) {
	global $maxrecs;

	// get the settings
	global $ldb_settings;
	// create data fields
	global $ldb_records;
	$ldb_records = new RecordList($maxrecs);
	global $ldb_challenge;
	$ldb_challenge = new Challenge();

	// database object
	global $dbo;

	// log status message
	$aseco->console("[LocalDB] Try to connect to MySQL server on '{1}' with database '{2}'",
	                $ldb_settings['mysql']['host'], $ldb_settings['mysql']['database']);

	$dbo = connect_to_db();

	// log status message
	$aseco->console('[LocalDB] MySQL Server Version is ' . $dbo->getAttribute(PDO::ATTR_SERVER_VERSION));
	// optional UTF-8 handling fix
	//mysql_query('SET NAMES utf8');
	$aseco->console('[LocalDB] Checking database structure...');

	// create main tables
	$query = "CREATE TABLE IF NOT EXISTS `challenges` (
	            `Id` mediumint(9) NOT NULL auto_increment,
	            `Uid` varchar(27) NOT NULL default '',
	            `Name` varchar(100) NOT NULL default '',
	            `Author` varchar(30) NOT NULL default '',
	            `Environment` varchar(10) NOT NULL default '',
	            PRIMARY KEY (`Id`),
	            UNIQUE KEY `Uid` (`Uid`)
	          ) ENGINE=MyISAM";
	$dbo->query($query);

	$query = "CREATE TABLE IF NOT EXISTS `players` (
	            `Id` mediumint(9) NOT NULL auto_increment,
	            `Login` varchar(50) NOT NULL default '',
	            `Game` varchar(3) NOT NULL default '',
	            `NickName` varchar(100) NOT NULL default '',
	            `Nation` varchar(3) NOT NULL default '',
	            `UpdatedAt` datetime NOT NULL default '0000-00-00 00:00:00',
	            `Wins` mediumint(9) NOT NULL default 0,
	            `TimePlayed` int(10) unsigned NOT NULL default 0,
	            `TeamName` char(60) NOT NULL default '',
	            PRIMARY KEY (`Id`),
	            UNIQUE KEY `Login` (`Login`),
	            KEY `Game` (`Game`)
	          ) ENGINE=MyISAM";
	$dbo->query($query);

	$query = "CREATE TABLE IF NOT EXISTS `records` (
	            `Id` int(11) NOT NULL auto_increment,
	            `ChallengeId` mediumint(9) NOT NULL default 0,
	            `PlayerId` mediumint(9) NOT NULL default 0,
	            `Score` int(11) NOT NULL default 0,
	            `Date` datetime NOT NULL default '0000-00-00 00:00:00',
	            `Checkpoints` text NOT NULL,
	            PRIMARY KEY (`Id`),
	            UNIQUE KEY `PlayerId` (`PlayerId`,`ChallengeId`),
	            KEY `ChallengeId` (`ChallengeId`)
	          ) ENGINE=MyISAM";
	$dbo->query($query);

	$query = "CREATE TABLE IF NOT EXISTS `players_extra` (
	            `playerID` mediumint(9) NOT NULL default 0,
	            `cps` smallint(3) NOT NULL default -1,
	            `dedicps` smallint(3) NOT NULL default -1,
	            `donations` mediumint(9) NOT NULL default 0,
	            `style` varchar(20) NOT NULL default '',
	            `panels` varchar(255) NOT NULL default '',
	            PRIMARY KEY (`playerID`),
	            KEY `donations` (`donations`)
	          ) ENGINE=MyISAM";
	$dbo->query($query);

	// check for main tables
	$tables = array();
	$res = $dbo->query('SHOW TABLES');
	while ($row = $res->fetch(PDO::FETCH_NUM))
		$tables[] = $row[0];
	$res = null;
	$check = array();
	$check[1] = in_array('challenges', $tables);
	$check[2] = in_array('players', $tables);
	$check[3] = in_array('records', $tables);
	$check[4] = in_array('players_extra', $tables);
	if (!($check[1] && $check[2] && $check[3] && $check[4])) {
		trigger_error('[LocalDB] Table structure incorrect!  Use localdb/aseco.sql & extra.sql to correct this', E_USER_ERROR);
	}

	// enlarge challenges 'Name' colum
	$result = $dbo->query('DESC challenges Name');
	$row = $result->fetch(PDO::FETCH_NUM);
	$result = null;
	if ($row[1] != 'varchar(100)') {
		$aseco->console("[LocalDB] Alter 'challenges' column 'Name'...");
		$dbo->query("ALTER TABLE challenges MODIFY Name varchar(100) NOT NULL default ''");
	}

	// reduce challenges 'Environment' colum
	$result = $dbo->query('DESC challenges Environment');
	$row = $result->fetch(PDO::FETCH_NUM);
	$result = null;
	if ($row[1] != 'varchar(10)') {
		$aseco->console("[LocalDB] Alter 'challenges' column 'Environment'...");
		$dbo->query("ALTER TABLE challenges MODIFY Environment varchar(10) NOT NULL default ''");
	}

	// add players 'TeamName' column
	$fields = array();
	$result = $dbo->query('SHOW COLUMNS FROM players');
	while ($row = $result->fetch(PDO::FETCH_NUM))
		$fields[] = $row[0];
	$result = null;
	if (!in_array('TeamName', $fields)) {
		$aseco->console("[LocalDB] Add 'players' column 'TeamName'...");
		$dbo->query("ALTER TABLE players ADD TeamName char(60) NOT NULL default ''");
	}

	// enlarge players 'NickName' & 'TimePlayed' columns
	$result = $dbo->query('DESC players NickName');
	$row = $result->fetch(PDO::FETCH_NUM);
	$result = null;
	if ($row[1] != 'varchar(100)') {
		$aseco->console("[LocalDB] Alter 'players' column 'NickName'...");
		$dbo->query("ALTER TABLE players MODIFY NickName varchar(100) NOT NULL default ''");
	}
	$result = $dbo->query('DESC players TimePlayed');
	$row = $result->fetch(PDO::FETCH_NUM);
	$result = null;
	if ($row[1] != 'int(10) unsigned') {
		$aseco->console("[LocalDB] Alter 'players' column 'TimePlayed'...");
		$dbo->query("ALTER TABLE players MODIFY TimePlayed int(10) unsigned NOT NULL default 0");
	}

	// enlarge records 'Id' & 'Score' columns
	$result = $dbo->query('DESC records Id');
	$row = $result->fetch(PDO::FETCH_NUM);
	$result = null;
	if ($row[1] != 'int(11)') {
		$aseco->console("[LocalDB] Alter 'records' column 'Id'...");
		$dbo->query('ALTER TABLE records MODIFY Id int(11) auto_increment');
	}
	$result = $dbo->query('DESC records Score');
	$row = $result->fetch(PDO::FETCH_NUM);
	$result = null;
	if ($row[1] != 'int(11)') {
		$aseco->console("[LocalDB] Alter 'records' column 'Score'...");
		$dbo->query("ALTER TABLE records MODIFY Score int(11) NOT NULL default 0");
	}

	// add records 'Checkpoints' column
	$fields = array();
	$result = $dbo->query('SHOW COLUMNS FROM records');
	while ($row = $result->fetch(PDO::FETCH_NUM))
		$fields[] = $row[0];
	$result = null;
	if (!in_array('Checkpoints', $fields)) {
		$aseco->console("[LocalDB] Add 'records' column 'Checkpoints'...");
		$dbo->query("ALTER TABLE records ADD Checkpoints text NOT NULL");
	}

	// change records old 'ChallengeId' key into new 'PlayerId' key and
	//  add records new 'ChallengeId' key
	$fields = array('PlayerId' => 0, 'ChallengeId' => 0);
	$result = $dbo->query('SHOW INDEX FROM records');
	while ($row = $result->fetch(PDO::FETCH_NUM)) {
		if (isset($fields[$row[2]]))
			$fields[$row[2]]++;
	}
	$result = null;
	if ($fields['ChallengeId'] == 2 && $fields['PlayerId'] == 0) {
		$aseco->console("[LocalDB] Drop 'records' key 'ChallengeId'...");
		$dbo->query("ALTER TABLE records DROP KEY ChallengeId");
		$aseco->console("[LocalDB] Add 'records' key 'PlayerId'...");
		$dbo->query("ALTER TABLE records ADD UNIQUE KEY PlayerId (PlayerId, ChallengeId)");
		$aseco->console("[LocalDB] Add 'records' key 'ChallengeId'...");
		$dbo->query("ALTER TABLE records ADD KEY ChallengeId (ChallengeId)");
	}

	// change players_extra 'playerID' key into primary key and
	//  add players_extra 'donations' key
	$fields = array();
	$result = $dbo->query('SHOW INDEX FROM players_extra');
	while ($row = $result->fetch(PDO::FETCH_NUM))
		$fields[] = $row[2];
	$result = null;
	if (in_array('playerID', $fields)) {
		$aseco->console("[LocalDB] Drop 'players_extra' key 'playerID'...");
		$dbo->query("ALTER TABLE players_extra DROP KEY playerID");
	}
	if (!in_array('PRIMARY', $fields)) {
		$aseco->console("[LocalDB] Add 'players_extra' primary key 'playerID'...");
		$dbo->query("ALTER TABLE players_extra ADD PRIMARY KEY (playerID)");
	}
	if (!in_array('donations', $fields)) {
		$aseco->console("[LocalDB] Add 'players_extra' key 'donations'...");
		$dbo->query("ALTER TABLE players_extra ADD KEY donations (donations)");
	}

	$aseco->console('[LocalDB] ...Structure OK!');
}  // ldb_connect

// called @ onEverySecond
function ldb_reconnect($aseco) {
	global $dlb_settings, $dbo;

	// check for online players
	if (empty($aseco->server->players->player_list)) {
		if (!is_connection_alive($dbo)) {
			$dbo = connect_to_db();
			$aseco->console('[LocalDB] Reconnected to MySQL Server.');
		}
	}
}  // ldb_reconnect

// called @ onSync
function ldb_sync($aseco) {

/* ldb_playerConnect on sync already invoked via onPlayerConnect event,
   so disable it here - Xymph
	$aseco->console('[LocalDB] Synchronize players with database');

	// take each player in the list and simulate a join
	while ($player = $aseco->server->players->nextPlayer()) {
		// log debug message
		if ($aseco->debug) $aseco->console('[LocalDB] Sending player ' . $player->login);
		ldb_playerConnect($aseco, $player);
	}
disabled */

	// reset player list
	$aseco->server->players->resetPlayers();
}  // ldb_sync

// called @ onPlayerConnect
function ldb_playerConnect($aseco, $player) {
	global $ldb_settings, $dbo;

	if ($aseco->server->getGame() == 'TMF')
		$nation = mapCountry($player->nation);
	else  // TMN/TMS/TMO
		$nation = $player->nation;

	// get player stats
	$query = 'SELECT Id, Wins, TimePlayed, TeamName FROM players
	          WHERE Login=' . quotedString($player->login); // .
	          // ' AND Game=' . quotedString($aseco->server->getGame());
	$result = $dbo->query($query);

	if ($result === false) {
		trigger_error('Could not get stats of connecting player! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return;
	}

	// was retrieved
	if ($result->rowCount() > 0) {
		$dbplayer = $result->fetch(PDO::FETCH_OBJ);
		$result = null;

		// update player stats
		$player->id = $dbplayer->Id;
		if ($player->teamname == '' && $dbplayer->TeamName != '') {
			$player->teamname = $dbplayer->TeamName;
		}
		if ($player->wins < $dbplayer->Wins) {
			$player->wins = $dbplayer->Wins;
		}
		if ($player->timeplayed < $dbplayer->TimePlayed) {
			$player->timeplayed = $dbplayer->TimePlayed;
		}

		// update player data
		$query = 'UPDATE players
		          SET NickName=' . quotedString($player->nickname) . ',
		              Nation=' . quotedString($nation) . ',
		              TeamName=' . quotedString($player->teamname) . ',
		              UpdatedAt=NOW()
		          WHERE Login=' . quotedString($player->login); // .
		          // ' AND Game=' . quotedString($aseco->server->getGame());
		$result = $dbo->query($query);

		if ($result === false || $result->rowCount() == -1) {
			trigger_error('Could not update connecting player! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			return;
		}

	// could not be retrieved
	} else {  // mysql_num_rows() == 0
		$result = null;
		$player->id = 0;

		// insert player
		$query = 'INSERT INTO players
		          (Login, Game, NickName, Nation, TeamName, UpdatedAt)
		          VALUES
		          (' . quotedString($player->login) . ', ' .
		           quotedString($aseco->server->getGame()) . ', ' .
		           quotedString($player->nickname) . ', ' .
		           quotedString($nation) . ', ' .
		           quotedString($player->teamname) . ', NOW())';
		$result = $dbo->query($query);

		if ($result === false || $result->rowCount() != 1) {
			trigger_error('Could not insert connecting player! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			return;
		} else {
			$query = 'SELECT LAST_INSERT_ID() FROM players';
			$result = $dbo->query($query);
			if ($result === false) {
				trigger_error('Could not get inserted player\'s id! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
				return;
			} else {
				$dbplayer = $result->fetch(PDO::FETCH_NUM);
				$player->id = $dbplayer[0];
				$result = null;
			}
		}
	}

	// check for player's extra data
	$query = 'SELECT playerID FROM players_extra
	          WHERE playerID=' . $player->id;
	$result = $dbo->query($query);

	if ($result === false) {
		trigger_error('Could not get player\'s extra data! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return;
	}

	// was retrieved
	if ($result->rowCount() > 0) {
		$result = null;

	// could not be retrieved
	} else {  // mysql_num_rows() == 0
		$result = null;

		// insert player's default extra data
		$query = 'INSERT INTO players_extra
		          (playerID, cps, dedicps, donations, style, panels)
		          VALUES
		          (' . $player->id . ', ' .
		           ($aseco->settings['auto_enable_cps'] ? 0 : -1) . ', ' .
		           ($aseco->settings['auto_enable_dedicps'] ? 0 : -1) . ', 0, ' .
		           quotedString($aseco->settings['window_style']) . ', ' .
		           quotedString($aseco->settings['admin_panel'] . '/' .
		                        $aseco->settings['donate_panel'] . '/' .
		                        $aseco->settings['records_panel'] . '/' .
		                        $aseco->settings['vote_panel']) . ')';
		$result = $dbo->query($query);

		if ($result === false || $result->rowCount() != 1) {
			trigger_error('Could not insert player\'s extra data! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		}
	}
}  // ldb_playerConnect

// called @ onPlayerDisconnect
function ldb_playerDisconnect($aseco, $player) {
	global $dbo;

	// ignore fluke disconnects with empty logins
	if ($player->login == '') return;

	// update player
	$query = 'UPDATE players
	          SET UpdatedAt=NOW(),
	              TimePlayed=TimePlayed+' . $player->getTimeOnline() . '
	          WHERE Login=' . quotedString($player->login); // .
	          // ' AND Game=' . quotedString($aseco->server->getGame());
	$result = $dbo->query($query);

	if ($result === false || $result->rowCount() == -1) {
		trigger_error('Could not update disconnecting player! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_playerDisconnect

function ldb_getDonations($aseco, $login) {
	global $dbo;

	// get player's donations
	$query = 'SELECT donations FROM players_extra
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = $dbo->query($query);

	if ($result === false) {
		trigger_error('Could not get player\'s donations! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return false;
	} else {
		$dbextra = $result->fetch(PDO::FETCH_OBJ);
		$result = null;

		return $dbextra->donations;
	}
}  // ldb_getDonations

function ldb_updateDonations($aseco, $login, $donation) {
	global $dbo;

	// update player's donations
	$query = 'UPDATE players_extra
	          SET donations=donations+' . $donation . '
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = $dbo->query($query);

	if ($result === false || $result->rowCount() != 1) {
		trigger_error('Could not update player\'s donations! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_updateDonations

function ldb_getCPs($aseco, $login) {
	global $dbo;

	// get player's CPs settings
	$query = 'SELECT cps, dedicps FROM players_extra
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = $dbo->query($query);

	if ($result === false) {
		trigger_error('Could not get player\'s CPs! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return false;
	} else {
		$dbextra = $result->fetch(PDO::FETCH_OBJ);
		$result = null;

		return array('cps' => $dbextra->cps, 'dedicps' => $dbextra->dedicps);
	}
}  // ldb_getCPs

function ldb_setCPs($aseco, $login, $cps, $dedicps) {
	global $dbo;

	$query = 'UPDATE players_extra
	          SET cps=' . $cps . ', dedicps=' . $dedicps . '
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = $dbo->query($query);

	if ($result === false || $result->rowCount() == -1) {
		trigger_error('Could not update player\'s CPs! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_setCPs

function ldb_getStyle($aseco, $login) {
	global $dbo;

	// get player's style
	$query = 'SELECT style FROM players_extra
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = $dbo->query($query);

	if ($result === false) {
		trigger_error('Could not get player\'s style! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return false;
	} else {
		$dbextra = $result->fetch(PDO::FETCH_OBJ);
		$result = null;

		return $dbextra->style;
	}
}  // ldb_getStyle

function ldb_setStyle($aseco, $login, $style) {
	global $dbo;

	$query = 'UPDATE players_extra
	          SET style=' . quotedString($style) . '
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = $dbo->query($query);

	if ($result === false || $result->rowCount() == -1) {
		trigger_error('Could not update player\'s style! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_setStyle

function ldb_getPanels($aseco, $login) {
	global $dbo;

	// get player's panels
	$query = 'SELECT panels FROM players_extra
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = $dbo->query($query);

	if ($result === false) {
		trigger_error('Could not get player\'s panels! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return false;
	} else {
		$dbextra = $result->fetch(PDO::FETCH_OBJ);
		$result = null;

		$panel = explode('/', $dbextra->panels);
		$panels = array();
		$panels['admin'] = $panel[0];
		$panels['donate'] = $panel[1];
		$panels['records'] = $panel[2];
		$panels['vote'] = $panel[3];
		return $panels;
	}
}  // ldb_getPanels

function ldb_setPanel($aseco, $login, $type, $panel) {
	global $dbo;

	// update player's panels
	$panels = ldb_getPanels($aseco, $login);
	$panels[$type] = $panel;
	$query = 'UPDATE players_extra
	          SET panels=' . quotedString($panels['admin'] . '/' . $panels['donate'] . '/' .
	                                      $panels['records'] . '/' . $panels['vote']) . '
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = $dbo->query($query);

	if ($result === false || $result->rowCount() == -1) {
		trigger_error('Could not update player\'s panels! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_setPanel

// called @ onPlayerFinish
function ldb_playerFinish($aseco, $finish_item) {
	global $ldb_records, $ldb_settings,
	       $checkpoints;  // from plugin.checkpoints.php

	// if no actual finish, bail out immediately
	if ($finish_item->score == 0) return;

	// in Laps mode on real PlayerFinish event, bail out too
	if ($aseco->server->gameinfo->mode == Gameinfo::LAPS && !$finish_item->new) return;

	$login = $finish_item->player->login;
	$nickname = stripColors($finish_item->player->nickname);

	// reset lap 'Finish' flag & add checkpoints
	$finish_item->new = false;
	$finish_item->checks = (isset($checkpoints[$login]) ? $checkpoints[$login]->curr_cps : array());

	// drove a new record?
	// go through each of the XX records
	for ($i = 0; $i < $ldb_records->max; $i++) {
		$cur_record = $ldb_records->getRecord($i);

		// if player's time/score is better, or record isn't set (thanks eyez)
		if ($cur_record === false || ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
		                              $finish_item->score > $cur_record->score :
		                              $finish_item->score < $cur_record->score)) {

			// does player have a record already?
			$cur_rank = -1;
			$cur_score = 0;
			for ($rank = 0; $rank < $ldb_records->count(); $rank++) {
				$rec = $ldb_records->getRecord($rank);

				if ($rec->player->login == $login) {

					// new record worse than old one
					if ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
					    $finish_item->score < $rec->score :
					    $finish_item->score > $rec->score) {
						return;

					// new record is better than or equal to old one
					} else {
						$cur_rank = $rank;
						$cur_score = $rec->score;
						break;
					}
				}
			}

			$finish_time = $finish_item->score;
			if ($aseco->server->gameinfo->mode != Gameinfo::STNT)
				$finish_time = formatTime($finish_time);

			if ($cur_rank != -1) {  // player has a record in topXX already

				// compute difference to old record
				if ($aseco->server->gameinfo->mode != Gameinfo::STNT) {
					$diff = $cur_score - $finish_item->score;
					$sec = floor($diff/1000);
					$hun = ($diff - ($sec * 1000)) / 10;
				} else {  // Stunts
					$diff = $finish_item->score - $cur_score;
				}

				// update record if improved
				if ($diff > 0) {
					$finish_item->new = true;
					$ldb_records->setRecord($cur_rank, $finish_item);
				}

				// player moved up in LR list
				if ($cur_rank > $i) {

					// move record to the new position
					$ldb_records->moveRecord($cur_rank, $i);

					// do a player improved his/her LR rank message
					$message = formatText($ldb_settings['messages']['RECORD_NEW_RANK'][0],
					                      $nickname,
					                      $i+1,
					                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'Score' : 'Time'),
					                      $finish_time,
					                      $cur_rank+1,
					                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
					                       '+' . $diff : sprintf('-%d.%02d', $sec, $hun)));

					// show chat message to all or player
					if ($ldb_settings['display']) {
						if ($i < $ldb_settings['limit']) {
							if ($aseco->settings['recs_in_window'] && function_exists('send_window_message'))
								send_window_message($aseco, $message, false);
							else
								$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
						} else {
							$message = str_replace('{#server}>> ', '{#server}> ', $message);
							$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
						}
					}

				} else {

					if ($diff == 0) {
						// do a player equaled his/her record message
						$message = formatText($ldb_settings['messages']['RECORD_EQUAL'][0],
						                      $nickname,
						                      $cur_rank+1,
						                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'Score' : 'Time'),
						                      $finish_time);
					} else {
						// do a player secured his/her record message
						$message = formatText($ldb_settings['messages']['RECORD_NEW'][0],
						                      $nickname,
						                      $i+1,
						                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'Score' : 'Time'),
						                      $finish_time,
						                      $cur_rank+1,
						                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
						                       '+' . $diff : sprintf('-%d.%02d', $sec, $hun)));
					}

					// show chat message to all or player
					if ($ldb_settings['display']) {
						if ($i < $ldb_settings['limit']) {
							if ($aseco->settings['recs_in_window'] && function_exists('send_window_message'))
								send_window_message($aseco, $message, false);
							else
								$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
						} else {
							$message = str_replace('{#server}>> ', '{#server}> ', $message);
							$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
						}
					}
				}

			} else {  // player hasn't got a record yet

				// if previously tracking own/last local record, now track new one
				if (isset($checkpoints[$login]) &&
				    $checkpoints[$login]->loclrec == 0 && $checkpoints[$login]->dedirec == -1) {
					$checkpoints[$login]->best_fin = $checkpoints[$login]->curr_fin;
					$checkpoints[$login]->best_cps = $checkpoints[$login]->curr_cps;
				}

				// insert new record at the specified position
				$finish_item->new = true;
				$ldb_records->addRecord($finish_item, $i);

				// do a player drove first record message
				$message = formatText($ldb_settings['messages']['RECORD_FIRST'][0],
				                      $nickname,
				                      $i+1,
				                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'Score' : 'Time'),
				                      $finish_time);

				// show chat message to all or player
				if ($ldb_settings['display']) {
					if ($i < $ldb_settings['limit']) {
						if ($aseco->settings['recs_in_window'] && function_exists('send_window_message'))
							send_window_message($aseco, $message, false);
						else
							$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
					} else {
						$message = str_replace('{#server}>> ', '{#server}> ', $message);
						$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					}
				}
			}

			// update aseco records
			$aseco->server->records = $ldb_records;

			// log records when debugging is set to true
			//if ($aseco->debug) $aseco->console('ldb_playerFinish records:' . CRLF . print_r($ldb_records, true));

			// insert and log a new local record (not an equalled one)
			if ($finish_item->new) {
				ldb_insert_record($finish_item);

				// update all panels if new #1 record
				if ($aseco->server->getGame() == 'TMF' && $i == 0) {
					setRecordsPanel('local', ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
					                          str_pad($finish_item->score, 5, ' ', STR_PAD_LEFT) :
					                          formatTime($finish_item->score)));
					if (function_exists('update_allrecpanels'))
						update_allrecpanels($aseco, null);  // from plugin.panels.php
				}

				// log record message in console
				$aseco->console('[LocalDB] player {1} finished with {2} and took the {3}. LR place!',
				                $login, $finish_item->score, $i+1);

				// throw 'local record' event
				$finish_item->pos = $i+1;
				$aseco->releaseEvent('onLocalRecord', $finish_item);
			}

			// got the record, now stop!
			return;
		}
	}
}  // ldb_playerFinish

function ldb_insert_record($record) {
	global $aseco, $ldb_challenge, $dbo;

	$playerid = $record->player->id;
	$cps = implode(',', $record->checks);

	// insert new record or update existing
	$query = 'INSERT INTO records
	          (ChallengeId, PlayerId, Score, Date, Checkpoints)
	          VALUES
	          (' . $ldb_challenge->id . ', ' . $playerid . ', ' .
	           $record->score . ', NOW(), ' . quotedString($cps) . ') ' .
	         'ON DUPLICATE KEY UPDATE ' .
	          'Score=VALUES(Score), Date=VALUES(Date), Checkpoints=VALUES(Checkpoints)';
	$result = $dbo->query($query);

	if ($result === false || $result->rowCount() <= 0) {
		trigger_error('Could not insert/update record! (' . $result->errorCode() . ': ' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_insert_record

function ldb_removeRecord($aseco, $cid, $pid, $recno) {
	global $ldb_records, $dbo;

	// remove record
	$query = 'DELETE FROM records WHERE ChallengeId=' . $cid . ' AND PlayerId=' . $pid;
	$result = $dbo->query($query);
	if ($result === false || $result->rowCount() != 1) {
		trigger_error('Could not remove record! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}

	// remove record from specified position
	$ldb_records->delRecord($recno);

	// check if fill up is needed
	if ($ldb_records->count() == ($ldb_records->max - 1)) {
		// get max'th time
		$query = 'SELECT DISTINCT playerid,score FROM rs_times t1 WHERE challengeid=' . $cid .
		         ' AND score=(SELECT MIN(t2.score) FROM rs_times t2 WHERE challengeid=' . $cid .
		         '            AND t1.playerid=t2.playerid) ORDER BY score,date LIMIT ' . ($ldb_records->max - 1) . ',1';
		$result = $dbo->query($query);

		if ($result !== false && $result->rowCount() == 1) {
			$timerow = $result->fetch(PDO::FETCH_OBJ);

			// get corresponding date/time & checkpoints
			$query = 'SELECT date,checkpoints FROM rs_times WHERE challengeid=' . $cid .
			         ' AND playerid=' . $timerow->playerid . ' ORDER BY score,date LIMIT 1';
			$result2 = $dbo->query($query);
			$timerow2 = $result2->fetch(PDO::FETCH_OBJ);
			$datetime = date('Y-m-d H:i:s', $timerow2->date);
			$result2 = null;

			// insert/update new max'th record
			$query = 'INSERT INTO records
			          (ChallengeId, PlayerId, Score, Date, Checkpoints)
			          VALUES
			          (' . $cid . ', ' . $timerow->playerid . ', ' .
			           $timerow->score . ', ' . quotedString($datetime) . ', ' .
			           quotedString($timerow2->checkpoints) . ') ' .
			         'ON DUPLICATE KEY UPDATE ' .
			          'Score=VALUES(Score), Date=VALUES(Date), Checkpoints=VALUES(Checkpoints)';
			$result2 = $dbo->query($query);

			if ($result2 === false || $result2->rowCount() <= 0) {
				trigger_error('Could not insert/update record! (' . $result->errorCode() . ': ' . $result2->errorInfo() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			}

			// get player info
			$query = 'SELECT * FROM players WHERE id=' . $timerow->playerid;
			$result2 = $dbo->query($query);
			$playrow = $result2->fetch(PDO::FETCH_ASSOC);
			$result2 = null;

			// create record object
			$record_item = new Record();
			$record_item->score = $timerow->score;
			$record_item->checks = ($timerow2->checkpoints != '' ? explode(',', $timerow2->checkpoints) : array());
			$record_item->new = false;

			// create a player object to put it into the record object
			$player_item = new Player();
			$player_item->nickname = $playrow['NickName'];
			$player_item->login = $playrow['Login'];
			$record_item->player = $player_item;

			// add the track information to the record object
			$record_item->challenge = clone $aseco->server->challenge;
			unset($record_item->challenge->gbx);  // reduce memory usage
			unset($record_item->challenge->tmx);

			// add the created record to the list
			$ldb_records->addRecord($record_item);
		}
		if ($result !== false)
			$result = null;
	}

	// update aseco records
	$aseco->server->records = $ldb_records;
}  // ldb_remove_record

// called @ onNewChallenge
function ldb_newChallenge($aseco, $challenge) {
	global $ldb_challenge, $ldb_records, $ldb_settings, $dbo;
	$ldb_records->clear();
	$aseco->server->records->clear();

	// on relay, ignore master server's challenge
	if ($aseco->server->isrelay) {
		$challenge->id = 0;
		return;
	}

	$order = ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'DESC' : 'ASC');
	$query = 'SELECT c.Id AS ChallengeId, r.Score, p.NickName, p.Login, r.Date, r.Checkpoints
	          FROM challenges c
	          LEFT JOIN records r ON (r.ChallengeId=c.Id)
	          LEFT JOIN players p ON (r.PlayerId=p.Id)
	          WHERE c.Uid=' . quotedString($challenge->uid) . '
	          GROUP BY r.Id
	          ORDER BY r.Score ' . $order . ',r.Date ASC
	          LIMIT ' . $ldb_records->max;

	$result = $dbo->query($query);

	if ($result === false) {
		$a = errInfo2text($result->errorInfo());
		trigger_error('Could not get challenge info! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return;
	}

	// challenge found?
	if ($result->rowCount() > 0) {

		// get each record
		while ($record = $result->fetch(PDO::FETCH_ASSOC)) {

			// create record object
			$record_item = new Record();
			$record_item->score = $record['Score'];
			$record_item->checks = ($record['Checkpoints'] != '' ? explode(',', $record['Checkpoints']) : array());
			$record_item->new = false;

			// create a player object to put it into the record object
			$player_item = new Player();
			$player_item->nickname = $record['NickName'];
			$player_item->login = $record['Login'];
			$record_item->player = $player_item;

			// add the track information to the record object
			$record_item->challenge = clone $challenge;
			unset($record_item->challenge->gbx);  // reduce memory usage
			unset($record_item->challenge->tmx);

			// add the created record to the list
			$ldb_records->addRecord($record_item);

			// get challenge info
			$ldb_challenge->id = $record['ChallengeId'];
			$challenge->id = $record['ChallengeId'];
		}

		// update aseco records
		$aseco->server->records = $ldb_records;

		// log records when debugging is set to true
		//if ($aseco->debug) $aseco->console('ldb_newChallenge records:' . CRLF . print_r($ldb_records, true));

		$result = null;

	// challenge isn't in database yet
	} else {
		$result = null;

		// then create it
		$query = 'INSERT INTO challenges
		          (Uid, Name, Author, Environment)
		          VALUES
		          (' . quotedString($challenge->uid) . ', ' .
		           quotedString($challenge->name) . ', ' .
		           quotedString($challenge->author) . ', ' .
		           quotedString($challenge->environment) . ')';
		$result = $dbo->query($query);

		// challenge was inserted successfully
		if ($result !== false && $result->rowCount() == 1) {
			// get its Id now
			$query = 'SELECT Id FROM challenges
			          WHERE Uid=' . quotedString($challenge->uid);
			$result = $dbo->query($query);

			if ($result !== false && $result->rowCount() == 1) {
				$row = $result->fetch(PDO::FETCH_NUM);
				$ldb_challenge->id = $row[0];
				$challenge->id = $row[0];
			} else {
				// challenge Id could not be found
				trigger_error('Could not get new challenge id! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			}
			if ($result !== false)
				$result = null;
		} else {
			trigger_error('Could not insert new challenge! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		}
	}
}  // ldb_newChallenge

// called @ onPlayerWins
function ldb_playerWins($aseco, $player) {
	global $dbo;

	$wins = $player->getWins();
	$query = 'UPDATE players
	          SET Wins=' . $wins . '
	          WHERE Login=' . quotedString($player->login);
	$result = $dbo->query($query);

	if ($result === false || $result->rowCount() != 1) {
		trigger_error('Could not update winning player! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_playerWins

// connects to the mysql/mariadb server instance using PDO
function connect_to_db(): PDO {
	global $ldb_settings;
	try {
		$dbo = new PDO('mysql:host=' . $ldb_settings['mysql']['host'] . 
					   ';dbname=' . $ldb_settings['mysql']['database'] . 
					   ';charset=utf8',
		 $ldb_settings['mysql']['login'],
		 $ldb_settings['mysql']['password']);
		 return $dbo;
	} catch (PDOException $ex) {
		trigger_error('[LocalDB] Error connecting to MySQL server: ' . $ex->getMessage(), E_USER_ERROR);
		return null;
	}
}  // connect_to_db

function is_connection_alive(PDO $dbo) {
	if ($dbo === null) {
		return false;
	}
	try {
		$dbo->query("DO 1;");
		return true;
	} catch (PDOException $ex) {
		return false;
	}
	return true;
}  // is_connection_alive
?>
