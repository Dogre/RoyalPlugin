<?php

namespace Dog\Helper;

use Dog\Exception\PlayerAlreadyAssignedException;
use ManiaControl\Players\Player;

class Teams
{
    private static $teams = [];

    public static function AddPlayer(Player $player, int $teamId)
    {
        foreach (self::$teams as $team) {
            if (array_key_exists($player->index, $team)) {
                throw new PlayerAlreadyAssignedException(
                    sprintf("player already part of a team")
                );
            }
        }
        self::$teams[$teamId][$player->index] = $player->nickname;
    }

    public static function RemovePlayer(Player $player)
    {
        foreach (self::$teams as $teamId => $players) {
            if (array_key_exists($player->index, $players)) {
                unset(self::$teams[$teamId][$player->index]);
            }
        }
    }

    public static function GetTeamId(Player $player)
    {
        foreach (self::$teams as $team => $players) {
            if (array_key_exists($player->index, $players)) {
                return $team;
            }
        }

        return -1;
    }

    public static function GetPlayerCount()
    {
        return count(array_merge(...self::$teams));
    }

    public static function ToJson()
    {
        $array = [];
        foreach (self::$teams as $team => $value) {
            array_push($array, '"' . $team . '":{"Members":"' . implode(' | ', $value) . '"}');
        }
        return (string) "{" . implode(",", $array) . "}";
    }

    public static function Reset()
    {
        foreach (self::$teams as $team => $value) {
            unset(self::$teams[$team]);
        }
    }
}
