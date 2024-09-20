<?php
/* vim: set noexpandtab tabstop=2 softtabstop=2 shiftwidth=2: */
global $aseco;

/**
 * Nextrank plugin.
 * Shows the next better ranked player.
 * Created by Xymph
 *
 * Dependencies: none
 */

$aseco->addChatCommand('nextrank', 'Shows the next better ranked player');

function chat_nextrank($aseco, $command) {
	global $rasp, $minrank, $feature_ranks, $nextrank_show_rp, $dbo;

	$login = $command['author']->login;

	// check for relay server
	if ($aseco->server->isrelay) {
		$message = formatText($aseco->getChatMessage('NOTONRELAY'));
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		return;
	}

	if ($feature_ranks) {
		// find current player's avg
		$query = 'SELECT avg FROM rs_rank
		          WHERE playerID=' . $command['author']->id;
		$res = $dbo->query($query);

		if ($res->fetch(PDO::FETCH_NUM) > 0) {
			$row = $res->fetch(PDO::FETCH_ASSOC);
			$avg = $row['avg'];

			// find players with better avgs
			$query = 'SELECT playerid,avg FROM rs_rank
			          WHERE avg<' . $avg . ' ORDER BY avg';
			$res2 = $dbo->query($query);

			if ($res2->rowCount() > 0) {
				// find last player before current one
				while ($row2 = $res2->fetch(PDO::FETCH_ASSOC)) {
					$pid = $row2['playerid'];
					$avg2 = $row2['avg'];
				}

				// obtain next player's info
				$query = 'SELECT login,nickname FROM players
				          WHERE id=' . $pid;
				$res3 = $dbo->query($query);
				$row3 = $res3->fetch(PDO::FETCH_ASSOC);

				$rank = $rasp->getRank($row3['login']);
				$rank = preg_replace('|^(\d+)/|', '{#rank}$1{#record}/{#highlite}', $rank);

				// show chat message
				$message = formatText($rasp->messages['NEXTRANK'][0],
				                      stripColors($row3['nickname']), $rank);
				// show difference in record positions too?
				if ($nextrank_show_rp) {
					// compute difference in record positions
					$diff = ($avg - $avg2) / 10000 * $aseco->server->gameinfo->numchall;
					$message .= formatText($rasp->messages['NEXTRANK_RP'][0], ceil($diff));
				}
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
				$res3 = null;
			} else {
				$message = $rasp->messages['TOPRANK'][0];
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			}
			$res2 = null;
		} else {
			$message = formatText($rasp->messages['RANK_NONE'][0], $minrank);
			$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		}
		$res = null;
	}
}  // chat_nextrank
?>
