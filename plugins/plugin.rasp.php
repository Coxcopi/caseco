<?php
/* vim: set noexpandtab tabstop=2 softtabstop=2 shiftwidth=2: */
global $aseco;

/**
 * RASP plugin.
 * Provides rank & personal best handling, and related chat commands.
 * Updated by Xymph
 *
 * Dependencies: requires plugin.rasp_karma.php
 */

$aseco->registerEvent('onStartup', 'event_onstartup');
$aseco->registerEvent('onSync', 'event_onsync');
$aseco->registerEvent('onNewChallenge2', 'event_newtrack');  // use 2nd event for logical ordering of rank/karma messages
$aseco->registerEvent('onEndRace', 'event_endrace');
$aseco->registerEvent('onPlayerFinish', 'event_finish');
$aseco->registerEvent('onPlayerConnect', 'event_playerjoin');

if (!INHIBIT_RECCMDS) {
	$aseco->addChatCommand('pb', 'Shows your personal best on current track');
}
$aseco->addChatCommand('rank', 'Shows your current server rank');
$aseco->addChatCommand('top10', 'Displays top 10 best ranked players');
$aseco->addChatCommand('top100', 'Displays top 100 best ranked players');
$aseco->addChatCommand('topwins', 'Displays top 100 victorious players');
$aseco->addChatCommand('active', 'Displays top 100 most active players');

class Rasp {
	var $aseco;
	var $features;
	var $ranks;
	var $settings;
	var $challenges;
	var $responses;
	var $maxrec;
	var $playerlist;

	function start($aseco_ext, $config_file) {
		global $maxrecs;

		$this->aseco = $aseco_ext;
		$this->aseco->console('[RASP] Loading config file [' . $config_file . ']');
		if (!$this->settings = $this->xmlparse($config_file)) {
			trigger_error('{RASP_ERROR} Could not read/parse config file ' . $config_file . ' !', E_USER_ERROR);
		} else {
			$this->aseco->console('[RASP] Checking database structure...');
			if (!$this->checkTables()) {
				trigger_error('{RASP_ERROR} Table structure incorrect!  Use localdb/rasp.sql to correct this', E_USER_ERROR);
			}
			$this->aseco->console('[RASP] ...Structure OK!');
			$this->aseco->server->records->setLimit($maxrecs);
			$this->cleanData();
		}
	}  // start

	function xmlparse($config_file) {

		if ($settings = $this->aseco->xml_parser->parseXml($config_file)) {
			$this->messages = $settings['RASP']['MESSAGES'][0];
			return $settings;
		} else {
			return false;
		}
	}  // xmlparse

	function checkTables() {
		global $dbo;

		// create rs_* tables if needed
		$query = 'CREATE TABLE IF NOT EXISTS `rs_karma` (
		           `Id` int(11) NOT NULL auto_increment,
		           `ChallengeId` mediumint(9) NOT NULL default 0,
		           `PlayerId` mediumint(9) NOT NULL default 0,
		           `Score` tinyint(4) NOT NULL default 0,
		           PRIMARY KEY (`Id`),
		           UNIQUE KEY `PlayerId` (`PlayerId`,`ChallengeId`),
		           KEY `ChallengeId` (`ChallengeId`)
		         ) ENGINE=MyISAM';
		$dbo->query($query);

		$query = 'CREATE TABLE IF NOT EXISTS `rs_rank` (
		           `playerID` mediumint(9) NOT NULL default 0,
		           `avg` float NOT NULL default 0,
		           KEY `playerID` (`playerID`)
		         ) ENGINE=MyISAM';
		$dbo->query($query);

		$query = 'CREATE TABLE IF NOT EXISTS `rs_times` (
		           `ID` int(11) NOT NULL auto_increment,
		           `challengeID` mediumint(9) NOT NULL default 0,
		           `playerID` mediumint(9) NOT NULL default 0,
		           `score` int(11) NOT NULL default 0,
		           `date` int(10) unsigned NOT NULL default 0,
		           `checkpoints` text NOT NULL,
		           PRIMARY KEY (`ID`),
		           KEY `playerID` (`playerID`,`challengeID`),
		           KEY `challengeID` (`challengeID`)
		         ) ENGINE=MyISAM';
		$dbo->query($query);

		// check for rs_* tables
		$tables = array();
		$res = $dbo->query('SHOW TABLES');
		while ($row = $res->fetch(PDO::FETCH_NUM))
			$tables[] = $row[0];
		$res = null;
		$check = array();
		$check[1] = in_array('rs_rank', $tables);
		$check[2] = in_array('rs_times', $tables);
		$check[3] = in_array('rs_karma', $tables);

		// get list of rs_times columns
		$fields = array();
		$res = $dbo->query('SHOW COLUMNS FROM rs_times');
		while ($row = $res->fetch(PDO::FETCH_NUM))
			$fields[] = $row[0];
		$res = null;

		// rename column 'trackID' (v0.7) to 'challengeID' (v0.8+) if not yet done
		if (in_array('trackID', $fields)) {
			$this->aseco->console("[RASP] Rename 'rs_times' column 'trackID'...");
			$dbo->query('ALTER TABLE rs_times CHANGE trackID challengeID mediumint(9) NOT NULL default 0');
		}
		// add rs_times 'checkpoints' column
		if (!in_array('checkpoints', $fields)) {
			$this->aseco->console("[RASP] Add 'rs_times' column 'trackID'...");
			$dbo->query('ALTER TABLE rs_times ADD checkpoints text NOT NULL');
		}

		// enlarge rs_times 'ID' & 'score' columns
		$res = $dbo->query('DESC rs_times ID');
		$row = $res->fetch(PDO::FETCH_NUM);
		$res = null;
		if ($row[1] != 'int(11)') {
			$this->aseco->console("[RASP] Alter 'rs_times' column 'ID'...");
			$dbo->query('ALTER TABLE rs_times MODIFY ID int(11) auto_increment');
		}
		$res = $dbo->query('DESC rs_times score');
		$row = $res->fetch(PDO::FETCH_NUM);
		$res = null;
		if ($row[1] != 'int(11)') {
			$this->aseco->console("[RASP] Alter 'rs_times' column 'score'...");
			$dbo->query('ALTER TABLE rs_times MODIFY score int(11) NOT NULL default 0');
		}

		// change rs_times old 'rs_times_player_track' key into new 'playerID' key
		//  and add rs_times new 'ChallengeId' key
		$fields = array('rs_times_player_track' => 0, 'challengeID' => 0);
		$result = $dbo->query('SHOW INDEX FROM rs_times');
		while ($row = $result->fetch(PDO::FETCH_NUM)) {
			if (isset($fields[$row[2]]))
				$fields[$row[2]]++;
		}
		$result = null;
		if ($fields['rs_times_player_track'] == 2 && $fields['challengeID'] == 0) {
			$this->aseco->console("[RASP] Drop 'rs_times' key 'rs_times_player_track'...");
			$dbo->query("ALTER TABLE rs_times DROP KEY rs_times_player_track");
			$this->aseco->console("[RASP] Add 'rs_times' key 'playerID'...");
			$dbo->query("ALTER TABLE rs_times ADD KEY playerID (playerID, challengeID)");
			$this->aseco->console("[RASP] Add 'rs_times' key 'challengeID'...");
			$dbo->query("ALTER TABLE rs_times ADD KEY challengeID (challengeID)");
		}

		// reduce rs_karma 'Score' column
		$res = $dbo->query('DESC rs_karma Score');
		$row = $res->fetch(PDO::FETCH_NUM);
		$res = null;
		if ($row[1] != 'tinyint(4)') {
			$this->aseco->console("[RASP] Alter 'rs_karma' column 'score'...");
			$dbo->query('ALTER TABLE rs_karma MODIFY Score tinyint(4) NOT NULL default 0');
		}

		return ($check[1] && $check[2] && $check[3]);
	}  // checkTables

	function cleanData () {
		global $prune_records_times, $dbo;

		$this->aseco->console('[RASP] Cleaning up unused data');
		$sql = "DELETE FROM challenges WHERE uid=''";
		$dbo->query($sql);
		$sql = "DELETE FROM players WHERE login=''";
		$dbo->query($sql);

		if (!$prune_records_times) return;
		// prune records and rs_times entries for players & challenges deleted from database

		$deletelist = array();
		$sql = 'SELECT DISTINCT r.ChallengeId,c.Id FROM records r LEFT JOIN challenges c ON (r.ChallengeId=c.Id) WHERE c.Id IS NULL';
		$res = $dbo->query($sql);
		if ($res->rowCount() > 0) {
			while ($row = $res->fetch(PDO::FETCH_NUM))
				$deletelist[] = $row[0];
			$this->aseco->console('[RASP] ...Deleting records for deleted challenges: ' . implode(',', $deletelist));
			$sql = 'DELETE FROM records WHERE ChallengeId IN (' . implode(',', $deletelist) . ')';
			$dbo->query($sql);
		}
		$res = null;

		$deletelist = array();
		$sql = 'SELECT DISTINCT r.PlayerId,p.Id FROM records r LEFT JOIN players p ON (r.PlayerId=p.Id) WHERE p.Id IS NULL';
		$res = $dbo->query($sql);
		if ($res->rowCount() > 0) {
			while ($row = $res->fetch(PDO::FETCH_NUM))
				$deletelist[] = $row[0];
			$this->aseco->console('[RASP] ...Deleting records for deleted players: ' . implode(',', $deletelist));
			$sql = 'DELETE FROM records WHERE PlayerId IN (' . implode(',', $deletelist) . ')';
			$dbo->query($sql);
		}
		$res = null;

		$deletelist = array();
		$sql = 'SELECT DISTINCT r.challengeID,c.Id FROM rs_times r LEFT JOIN challenges c ON (r.challengeID=c.Id) WHERE c.Id IS NULL';
		$res = $dbo->query($sql);
		if ($res->rowCount() > 0) {
			while ($row = $res->fetch(PDO::FETCH_NUM))
				$deletelist[] = $row[0];
			$this->aseco->console('[RASP] ...Deleting rs_times for deleted challenges: ' . implode(',', $deletelist));
			$sql = 'DELETE FROM rs_times WHERE challengeID IN (' . implode(',', $deletelist) . ')';
			$dbo->query($sql);
		}
		$res = null;

		$deletelist = array();
		$sql = 'SELECT DISTINCT r.playerID,p.Id FROM rs_times r LEFT JOIN players p ON (r.playerID=p.Id) WHERE p.Id IS NULL';
		$res = $dbo->query($sql);
		if ($res->rowCount() > 0) {
			while ($row = $res->fetch(PDO::FETCH_NUM))
				$deletelist[] = $row[0];
			$this->aseco->console('[RASP] ...Deleting rs_times for deleted players: ' . implode(',', $deletelist));
			$sql = 'DELETE FROM rs_times WHERE playerID IN (' . implode(',', $deletelist) . ')';
			$dbo->query($sql);
		}
		$res = null;
	}  // cleanData

	function getChallenges() {
		global $dbo;

		// get new/cached list of tracks
		$newlist = getChallengesCache($this->aseco);  // from rasp.funcs.php

		foreach ($newlist as $row) {
			$tid = $this->aseco->getChallengeId($row['UId']);
			// insert in case it wasn't in the database yet
			if ($tid == 0) {
				$query = 'INSERT INTO challenges (Uid, Name, Author, Environment)
				          VALUES (' . quotedString($row['UId']) . ', ' . quotedString($row['Name']) . ', '
				                    . quotedString($row['Author']) . ', ' . quotedString($row['Environnement']) . ')';
				$result = $dbo->query($query);
				if ($result->rowCount() != 1) {
					trigger_error('{RASP_ERROR} Could not insert challenge! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
				} else {
					$tid = $dbo->lastInsertId();
				}
			}
			if ($tid != 0)
				$tlist[] = $tid;
		}

		// check for missing challenge list
		if (empty($tlist)) {
			trigger_error('{RASP_ERROR} Cannot obtain challenge list from server and/or database - check configuration files!', E_USER_ERROR);
		}
		$this->challenges = $tlist;
	}  // getChallenges

	// called @ onSync
	function onSync($aseco, $data) {
		global $tmxdir, $tmxtmpdir, $feature_tmxadd;

		$sepchar = substr($aseco->server->trackdir, -1, 1);
		if ($sepchar == '\\') {
			$tmxdir = str_replace('/', $sepchar, $tmxdir);
		}

		if (!file_exists($aseco->server->trackdir . $tmxdir)) {
			if (!mkdir($aseco->server->trackdir . $tmxdir)) {
				$aseco->console_text('{RASP_ERROR} TMX Directory (' . $aseco->server->trackdir . $tmxdir . ') cannot be created');
			}
		}

		if (!is_writeable($aseco->server->trackdir . $tmxdir)) {
			$aseco->console_text('{RASP_ERROR} TMX Directory (' . $aseco->server->trackdir . $tmxdir . ') cannot be written to');
		}

		// check if user /add votes are enabled
		if ($feature_tmxadd) {
			if (!file_exists($aseco->server->trackdir . $tmxtmpdir)) {
				if (!mkdir($aseco->server->trackdir . $tmxtmpdir)) {
					$aseco->console_text('{RASP_ERROR} TMXtmp Directory (' . $aseco->server->trackdir . $tmxtmpdir . ') cannot be created');
					$feature_tmxadd = false;
				}
			}

			if (!is_writeable($aseco->server->trackdir . $tmxtmpdir)) {
				$aseco->console_text('{RASP_ERROR} TMXtmp Directory (' . $aseco->server->trackdir . $tmxtmpdir . ') cannot be written to');
				$feature_tmxadd = false;
			}
		}
	}  // onSync

	function resetRanks() {
		global $maxrecs, $minrank, $dbo;

		$players = array();
		$this->aseco->console('[RASP] Calculating ranks...');
		$this->getChallenges();
		$tracks = $this->challenges;
		$total = count($tracks);

		// erase old average data
		$dbo->query('TRUNCATE TABLE rs_rank');

		// get list of players with at least $minrecs records (possibly unranked)
		$query = 'SELECT PlayerId, COUNT(*) AS cnt
		          FROM records
		          GROUP BY PlayerId
		          HAVING cnt >=' . $minrank;
		$res = $dbo->query($query);
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$players[$row->PlayerId] = array(0, 0);  // sum, count
		}
		$res = null;

		if (!empty($players)) {
			// get ranked records for all tracks
			$order = ($this->aseco->server->gameinfo->mode == Gameinfo::STNT ? 'DESC' : 'ASC');
			foreach ($tracks as $track) {
				$query = 'SELECT PlayerId FROM records
				          WHERE challengeid=' . $track . '
				          ORDER BY score ' . $order . ', date ASC
				          LIMIT ' . $maxrecs;
				$res = $dbo->query($query);
				if ($res->rowCount() > 0) {
					$i = 1;
					while ($row = $res->fetch(PDO::FETCH_OBJ)) {
						$pid = $row->PlayerId;
						if (isset($players[$pid])) {
							$players[$pid][0] += $i;
							$players[$pid][1] ++;
						}
						$i++;
					}
				}
				$res = null;
			}

			// one-shot insert for queries up to 1 MB (default max_allowed_packet),
			// or about 75K rows at 14 bytes/row (avg)
			$query = 'INSERT INTO rs_rank VALUES ';
			// compute each player's new average score
			foreach ($players as $player => $ranked) {
				// ranked tracks sum + $maxrecs rank for all remaining tracks
				$avg = ($ranked[0] + ($total - $ranked[1]) * $maxrecs) / $total;
				$query .= '(' . $player . ',' . round($avg * 10000) . '),';
			}
			$query = substr($query, 0, strlen($query)-1);  // strip trailing ','
			$result = $dbo->query($query);
			if ($result->rowCount() < 1) {
				trigger_error('{RASP_ERROR} Could not insert any player averages! (' . errInfo2text($result->errorInfo()) . ')', E_USER_WARNING);
			} elseif ($result->rowCount() != count($players)) {
				trigger_error('{RASP_ERROR} Could not insert all ' . count($players) . ' player averages! (' . errInfo2text($result->errorInfo()) . ')', E_USER_WARNING);
				// increase MySQL's max_allowed_packet setting
			}
		}
		$this->aseco->console('[RASP] ...Done!');
	}  // resetRanks

	// called @ onPlayerConnect
	function onPlayerjoin($aseco, $player) {
		global $feature_ranks, $feature_stats, $always_show_pb;

		if ($feature_ranks)
			$this->showRank($player->login);
		if ($feature_stats)
			$this->showPb($player, $aseco->server->challenge->id, $always_show_pb);
	}  // onPlayerjoin

	function showPb($player, $track, $always_show) {
		global $maxrecs, $maxavg, $dbo;

		$found = false;
		// find ranked record
		for ($i = 0; $i < $maxrecs; $i++) {
			if (($rec = $this->aseco->server->records->getRecord($i)) !== false) {
				if ($rec->player->login == $player->login) {
					$ret['time'] = $rec->score;
					$ret['rank'] = $i + 1;
					$found = true;
					break;
				}
			} else {
				break;
			}
		}

		// check whether to show PB (e.g. for /pb)
		if (!$always_show) {
			// check for ranked record that's already shown at track start,
			// or for player's records panel showing it
			if (($found && $this->aseco->settings['show_recs_before'] == 2) ||
			    $player->panels['records'] != '')
				return;
		}

		if (!$found) {
			// find unranked time/score
			$order = ($this->aseco->server->gameinfo->mode == Gameinfo::STNT ? 'DESC' : 'ASC');
			$query2 = 'SELECT score FROM rs_times
			           WHERE playerID=' . $player->id . ' AND challengeID=' . $track . '
			           ORDER BY score ' . $order . ' LIMIT 1';
			$res2 = $dbo->query($query2);
			if ($res2->rowCount() > 0) {
				$row = $res2->fetch(PDO::FETCH_OBJ);
				$ret['time'] = $row->score;
				$ret['rank'] = '$nUNRANKED$m';
				$found = true;
			}
			$res2 = null;
		}

		// compute average time of last $maxavg times
		$query = 'SELECT score FROM rs_times
		          WHERE playerID=' . $player->id . ' AND challengeID=' . $track . '
		          ORDER BY date DESC LIMIT ' . $maxavg;
		$res = $dbo->query($query);
		$size = $res->rowCount();
		if ($size > 0) {
			$total = 0;
			while ($row = $res->fetch(PDO::FETCH_OBJ)) {
				$total += $row->score;
			}
			$avg = floor($total / $size);
			if ($this->aseco->server->gameinfo->mode != Gameinfo::STNT)
				$avg = formatTime($avg);
		} else {
			$avg = 'No Average';
		}
		$res = null;

		if ($found) {
			$message = formatText($this->messages['PB'][0],
			                      ($this->aseco->server->gameinfo->mode == Gameinfo::STNT ?
			                       $ret['time'] : formatTime($ret['time'])),
			                      $ret['rank'], $avg);
			$message = $this->aseco->formatColors($message);
			$this->aseco->client->query('ChatSendServerMessageToLogin', $message, $player->login);
		} else {
			$message = $this->messages['PB_NONE'][0];
			$message = $this->aseco->formatColors($message);
			$this->aseco->client->query('ChatSendServerMessageToLogin', $message, $player->login);
		}
	}  // showPb

	function getPb($login, $track) {
		global $maxrecs, $dbo;

		$found = false;
		// find ranked record
		for ($i = 0; $i < $maxrecs; $i++) {
			if (($rec = $this->aseco->server->records->getRecord($i)) !== false) {
				if ($rec->player->login == $login) {
					$ret['time'] = $rec->score;
					$ret['rank'] = $i + 1;
					$found = true;
					break;
				}
			} else {
				break;
			}
		}

		if (!$found) {
			$pid = $this->aseco->getPlayerId($login);
			// find unranked time/score
			$order = ($this->aseco->server->gameinfo->mode == Gameinfo::STNT ? 'DESC' : 'ASC');
			$query2 = 'SELECT score FROM rs_times
			           WHERE playerID=' . $pid . ' AND challengeID=' . $track . '
			           ORDER BY score ' . $order . ' LIMIT 1';
			$res2 = $dbo->query($query2);
			if ($res2->rowCount() > 0) {
				$row = $res2->fetch(PDO::FETCH_OBJ);
				$ret['time'] = $row->score;
				$ret['rank'] = '$nUNRANKED$m';
			} else {
				$ret['time'] = 0;
				$ret['rank'] = '$nNONE$m';
			}
			$res2 = null;
		}

		return $ret;
	}  // getPb

	function showRank($login) {
		global $minrank, $dbo;

		$pid = $this->aseco->getPlayerId($login);
		$query = 'SELECT avg FROM rs_rank
		          WHERE playerID=' . $pid;
		$res = $dbo->query($query);
		if ($res->rowCount() > 0) {
			$row = $res->fetch(PDO::FETCH_ASSOC);
			$query2 = 'SELECT playerid FROM rs_rank ORDER BY avg ASC';
			$res2 = $dbo->query($query2);
			$rank = 1;
			while ($row2 = $res2->fetch(PDO::FETCH_ASSOC)) {
				if ($row2['playerid'] == $pid) break;
				$rank++;
			}
			$message = formatText($this->messages['RANK'][0],
			                      $rank, $res2->rowCount(),
			                      sprintf("%4.1F", $row['avg'] / 10000));
			$message = $this->aseco->formatColors($message);
			$this->aseco->client->query('ChatSendServerMessageToLogin', $message, $login);
			$res2 = null;
		} else {
			$message = formatText($this->messages['RANK_NONE'][0], $minrank);
			$message = $this->aseco->formatColors($message);
			$this->aseco->client->query('ChatSendServerMessageToLogin', $message, $login);
		}
		$res = null;
	}  // showRank

	function getRank($login) {
		global $dbo;

		$pid = $this->aseco->getPlayerId($login);
		$query = 'SELECT avg FROM rs_rank
		          WHERE playerID=' . $pid;
		$res = $dbo->query($query);
		if ($res->rowCount() > 0) {
			$row = $res->fetch(PDO::FETCH_ASSOC);
			$query2 = 'SELECT playerid FROM rs_rank ORDER BY avg ASC';
			$res2 = $dbo->query($query2);
			$rank = 1;
			while ($row2 = $res2->fetch(PDO::FETCH_ASSOC)) {
				if ($row2['playerid'] == $pid) break;
				$rank++;
			}
			$message = formatText('{1}/{2} Avg: {3}',
			                      $rank, $res2->rowCount(),
			                      sprintf("%4.1F", $row['avg'] / 10000));
			$res2 = null;
		} else {
			$message = 'None';
		}
		$res = null;
		return $message;
	}  // getRank

	// called @ onPlayerFinish
	function onFinish($aseco, $finish_item) {
		global $feature_stats,
		       $checkpoints;  // from plugin.checkpoints.php

		// check for actual finish & no Laps mode
		if ($feature_stats && $finish_item->score > 0 && $aseco->server->gameinfo->mode != Gameinfo::LAPS) {
			$this->insertTime($finish_item, isset($checkpoints[$finish_item->player->login]) ?
			                  implode(',', $checkpoints[$finish_item->player->login]->curr_cps) : '');
		}
	}  // onFinish

	// called @ onNewChallenge2
	function onNewtrack($aseco, $challenge) {
		global $feature_karma, $feature_stats, $always_show_pb, $karma_show_start, $karma_show_votes;

		if ($feature_stats && !$aseco->server->isrelay) {
			foreach ($aseco->server->players->player_list as $pl)
				$this->showPb($pl, $challenge->id, $always_show_pb);
		}
		if ($feature_karma && $karma_show_start &&
		    function_exists('rasp_karma')) {
			// show players' actual votes, or global karma message?
			if ($karma_show_votes) {
				// send individual player messages
				foreach ($aseco->server->players->player_list as $pl)
					rasp_karma($challenge->id, $pl->login);
			} else {
				// send more efficient global message
				rasp_karma($challenge->id, false);
			}
		}
	}  // onNewtrack

	function insertTime($time, $cps) {
		global $dbo;

		$pid = $time->player->id;
		if ($pid != 0) {
			$query = 'INSERT INTO rs_times (playerID, challengeID, score, date, checkpoints)
			          VALUES (' . $pid . ', ' . $time->challenge->id . ', ' . $time->score . ', '
			                    . quotedString(time()) . ', ' . quotedString($cps) . ')';
			$result = $dbo->query($query);
			if ($result->rowCount() != 1) {
				trigger_error('{RASP_ERROR} Could not insert time! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			}
		} else {
			trigger_error('{RASP_ERROR} Could not get Player ID for ' . $time->player->login . ' !', E_USER_WARNING);
		}
	}  // insertTime

	function deleteTime($cid, $pid) {
		global $dbo;

		$query = 'DELETE FROM rs_times WHERE challengeID=' . $cid . ' AND playerID=' . $pid;
		$result = $dbo->query($query);
		if ($result->rowCount() <= 0) {
			trigger_error('{RASP_ERROR} Could not remove time(s)! (' . errInfo2text($result->errorInfo()) . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		}
	}  // deleteTime

	// called @ onEndRace
	function onEndrace($aseco, $data) {
		global $feature_ranks, $tmxplayed;

		// check for relay server
		if ($aseco->server->isrelay) return;

		if ($feature_ranks) {
			if (!$tmxplayed) {
				$this->resetRanks();
			}
			if ($aseco->server->getGame() != 'TMF' || !$aseco->settings['sb_stats_panels']) {
				foreach ($aseco->server->players->player_list as $pl)
					$this->showRank($pl->login);
			}
		}
	}  // onEndrace

}  // class Rasp

// These functions pass the callback data to the Rasp class...
function event_onsync($aseco, $data) { global $rasp; $rasp->onSync($aseco, $data); }
function event_finish($aseco, $data) { global $rasp; $rasp->onFinish($aseco, $data); }
function event_newtrack($aseco, $data) { global $rasp; $rasp->onNewtrack($aseco, $data); }
function event_endrace($aseco, $data) { global $rasp; $rasp->onEndrace($aseco, $data); }
function event_playerjoin($aseco, $data) { global $rasp; $rasp->onPlayerjoin($aseco, $data); }


// Chat commands...

function chat_pb($aseco, $command) {
	global $rasp, $feature_stats;

	// check for relay server
	if ($aseco->server->isrelay) {
		$message = formatText($aseco->getChatMessage('NOTONRELAY'));
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $command['author']->login);
		return;
	}

	if ($feature_stats) {
		$rasp->showPb($command['author'], $aseco->server->challenge->id, true);
	}
}  // chat_pb

function chat_rank($aseco, $command) {
	global $rasp, $feature_ranks;

	if ($feature_ranks) {
		$rasp->showRank($command['author']->login);
	}
}  // chat_rank

function chat_top10($aseco, $command) {
	global $dbo;

	$player = $command['author'];

	if ($aseco->server->getGame() == 'TMN') {
		$recs = 'Current TOP 10 Players:';
		$top = 10;
		$bgn = '{#black}';  // nickname begin
		$end = '$z';  // ... & end colors
	} elseif ($aseco->server->getGame() == 'TMF') {
		$header = 'Current TOP 10 Players:';
		$recs = array();
		$top = 10;
		$bgn = '{#black}';  // nickname begin
	} else {  // TMS/TMO
		$recs = '{#server}> Current TOP 4 Players:{#highlite}';
		$top = 4;
		$bgn = '{#highlite}';
		$end = '{#highlite}';
	}

	$query = 'SELECT p.NickName, r.avg FROM players p
	          LEFT JOIN rs_rank r ON (p.Id=r.PlayerId)
	          WHERE r.avg!=0 ORDER BY r.avg ASC LIMIT ' . $top;
	$res = $dbo->query($query);

	if ($res->rowCount() == 0) {
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors('{#server}> {#error}No ranked players found!'), $player->login);
		$res = null;
		return;
	}

	if ($aseco->server->getGame() == 'TMN') {
		$i = 1;
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$nick = $row->NickName;
			if (!$aseco->settings['lists_colornicks'])
				$nick = stripColors($nick);
			$recs .= LF . $i . '.  ' . $bgn . str_pad($nick, 20)
			         . $end . ' - ' . sprintf("%4.1F", $row->avg / 10000);
			$i++;
		}

		// display popup message
		$aseco->client->query('SendDisplayServerMessageToLogin', $player->login, $aseco->formatColors($recs), 'OK', '', 0);

	} elseif ($aseco->server->getGame() == 'TMF') {
		$i = 1;
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$nick = $row->NickName;
			if (!$aseco->settings['lists_colornicks'])
				$nick = stripColors($nick);
			$recs[] = array($i . '.',
			                $bgn . $nick,
			                sprintf("%4.1F", $row->avg / 10000));
			$i++;
		}

		// reserve extra width for $w tags
		$extra = ($aseco->settings['lists_colornicks'] ? 0.2 : 0);
		// display ManiaLink message
		display_manialink($player->login, $header, array('BgRaceScore2', 'LadderRank'), $recs, array(0.7+$extra, 0.1, 0.45+$extra, 0.15), 'OK');

	} else {  // TMS/TMO
		$i = 1;
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$recs .= LF . $i . '.  ' . $bgn . str_pad(stripColors($row->NickName), 15)
			         . $end . ' - ' . sprintf("%4.1F", $row->avg / 10000);
			$i++;
		}

		// show chat message
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($recs), $player->login);
	}
	$res = null;
}  // chat_top10

function chat_top100($aseco, $command) {
	global $dbo;

	$player = $command['author'];

	if ($aseco->server->getGame() == 'TMN') {
		$head = 'Current TOP 100 Players:';
		$top = 100;
		$bgn = '{#black}';  // nickname begin
		$end = '$z';  // ... & end colors
	} elseif ($aseco->server->getGame() == 'TMF') {
		$head = 'Current TOP 100 Players:';
		$top = 100;
		$bgn = '{#black}';  // nickname begin
	} else {  // TMS/TMO
		$message = '{#server}> {#error}Command unavailable, use {#highlite}$i/top10 {#error}instead.';
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $player->login);
		return;
	}

	$query = 'SELECT p.NickName, r.avg FROM players p
	          LEFT JOIN rs_rank r ON (p.Id=r.PlayerId)
	          WHERE r.avg!=0 ORDER BY r.avg ASC LIMIT ' . $top;
	$res = $dbo->query($query);

	if ($res->rowCount() == 0) {
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors('{#server}> {#error}No ranked players found!'), $player->login);
		$res = null;
		return;
	}

	if ($aseco->server->getGame() == 'TMN') {
		$recs = '';
		$lines = 0;
		$player->msgs = array();
		$player->msgs[0] = 1;
		$i = 1;
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$nick = $row->NickName;
			if (!$aseco->settings['lists_colornicks'])
				$nick = stripColors($nick);
			$recs .= LF . str_pad($i, 2, '0', STR_PAD_LEFT) . '.  ' . $bgn
			         . str_pad($nick, 20) . $end . ' - '
			         . sprintf("%4.1F", $row->avg / 10000);
			$i++;
			if (++$lines > 9) {
				$player->msgs[] = $aseco->formatColors($head . $recs);
				$lines = 0;
				$recs = '';
			}
		}
		// add if last batch exists
		if ($recs != '')
			$player->msgs[] = $aseco->formatColors($head . $recs);

		// display popup message
		if (count($player->msgs) == 2) {
			$aseco->client->query('SendDisplayServerMessageToLogin', $player->login, $player->msgs[1], 'OK', '', 0);
		} elseif (count($player->msgs) > 2) {
			$aseco->client->query('SendDisplayServerMessageToLogin', $player->login, $player->msgs[1], 'Close', 'Next', 0);
		}  // == 1, no message

	} elseif ($aseco->server->getGame() == 'TMF') {
		$recs = array();
		$lines = 0;
		$player->msgs = array();
		// reserve extra width for $w tags
		$extra = ($aseco->settings['lists_colornicks'] ? 0.2 : 0);
		$player->msgs[0] = array(1, $head, array(0.7+$extra, 0.1, 0.45+$extra, 0.15), array('BgRaceScore2', 'LadderRank'));
		$i = 1;
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$nick = $row->NickName;
			if (!$aseco->settings['lists_colornicks'])
				$nick = stripColors($nick);
			$recs[] = array(str_pad($i, 2, '0', STR_PAD_LEFT) . '.',
			                $bgn . $nick,
			                sprintf("%4.1F", $row->avg / 10000));
			$i++;
			if (++$lines > 14) {
				$player->msgs[] = $recs;
				$lines = 0;
				$recs = array();
			}
		}
		// add if last batch exists
		if (!empty($recs))
			$player->msgs[] = $recs;

		// display ManiaLink message
		display_manialink_multi($player);
	}

	$res = null;
}  // chat_top100

function chat_topwins($aseco, $command) {
	global $dbo;

	$player = $command['author'];

	if ($aseco->server->getGame() == 'TMN') {
		$head = 'Current TOP 100 Victors:';
		$top = 100;
		$bgn = '{#black}';  // nickname begin
		$end = '$z';  // ... & end colors
	} elseif ($aseco->server->getGame() == 'TMF') {
		$head = 'Current TOP 100 Victors:';
		$top = 100;
		$bgn = '{#black}';  // nickname begin
	} else {
		$head = '{#server}> Current TOP 4 Victors:{#highlite}';
		$top = 4;
		$bgn = '{#highlite}';
		$end = '{#highlite}';
	}

	$query = 'SELECT NickName, Wins FROM players ORDER BY Wins DESC LIMIT ' . $top;
	$res = $dbo->query($query);

	if ($aseco->server->getGame() == 'TMN') {
		$wins = '';
		$i = 1;
		$lines = 0;
		$player->msgs = array();
		$player->msgs[0] = 1;
		if ($res->rowCount() > 0) {
			while ($row = $res->fetch(PDO::FETCH_OBJ)) {
				$nick = $row->NickName;
				if (!$aseco->settings['lists_colornicks'])
					$nick = stripColors($nick);
				$wins .= LF . str_pad($i, 2, '0', STR_PAD_LEFT) . '.  ' . $bgn
								 . str_pad($nick, 20) . $end . ' - ' . $row->Wins;
				$i++;
				if (++$lines > 9) {
					$player->msgs[] = $aseco->formatColors($head . $wins);
					$lines = 0;
					$wins = '';
				}
			}
		}
		// add if last batch exists
		if ($wins != '')
			$player->msgs[] = $aseco->formatColors($head . $wins);

		// display popup message
		if (count($player->msgs) == 2) {
			$aseco->client->query('SendDisplayServerMessageToLogin', $player->login, $player->msgs[1], 'OK', '', 0);
		} elseif (count($player->msgs) > 2) {
			$aseco->client->query('SendDisplayServerMessageToLogin', $player->login, $player->msgs[1], 'Close', 'Next', 0);
		}  // == 1, no message

	} elseif ($aseco->server->getGame() == 'TMF') {
		$wins = array();
		$i = 1;
		$lines = 0;
		$player->msgs = array();
		// reserve extra width for $w tags
		$extra = ($aseco->settings['lists_colornicks'] ? 0.2 : 0);
		$player->msgs[0] = array(1, $head, array(0.7+$extra, 0.1, 0.45+$extra, 0.15), array('BgRaceScore2', 'LadderRank'));
		if ($res->rowCount() > 0) {
			while ($row = $res->fetch(PDO::FETCH_OBJ)) {
				$nick = $row->NickName;
				if (!$aseco->settings['lists_colornicks'])
					$nick = stripColors($nick);
				$wins[] = array(str_pad($i, 2, '0', STR_PAD_LEFT) . '.',
				                $bgn . $nick,
				                $row->Wins);
				$i++;
				if (++$lines > 14) {
					$player->msgs[] = $wins;
					$lines = 0;
					$wins = array();
				}
			}
		}
		// add if last batch exists
		if (!empty($wins))
			$player->msgs[] = $wins;

		// display ManiaLink message
		display_manialink_multi($player);

	} else {  // TMS/TMO
		$wins = $head;
		$i = 1;
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$wins .= LF . $i . '.  ' . $bgn . str_pad(stripColors($row->NickName), 15)
			         . $end . ' - ' . $row->Wins;
			$i++;
		}
		// show chat message
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($wins), $player->login);
	}

	$res = null;
}  // chat_topwins

function chat_active($aseco, $command) {
	global $dbo;

	$player = $command['author'];

	if ($aseco->server->getGame() == 'TMN') {
		$head = 'TOP 100 Most Active Players:';
		$top = 100;
		$bgn = '{#black}';  // nickname begin
		$end = '$z';  // ... & end colors
	} elseif ($aseco->server->getGame() == 'TMF') {
		$head = 'TOP 100 Most Active Players:';
		$top = 100;
		$bgn = '{#black}';  // nickname begin
	} else {  // TMS/TMO
		$head = '{#server}> Most Active Players:{#highlite}';
		$top = 4;
		$bgn = '{#highlite}';
		$end = '{#highlite}';
	}

	$query = 'SELECT NickName, TimePlayed FROM players ORDER BY TimePlayed DESC LIMIT ' . $top;
	$res = $dbo->query($query);

	if ($aseco->server->getGame() == 'TMN') {
		$active = '';
		$i = 1;
		$lines = 0;
		$player->msgs = array();
		$player->msgs[0] = 1;
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$nick = $row->NickName;
			if (!$aseco->settings['lists_colornicks'])
				$nick = stripColors($nick);
			$active .= LF . str_pad($i, 2, '0', STR_PAD_LEFT) . '.  ' . $bgn
			           . str_pad($nick, 20) . $end . ' - '
			           . formatTimeH($row->TimePlayed * 1000, false);
			$i++;
			if (++$lines > 9) {
				$player->msgs[] = $aseco->formatColors($head . $active);
				$lines = 0;
				$active = '';
			}
		}
		// add if last batch exists
		if ($active != '')
			$player->msgs[] = $aseco->formatColors($head . $active);

		// display popup message
		if (count($player->msgs) == 2) {
			$aseco->client->query('SendDisplayServerMessageToLogin', $player->login, $player->msgs[1], 'OK', '', 0);
		} elseif (count($player->msgs) > 2) {
			$aseco->client->query('SendDisplayServerMessageToLogin', $player->login, $player->msgs[1], 'Close', 'Next', 0);
		}  // == 1, no message

	} elseif ($aseco->server->getGame() == 'TMF') {
		$active = array();
		$i = 1;
		$lines = 0;
		$player->msgs = array();
		// reserve extra width for $w tags
		$extra = ($aseco->settings['lists_colornicks'] ? 0.2 : 0);
		$player->msgs[0] = array(1, $head, array(0.8+$extra, 0.1, 0.45+$extra, 0.25), array('BgRaceScore2', 'LadderRank'));
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$nick = $row->NickName;
			if (!$aseco->settings['lists_colornicks'])
				$nick = stripColors($nick);
			$active[] = array(str_pad($i, 2, '0', STR_PAD_LEFT) . '.',
			                  $bgn . $nick,
			                  formatTimeH($row->TimePlayed * 1000, false));
			$i++;
			if (++$lines > 14) {
				$player->msgs[] = $active;
				$lines = 0;
				$active = array();
			}
		}
		// add if last batch exists
		if (!empty($active))
			$player->msgs[] = $active;

		// display ManiaLink message
		display_manialink_multi($player);

	} else {  // TMS/TMO
		$active = $head;
		$i = 1;
		while ($row = $res->fetch(PDO::FETCH_OBJ)) {
			$active .= LF . $i . '.  ' . $bgn . str_pad(stripColors($row->NickName), 15)
			           . $end . ' - ' . formatTimeH($row->TimePlayed * 1000, false);
			$i++;
		}
		// show chat message
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($active), $player->login);
	}

	$res = null;
}  // chat_active


// Starts the rasp plugin...

// called @ onStartup
function event_onstartup($aseco) {
	global $rasp, $prune_records_times, $dbo;

	$rasp = new Rasp();
	$rasp->start($aseco, 'rasp.xml');

	// prune records and rs_times entries for tracks deleted from server
	if ($prune_records_times) {
		$aseco->console('[RASP] Pruning records/rs_times for deleted tracks');
		$rasp->getChallenges();
		$tracks = $rasp->challenges;

		// get list of challenge IDs with records in the database
		$query = 'SELECT DISTINCT ChallengeId FROM records';
		$res = $dbo->query($query);
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$track = $row[0];
			// delete records & rs_times if it's not in server's challenge list
			if (!in_array($track, $tracks)) {
				$aseco->console('[RASP] ...challengeID: ' . $track);
				$query = 'DELETE FROM records WHERE ChallengeId=' . $track;
				$dbo->query($query);
				$query = 'DELETE FROM rs_times WHERE challengeID=' . $track;
				$dbo->query($query);
			}
		}
		$res = null;
	}
}  // event_onstartup
?>
