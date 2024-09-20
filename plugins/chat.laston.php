<?php
/* vim: set noexpandtab tabstop=2 softtabstop=2 shiftwidth=2: */
global $aseco;

/**
 * Chat plugin.
 * Shows last online info.
 * Created by Xymph
 *
 * Dependencies: none
 */

$aseco->addChatCommand('laston', 'Shows when a player was last online');

function chat_laston($aseco, $command) {
	global $dbo;

	$player = $command['author'];
	$target = $player;

	// get player login or ID
	if ($command['params'] != '')
		if (!$target = $aseco->getPlayerParam($player, $command['params'], true))
			return;

	// obtain last online timestamp
	$query = 'SELECT UpdatedAt FROM players
	          WHERE login=' . quotedString($target->login);
	$result = $dbo->query($query);
	$laston = $result->fetch(PDO::FETCH_NUM);
	$result = null;

	// show chat message (strip seconds off timestamp)
	$message = '{#server}> Player {#highlite}' . $target->nickname .
	           '$z$s{#server} was last online on: {#highlite}' .
	           preg_replace('/:\d\d$/', '', $laston[0]);
	$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $player->login);
}  // chat_laston
?>
