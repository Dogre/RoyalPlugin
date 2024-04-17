<?php

namespace Dog;

use Dog\Helper\Account;
use Dog\Helper\Teams;
use ManiaControl\Callbacks\Structures\TrackMania\OnScoresStructure;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Callbacks\Callbacks;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Files\AsyncHttpRequest;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Admin\AuthenticationManager;
use Dog\Exception\PlayerAlreadyAssignedException;

/**
 * Add royal support
 *
 * @author  Dog
 * @version 1.01
 */
class Royal implements Plugin, CallbackListener, CommandListener, TimerListener
{
	/*
	 * Constants
	 */
	const ID = 180;
	const VERSION = 1.01;
	const NAME = 'Royal';
	const AUTHOR = 'Dog';

	// ML IDs
	const MLID_TEAMDISPLAY = 'TeamSelectionDisplay';
	const ACTION_START_MATCH = 'Royal.Match.Start';
	const ACTION_RESET_TEAMS = 'Royal.Team.Reset';
	const ACTION_JOIN_TEAM = 'Royal.Team.Join';
	const ACTION_LEAVE_TEAM = 'Royal.Team.Leave';
	const ACTION_CLOSE_DISPLAY = 'Royal.Display.Close';
	const ACTION_DISPLAY_SELECTION = 'Royal.Display';
	// Settings
	const SETTING_TEAMDISPLAY_POSX = 'Team display x pos';
	const SETTING_TEAMDISPLAY_POSY = 'Team display y pos';
	const SETTING_TEAMDISPLAY_SCALE = 'Team display scale';
	const SETTING_HTTP_POST_ENDPOINT = 'POST Endpoint URL';
	const SETTING_HTTP_POST_ENDPOINT_HEADER_KEY = 'Header Key';
	const SETTING_HTTP_POST_ENDPOINT_HEADER_VALUE = 'Header Value';
	// XML RPC 
	const XMLRPC_METHOD_ADDPLAYER = 'Club.Match.AddPlayer';
	const XMLRPC_METHOD_REMOVEPLAYER = 'Club.Match.RemovePlayer';
	// Match status
	const MATCH_IN_PROGRESS = 'Royal.MatchInProgress';
	const MATCH_INVALID = 'Royal.MatchInvalid';
	const MATCH_ENDED = 'Royal.MatchEnded';

	/**
	 * Private Properties
	 */
	/** @var ManiaControl $maniaControl */
	private $maniaControl = null;

	private $matchStatus;

	private bool $isRoyalMode = false;
	/**
	 * @see \ManiaControl\Plugins\Plugin::prepare()
	 */
	public static function prepare(ManiaControl $maniaControl)
	{
		// TODO: Implement prepare() method.
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::load()
	 */
	public function load(ManiaControl $maniaControl)
	{
		$this->maniaControl = $maniaControl;

		// Player Commands
		$this->maniaControl->getCommandManager()->registerCommandListener('display', $this, 'onCommandDisplay', false, 'Show the team selection screen');
		$this->maniaControl->getCommandManager()->registerCommandListener('join', $this, 'onCommandJoin', false, 'Join a team via chat command');
		$this->maniaControl->getCommandManager()->registerCommandListener('leave', $this, 'onCommandLeave', false, 'Leave a team via chat command');
		$this->maniaControl->getCommandManager()->registerCommandListener('close', $this, 'onCommandClose', false, 'Close the team selection');
		// Admin Commands
		$this->maniaControl->getCommandManager()->registerCommandListener('start', $this, 'onCommandStart', true, 'Start a royal game');
		$this->maniaControl->getCommandManager()->registerCommandListener('displayall', $this, 'onCommandDisplayAll', true, 'Show team selection to all players');
		// Maniacontrol Commands
		$this->maniaControl->getCommandManager()->registerCommandListener(['restartmap', 'resmap', 'res'], $this, 'onCommandRestart', true);
		$this->maniaControl->getCommandManager()->registerCommandListener(['nextmap', 'next', 'skip'], $this, 'onCommandRestart', true);

		// Callbacks
		$this->maniaControl->getCallbackManager()->registerCallbackListener(CallbackManager::CB_MP_PLAYERMANIALINKPAGEANSWER, $this, 'handleManialinkPageAnswer');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerConnect');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERDISCONNECT, $this, 'handlePlayerDisconnect');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_SCORES, $this, 'handleScores');

		// Timers
		$this->maniaControl->getTimerManager()->registerTimerListening($this, 'handle60Secs', 60000);

		// Settings
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_HTTP_POST_ENDPOINT, "", "");
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_HTTP_POST_ENDPOINT_HEADER_KEY, "", "");
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_HTTP_POST_ENDPOINT_HEADER_VALUE, "", "");
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_TEAMDISPLAY_POSX, 0);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_TEAMDISPLAY_POSY, 0);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_TEAMDISPLAY_SCALE, 0.8);
	}
	public function showTeamSelection(Player $player)
	{
		$manialink = '<manialink version="3" id="' . self::MLID_TEAMDISPLAY . '" >
		<framemodel id="framemodel-team">
			<quad pos="0 0" z-index="1" size="90 7" opacity="0.5" halign="center" valign="center" alphamask="file://Media/Manialinks/Nadeo/Trackmania/Modes/Race/Mode_Common_Scorestable_Header_Mask.dds" image="file://Media/Painter/Stencils/15-Stripes/_Stripe0Grad/Brush.tga" modulatecolor="ff66ff" id="quad-team-gradient"/>
			<quad pos="0 0" z-index="0" size="90 7" opacity="1" scriptevents="1" image="file://Media/Painter/Stencils/15-Stripes/_Stripe0/Brush.tga" modulatecolor="2E3E4C" alphamask="file://Media/Manialinks/Nadeo/Trackmania/Modes/Race/Mode_Common_Scorestable_Header_Mask.dds" halign="center" valign="center" id="quad-team-bg"/>
			<quad pos="-36.8 0" z-index="2" size="12 11" opacity="1" halign="center" valign="center" id="quad-team-logo" image="file://Media/Manialinks/Nadeo/CMGame/Modes/Clans/Animals/flamingo.dds"/>
			<quad pos="-2.28 -6.94" z-index="0" size="90 7" opacity="1" scriptevents="1" image="file://Media/Painter/Stencils/15-Stripes/_Stripe0/Brush.tga" modulatecolor="26323C" alphamask="file://Media/Manialinks/Nadeo/Trackmania/Modes/Race/Mode_Common_Scorestable_Header_Mask.dds" halign="center" valign="center" id="quad-player-bg" rot="180"/>
			<label pos="0 0" z-index="1" size="70 7" text="flamingo" halign="center" id="label-team-name" textfont="GameFontExtraBold" textsize="2" valign="center" textprefix="$t"/>
			<label pos="35 0" z-index="1" size="20 7" text="20" halign="center" id="label-team-id" textfont="GameFontExtraBold" textsize="2" valign="center"/>
			<label pos="-44.3 -6.94" z-index="1" size="85 7" text="" textfont="GameFontSemiBold" halign="left" textprefix="$t$i" textsize="1" id="label-players" valign="center2"/>
		</framemodel>
		<frame id="frame-global" pos="' . $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_TEAMDISPLAY_POSX) . " " . $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_TEAMDISPLAY_POSY) . '" scale="' . $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_TEAMDISPLAY_SCALE) . '">
			<frame id="frame-info" pos="0 10">
				<label pos="-115 74" z-index="1" size="0 5" text="participants 0/0" textprefix="$t" textfont="GameFontExtraBold" halign="center" valign="center" textcolor="eee" id="label-participants" textsize="1"/>
				<quad pos="-1.14 74" size="272.28 9" opacity="1" style="UICommon64_1" substyle="BgFrame1" modulatecolor="26323C" halign="center" valign="center" autoscalefixedwidth="1" id="quad-info-bg"/>
				<quad size="5 5" halign="center" valign="center" style="UICommon64_2" substyle="CloseWindow_light" pos="129 74" scriptevents="1" id="quad-close" action="' . self::ACTION_CLOSE_DISPLAY . '"/>
				<label pos="0 74.1" z-index="1" text="leave team" textprefix="$t" halign="center" style="CardButtonMediumL" scriptevents="1" valign="center" id="label-leave-team" action="' . self::ACTION_LEAVE_TEAM . '"/>
			</frame>';
		if ($this->maniaControl->getAuthenticationManager()->checkRight($player, AuthenticationManager::AUTH_LEVEL_ADMIN)) {
			$manialink .= '<frame id="frame-admin" pos="0 10">
				<label pos="-110 85" z-index="0" size="54.456 10" text="start " textfont="GameFontExtraBold" textprefix="$t" halign="center" id="label-start" action="' . self::ACTION_START_MATCH . '" scriptevents="1" valign="center2" focusareacolor2="00A565" focusareacolor1="00000000"/>
				<label pos="-55.365 85" z-index="0" size="54.456 10" text="display all" textfont="GameFontExtraBold" textprefix="$t" halign="center" id="label-display" action="' . self::ACTION_DISPLAY_SELECTION . '" scriptevents="1" valign="center2" focusareacolor2="00A565" focusareacolor1="00000000"/>
				<label pos="-54.2 81" z-index="0" size="54.456 10" text="display this window to all players" textfont="GameFontExtraBold" textprefix="$t" halign="center" valign="center2" textsize="0.1"/>
				<label pos="107.86 85" z-index="0" size="54.456 10" text="reset teams " textfont="GameFontExtraBold" textprefix="$t" halign="center" id="label-reset" action="' . self::ACTION_RESET_TEAMS . '" scriptevents="1" valign="center2" focusareacolor2="A50000FF" focusareacolor1="00000000"/>
			</frame>';
		}

		$manialink .= '
			<frame id="frame-teams">
				<frameinstance modelid="framemodel-team" pos="-90 75" data-teamid="1" />
				<frameinstance modelid="framemodel-team" pos="-90 60" data-teamid="2" />
				<frameinstance modelid="framemodel-team" pos="-90 45" data-teamid="3" />
				<frameinstance modelid="framemodel-team" pos="-90 30" data-teamid="4" />
				<frameinstance modelid="framemodel-team" pos="-90 15" data-teamid="5" />
				<frameinstance modelid="framemodel-team" pos="-90 0" data-teamid="6" />
				<frameinstance modelid="framemodel-team" pos="-90 -15" data-teamid="7" />
				<frameinstance modelid="framemodel-team" pos="-90 -30" data-teamid="8" />
				<frameinstance modelid="framemodel-team" pos="-90 -45" data-teamid="9" />
				<frameinstance modelid="framemodel-team" pos="-90 -60" data-teamid="10" />
				<frameinstance modelid="framemodel-team" pos="0 75" data-teamid="11" />
				<frameinstance modelid="framemodel-team" pos="0 60" data-teamid="12" />
				<frameinstance modelid="framemodel-team" pos="0 45" data-teamid="13" />
				<frameinstance modelid="framemodel-team" pos="0 30" data-teamid="14" />
				<frameinstance modelid="framemodel-team" pos="0 15" data-teamid="15" />
				<frameinstance modelid="framemodel-team" pos="0 0" data-teamid="16" />
				<frameinstance modelid="framemodel-team" pos="0 -15" data-teamid="17" />
				<frameinstance modelid="framemodel-team" pos="0 -30" data-teamid="18" />
				<frameinstance modelid="framemodel-team" pos="0 -45" data-teamid="19" />
				<frameinstance modelid="framemodel-team" pos="0 -60" data-teamid="20" />
				<frameinstance modelid="framemodel-team" pos="90 75" data-teamid="21" />
				<frameinstance modelid="framemodel-team" pos="90 60" data-teamid="22" />
				<frameinstance modelid="framemodel-team" pos="90 45" data-teamid="23" />
				<frameinstance modelid="framemodel-team" pos="90 30" data-teamid="24" />
				<frameinstance modelid="framemodel-team" pos="90 15" data-teamid="25" />
				<frameinstance modelid="framemodel-team" pos="90 0" data-teamid="26" />
				<frameinstance modelid="framemodel-team" pos="90 -15" data-teamid="27" />
				<frameinstance modelid="framemodel-team" pos="90 -30" data-teamid="28" />
				<frameinstance modelid="framemodel-team" pos="90 -45" data-teamid="29" />
				<frameinstance modelid="framemodel-team" pos="90 -60" data-teamid="30" />
			</frame>
		</frame>
		<script><!-- 
			#Include "Libs/Nadeo/CMGame/Modes/Clans_Client.Script.txt" as Clans
			#Include "TextLib" as TL
			
			#Struct K_RoyalTeam {
				Text Members;
			}
	
			#Struct K_ServerInfo {
				Integer NbPlayers;
				Integer NbPlayersInTeams;
			}
			
			main() {
				declare K_RoyalTeam[Text] RoyalTeams for This;
				declare K_ServerInfo ServerInfo for This;
	
				declare CMlLabel Label_Participants <=> (Page.GetFirstChild("label-participants") as CMlLabel); 
	
				declare CMlFrame Frame_Teams <=> (Page.GetFirstChild("frame-teams") as CMlFrame);
				foreach (Key => Control in Frame_Teams.Controls) {
					declare CMlQuad Quad_Team_Gradient <=> ((Control as CMlFrame).GetFirstChild("quad-team-gradient") as CMlQuad);
					declare CMlQuad Quad_Team_Bg <=> ((Control as CMlFrame).GetFirstChild("quad-team-bg") as CMlQuad);
					declare CMlQuad Quad_Team_Logo <=> ((Control as CMlFrame).GetFirstChild("quad-team-logo") as CMlQuad);
					declare CMlQuad Quad_Player_Bg <=> ((Control as CMlFrame).GetFirstChild("quad-player-bg") as CMlQuad);
					declare CMlLabel Label_Team_Name <=> ((Control as CMlFrame).GetFirstChild("label-team-name") as CMlLabel);
					declare CMlLabel Label_Team_Id <=> ((Control as CMlFrame).GetFirstChild("label-team-id") as CMlLabel);
					declare CMlLabel Label_Players <=> ((Control as CMlFrame).GetFirstChild("label-players") as CMlLabel);
	
					Label_Team_Id.SetText("""{{{Key + 1}}}""");
					Quad_Team_Gradient.ModulateColor = Clans::GetClanColor(Key + 1);
					Quad_Team_Logo.ChangeImageUrl(Clans::GetClanLogo(Key + 1));
					Label_Team_Name.SetText(Clans::GetClanName(Key + 1));
				}
	
				while (True) {
					yield;
	
					Label_Participants.SetText("""participants {{{ServerInfo.NbPlayersInTeams}}} / {{{ServerInfo.NbPlayers}}}""");
	
					foreach (Key => Control in Frame_Teams.Controls) {
						declare CMlLabel Label_Players <=> ((Control as CMlFrame).GetFirstChild("label-players") as CMlLabel);
						if (RoyalTeams.existskey("""{{{Key + 1}}}""")) {
							Label_Players.SetText(RoyalTeams["""{{{Key + 1}}}"""].Members);
						} else {
							Label_Players.SetText("");
						}
					}
	
					foreach (Event in PendingEvents) {
						if (Event.Type == CMlScriptEvent::Type::MouseOver) {
							if (Event.ControlId == "quad-team-bg") {
								declare CMlQuad Quad_Team_Bg <=> ((Event.Control.Parent as CMlFrame).GetFirstChild("quad-team-bg") as CMlQuad);
								declare Text TeamId = Event.Control.Parent.DataAttributeGet("teamid");
								Quad_Team_Bg.ModulateColor = Clans::GetClanColor(TL::ToInteger(TeamId));
							}
						}
	
						if (Event.Type == CMlScriptEvent::Type::MouseOut) {
							if (Event.ControlId == "quad-team-bg") {
								declare CMlQuad Quad_Team_Bg <=> ((Event.Control.Parent as CMlFrame).GetFirstChild("quad-team-bg") as CMlQuad);
								Quad_Team_Bg.ModulateColor = <0.18,0.243,0.298>;
							}
						}
	
						if (Event.Type == CMlScriptEvent::Type::MouseClick) {
							if (Event.ControlId == "quad-team-bg") {
								declare Text TeamId = Event.Control.Parent.DataAttributeGet("teamid");
								TriggerPageAction("""' . self::ACTION_JOIN_TEAM . '.{{{TeamId}}}""");
							}
						}
					}
				}
			}
		--></script>
	</manialink>';
		$this->maniaControl->getManialinkManager()->sendManialink($manialink, $player->login);
	}

	public function handle60Secs()
	{
		$script = $this->maniaControl->getClient()->getScriptName();
		$scriptName = str_replace(".Script.txt", "", $script["CurrentValue"]);
		// Check if the current mode is royal (be it custom or official, or stars)
		$keywords = ["TM_Royal_", "Trackmania/TM_Royal_", "TM_RoyalStars_", "Trackmania/TM_RoyalStars_"];

		foreach ($keywords as $keyword) {
			if (stripos($scriptName, $keyword) !== false) {
				$this->isRoyalMode = true;
				return $this->isRoyalMode;
			}
		}

		$this->isRoyalMode = false;
		return $this->isRoyalMode;
	}

	public function handlePlayerConnect(Player $player)
	{
		$this->updateTeamSelection();

		if ($this->isRoyalMode && $this->matchStatus !== self::MATCH_IN_PROGRESS) {
			$this->showTeamSelection($player);
		}
	}

	public function handlePlayerDisconnect(Player $player)
	{
		Teams::RemovePlayer($player);
		$this->updateTeamSelection();
	}

	public function handleScores(OnScoresStructure $struct)
	{
		if ($struct->getSection() != "EndMatchEarly" || $this->isRoyalMode == false)
			return;

		$scores = $struct->getPlainJsonObject();

		$endpoint = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_HTTP_POST_ENDPOINT);
		if ($endpoint != "" && $this->matchStatus != self::MATCH_INVALID) {
			$this->sendPostRequest($scores, $endpoint);
		}

		$this->matchStatus = self::MATCH_ENDED;
		$this->maniaControl->getChat()->sendInformation("Match has ended");
	}

	public function onCommandRestart($callback, Player $player)
	{
		$this->matchStatus = self::MATCH_INVALID;
	}

	public function onCommandDisplayAll($callback, Player $player)
	{
		$players = $this->maniaControl->getPlayerManager()->getPlayers();
		foreach ($players as $player) {
			$this->showTeamSelection($player);
		}
	}

	public function onCommandDisplay($callback, Player $player)
	{
		$this->updateTeamSelection();
		$this->showTeamSelection($player);
	}

	public function onCommandStart($callback, Player $player)
	{
		$this->startGame($player);
	}

	private function startGame(Player $player)
	{
		if (!$this->maniaControl->getAuthenticationManager()->checkRight($player, AuthenticationManager::AUTH_LEVEL_ADMIN)) {
			$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
			return;
		}

		$players = $this->maniaControl->getPlayerManager()->getPlayers();

		foreach ($players as $player) {
			if (Teams::GetTeamId($player) < 0) {
				continue;
			}

			try {
				$this->maniaControl->getClient()->triggerModeScriptEvent(self::XMLRPC_METHOD_ADDPLAYER, [
					Account::toAccountId($player->login),
					(string) Teams::GetTeamId($player)
				], true);

				$triggered = $this->maniaControl->getClient()->executeMulticall();
				if ($triggered[0]) {
					$this->matchStatus = self::MATCH_IN_PROGRESS;
					$this->maniaControl->getChat()->sendChat("Match starting...");
					$this->maniaControl->getManialinkManager()->hideManialink(self::MLID_TEAMDISPLAY);
				}
			} catch (\Exception $e) {
				Logger::logError($e->getMessage());
			}
		}
	}

	public function onCommandClose($callback, Player $player)
	{
		$this->maniaControl->getManialinkManager()->hideManialink(self::MLID_TEAMDISPLAY, $player->login);
	}

	public function onCommandLeave($callback, Player $player)
	{
		Teams::RemovePlayer($player);
		$this->updateTeamSelection();
	}

	public function onCommandJoin($callback, Player $player)
	{
		$command = $callback[1][2];

		$parts = explode(" ", $command);
		$teamId = $parts[1];

		// The team id must be a number between 1 and 30.
		if (!is_numeric($teamId) || $teamId < 1 || $teamId > 30) {
			// Not a valid team.
			$this->maniaControl->getChat()->sendError("Invalid team ID. Please provide a number between 1 and 30.", $player->login);
			return;
		}

		try {
			Teams::AddPlayer($player, $teamId);
		} catch (PlayerAlreadyAssignedException $e) {
			Teams::RemovePlayer($player);
			Teams::AddPlayer($player, $teamId);
		}

		$this->updateTeamSelection();
	}

	public function updateTeamSelection()
	{
		$this->maniaControl->getManialinkManager()->sendManialink('
					<manialink version="3" id="' . self::MLID_TEAMDISPLAY . '.Update">
					<script><!--
						#Struct K_RoyalTeam {
							Text Members;
						}

						#Struct K_ServerInfo {
							Integer NbPlayers;
							Integer NbPlayersInTeams;
						}

						main() {
							declare K_RoyalTeam[Text] RoyalTeams for This;
							declare K_ServerInfo ServerInfo for This;

							declare Text json = """' . Teams::ToJson() . '""";

							RoyalTeams.fromjson(json);

							ServerInfo = K_ServerInfo {
								NbPlayers = ' . $this->maniaControl->getPlayerManager()->getPlayerCount(false) . ',
								NbPlayersInTeams = ' . Teams::GetPlayerCount() . '
							};
						}
						

					--></script>
					</manialink>
					');
	}

	public function handleManialinkPageAnswer($callback)
	{
		$login = $callback[1][1];
		$action = $callback[1][2];
		$actionParts = explode('.', $action);
		$action = implode(".", array_slice($actionParts, 0, 3));

		$player = $this->maniaControl->getPlayerManager()->getPlayer($login);

		switch ($action) {
			case self::ACTION_JOIN_TEAM:
				$teamId = (int) end($actionParts);
				try {
					Teams::AddPlayer($player, $teamId);
					$this->updateTeamSelection();
				} catch (PlayerAlreadyAssignedException $e) {
					Teams::RemovePlayer($player);
					Teams::AddPlayer($player, $teamId);
					$this->updateTeamSelection();
				}
				break;
			case self::ACTION_LEAVE_TEAM:
				Teams::RemovePlayer($player);
				$this->updateTeamSelection();
				break;
			case self::ACTION_CLOSE_DISPLAY:
				$this->maniaControl->getManialinkManager()->hideManialink(self::MLID_TEAMDISPLAY, $player->login);
				break;
			case self::ACTION_START_MATCH:
				$this->startGame($player);
				break;
			case self::ACTION_RESET_TEAMS:
				Teams::Reset();
				$this->updateTeamSelection();
				break;
			case self::ACTION_DISPLAY_SELECTION:
				$players = $this->maniaControl->getPlayerManager()->getPlayers();
				foreach ($players as $player) {
					$this->showTeamSelection($player);
				}
				break;
		}
	}

	private function sendPostRequest($data, $url)
	{
		$asyncRequest = new AsyncHttpRequest($this->maniaControl, $url);
		$asyncRequest->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);

		if (
			!empty($this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_HTTP_POST_ENDPOINT_HEADER_KEY)) &&
			!empty($this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_HTTP_POST_ENDPOINT_HEADER_VALUE))
		) {
			$asyncRequest->setHeaders([
				$this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_HTTP_POST_ENDPOINT_HEADER_KEY) . ": " .
				$this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_HTTP_POST_ENDPOINT_HEADER_VALUE)
			]);
		}

		$asyncRequest->setContent(json_encode($data));
		$asyncRequest->setCallable(function ($response, $error) {
			$response = json_decode($response);
			if ($error || !$response) {
				Logger::logError('Error while Sending data: ' . print_r($error, true));
				return;
			}

			$this->maniaControl->getChat()->sendSuccessToAdmins("Scores successfully submitted");
		});
		$asyncRequest->postData();
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::unload()
	 */
	public function unload()
	{
		$this->maniaControl->getManialinkManager()->hideManialink('TeamSelectionDisplay');
		$this->maniaControl->getManialinkManager()->hideManialink('TeamSelectionDisplay.Update');
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getId()
	 */
	public static function getId()
	{
		return self::ID;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getName()
	 */
	public static function getName()
	{
		return self::NAME;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getVersion()
	 */
	public static function getVersion()
	{
		return self::VERSION;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getAuthor()
	 */
	public static function getAuthor()
	{
		return self::AUTHOR;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getDescription()
	 */
	public static function getDescription()
	{
		return 'This plugin allows you to host custom TM2020 royal games.';
	}
}
