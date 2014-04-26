<?php

namespace pemapmodder\legionpe\mgs\pvp;

use pemapmodder\legionpe\hub\HubPlugin;
use pemampodder\legionpe\hub\Team;

use pemapmodder\utils\CallbackEventExe as EvtExe;
use pemapmodder\utils\CallbackPluginTask as Task;

use pocketmine\Player;
use pocketmine\Server;
use pocketmine\event\Event;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\permisison\DefaultPermissions as DP;
use pocketmine\permission\Permission as Perm;

class Pvp implements Listener{
	public $pvpDies = array();
	protected $attachments = array();
	public function onJoin(Player $p){
		$this->attachments[$p->CID] = $p->addAttachment($this->hub, "legionpe.cmd.mg.pvp", true);
	}
	public function onQuit(Player $p){
		$p->removeAttachment($this->attachment[$p->CID]);
		unset($this->attachments[$p->CID]);
	}
	public function __construct(){
		$this->server = Server::getInstance();
		$this->hub = HubPlugin::get();
		// permissions
		// cmd perms
		$mgs = $this->server->getPluginManager()->getPermission("legionpe.cmd.mg");
		$mg = DP::registerPermission(new Perm("legionpe.cmd.mg.pvp", "Allow using PvP minigame commands"), $mgs);
		DP::registerPermission(new Perm("legionpe.cmd.mg.pvp.pvp", "Allow using command /pvp in KitPvP minigame", Perm::DEFAULT_FALSE), $mg); // DEFAULT_FALSE because minigame-only
		DP::registerPermission(new Perm("legionpe.cmd.mg.pvp.kills", "Allow using command /kills in KitPvP minigame", Perm::DEFAULT_FALSE), $mg);
		// actions perms
		$mgs = $this->server->getPluginManager()->getPermission("legionpe.mg");
		$mg = DP::registerPermission(new Perm("legionpe.mg.pvp", "Allow doing some actions in PvP minigame"), $mgs);
		DP::registerPermission(new Perm("legionpe.mg.pvp.spawnattack", "Allow attacking at spawn platform", Perm::DEFAULT_OP), $mg);
		// event handlers
		$this->server->getPluginManager()->registerEvent("pocketmine\\event\\entity\\EntityDeathEvent", $this, EventPriority::HIGH, new EvtExe(array($this, "onDeath")), $this->hub);
		$this->server->getPluginManager()->registerEvent("pocketmine\\event\\entity\\EntityHurtEvent", $this, EventPriority::HIGH, new EvtExe(array($this, "onHurt")), $this->hub);
		$this->server->getPluginManager()->registerEvent("pocketmine\\event\\player\\PlayerAttackEvent", $this, EventPriority::HIGH, new EvtExe(array($this, "onAttack")), $this->hub);
		$this->server->getPluginManager()->registerEvent("pocketmine\\event\\player\\PlayerRespawnEvent", $this, EventPriority::HIGH, new EvtExe(array($this, "onRespawn")), $this->hub);
		// commands
		$cmd = new Cmd("pvp", $this->hub);
		$cmd->setDescription("Get the PvP kit!");
		$cmd->setUsage("/pvp");
		$cmd->setPermission("legionpe.cmd.mg.pvp.pvp");
		$cmd->setAliases(array("kit"));
		$cmd->register($this->server->getCommandMap());
		$cmd = new Cmd("kills", $this->hub);
		$cmd->setDescription("View your kills or top kills");
		$cmd->setUsage("/kills [top]");
		$cmd->register($this->server->getCommandMap());
	}
	public function onDeath(Event $event){
		$p = $event->getEntity();
		if(!($p instanceof Player) or $p->level->getName() !== "world_pvp") return;
		$cause = $event->getCause();
		if($cause instanceof Player){
			$this->onKill($cause);
			$cause->sendMessage("You killed {$p->getDisplayName()}!");
			$cause->sendMessage("Team points +2!");
			Team::get($this->hub->getDb($cause)->get("team"))["points"] += 2;
			$this->pvpDies[$p->CID] = true;
			$p->sendMessage("You have been killed by {$cause->getDisplayName()}!");
		}
		Team::get($this->hub->getDb($p)->get("team"))["points"]--;
		$config = $this->hub->getDb($p);
		$data = $config->get("kitpvp");
		$data["deaths"]++;
		$config->set("kitpvp", $data);
		$config->save();
		$p->sendMessage("Your number of deaths is now {$data["deaths"]}!");
		$event->setMessage(""); // @shoghicp, you must add this!
	}
	public function onRespawn(Event $event){
		$p = $event->getPlayer();
		if(@$this->pvpDies[$p->CID] !== true)
			return;
		$p->teleport(RawLocs::pvpSpawn());
		$this->equip($p);
		$this->pvpDies[$p->CID] = false;
		unset($this->pvpDies[$p->CID]);
	}
	public function onHurt(Event $event){
		$p = $event->getEntity();
		if(!($p instanceof Player)) return;
		$cause = $event->getCause();
		if(in_array($cause, array("suffocation", "falling")))
			$event->setCancelled(true);
	}
	public function onAttack(Event $event){
		if(RawLocs::safeArea()->isInside($event->getPlayer())){
			$event->setCancelled(true);
			$event->getPlayer()->sendMessage("You may not attack people here!");
		}
		elseif($this->hub->getTeam($event->getPlayer()) === $this->hub->getTeam($event->getVictim())){
			$event->setCancelled(true);
		}
	}
	public function onKill(Player $killer){
		$db = $this->hub->getDb($killer);
		$data = $db->get("kitpvp");
		$data["kills"]++;
		$db->set("kitpvp", $data);
		$db->save();
		$killer->sendMessage("Your number of kills is now {$data["kills"]}!");
		$this->updatePrefix($killer, $data["kills"]);
	}
	protected function updatePrefix(Player $killer, $kills){
		$pfxs = $this->hub->config->get("kitpvp")["prefixes"];
		asort($pfxs, SORT_NUMERIC);
		$pfx = "";
		foreach($pfxs as $prefix=>$min){
			if($kills >= $min)
				$pfx = $prefix;
			else break;
		}
		$data = $this->hub->config->get("kitpvp");
		$tops = $data["top-kills"];
		$tops[$killer->getDisplayName()] = $kills;
		arsort($tops);
		if(count($tops) > 5)
			$tops = array_slice($tops, 0, 5, true);
		$data["top-kills"] = $tops;
		$this->hub->config->set("kitpvp", $data);
		$this->hub->config->save();
		$data = $this->hub->getDb($killer)->get("prefixes");
		$data["kitpvp"] = $pfx;
		$data["kitpvp-kills"] = $kills;
		if(isset($tops[$killer->getDisplayName()]))
			$data["kitpvp-rank"] = "#".(array_search($killer->getDisplayName(), array_keys($tops)) + 1);
		$this->hub->getDb($killer)->set("prefixes", $data);
		$this->hub->getDb($killer)->save();
	}
	public function equip(Player $player){
		$rk = $this->hub->getRank($player);
		$data = $this->hub->config->get("kitpvp")["auto-equip"][$rk];
		foreach($data["inventory"] as $slot=>$item){
			$player->setSlot($slot, Item::get($item[0], $item[1], $item[2]));
		}
		foreach($data["armor"] as $slot=>$armor){
			$player->setArmorSlot(array("h"=>0, "c"=>1, "l"=>2, "b"=>3)[$slot], Item::get($armor));
		}
	}
	public static $inst = false;
	public static function init(){
		self::$inst = new self();
	}
}
