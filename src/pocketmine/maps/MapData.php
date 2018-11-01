<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);


namespace pocketmine\maps;

use pocketmine\item\Map;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\math\Vector2;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\network\mcpe\protocol\ClientboundMapItemDataPacket;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\MapDecoration;
use pocketmine\network\mcpe\protocol\types\MapTrackedObject;
use pocketmine\Player;
use pocketmine\utils\Color;

class MapData{

	/** @var int */
	protected $mapId = 0;
	/** @var int */
	protected $xCenter = 0, $zCenter = 0;
	/** @var int */
	protected $dimension = DimensionIds::OVERWORLD;
	/** @var int */
	protected $scale = 0;
	/** @var Color[][] */
	protected $colors = [];
	/** @var MapDecoration[] */
	protected $decorations = [];
	/** @var MapTrackedObject[] */
	protected $trackedObjects = [];

	/** @var ClientboundMapItemDataPacket|null */
	protected $cachedDataPacket = null;
	/** @var bool */
	protected $fullyExplored = true;

	/** @var MapInfo[] */
	protected $playersMap = [];

	public function __construct(int $mapId){
		$this->mapId = $mapId;
	}

	/**
	 * @return int
	 */
	public function getMapId() : int{
		return $this->mapId;
	}

	/**
	 * @return int
	 */
	public function getDimension() : int{
		return $this->dimension;
	}

	/**
	 * @param int $dimension
	 */
	public function setDimension(int $dimension) : void{
		$this->dimension = $dimension;
		$this->updateMap(ClientboundMapItemDataPacket::BITFLAG_TEXTURE_UPDATE | ClientboundMapItemDataPacket::BITFLAG_DECORATION_UPDATE);
	}

	/**
	 * @return int
	 */
	public function getScale() : int{
		return $this->scale;
	}

	/**
	 * @param int $scale
	 */
	public function setScale(int $scale) : void{
		$this->scale = $scale;
		$this->updateMap(ClientboundMapItemDataPacket::BITFLAG_TEXTURE_UPDATE | ClientboundMapItemDataPacket::BITFLAG_DECORATION_UPDATE);
	}

	/**
	 * @return Color[][]
	 */
	public function getColors() : array{
		return $this->colors;
	}

	/**
	 * @param Color[][] $colors
	 */
	public function setColors(array $colors) : void{
		$this->colors = $colors;
		$this->updateMap(ClientboundMapItemDataPacket::BITFLAG_TEXTURE_UPDATE);
	}

	/**
	 * @param int   $x
	 * @param int   $y
	 * @param Color $color
	 */
	public function setColorAt(int $x, int $y, Color $color) : void{
		$this->colors[$y][$x] = $color;
		$this->updateMap(ClientboundMapItemDataPacket::BITFLAG_TEXTURE_UPDATE);
	}

	/**
	 * @param int $x
	 * @param int $y
	 *
	 * @return Color
	 */
	public function getColorAt(int $x, int $y) : Color{
		return $this->colors[$y][$x] ?? new Color(0, 0, 0);
	}

	/**
	 * @param int $x
	 * @param int $z
	 */
	public function setCenter(int $x, int $z) : void{
		$this->xCenter = $x;
		$this->zCenter = $z;
	}

	/**
	 * @return Vector2
	 */
	public function getCenter() : Vector2{
		return new Vector2($this->xCenter, $this->zCenter);
	}

	public function calculateMapCenter(int $x, int $z, int $scale) : void{
		$i = 128 * (1 << $scale);
		$j = (int) floor(($x + 64.0) / $i);
		$k = (int) floor(($z + 64.0) / $i);

		$this->setCenter($j * $i + $i / 2 - 64, $k * $i + $i / 2 - 64);
	}

	public function updateMap(int $flags) : void{
		foreach($this->playersMap as $info){
			$player = $info->player;
			if($player->isOnline() and $player->isAlive() and $player->level->getDimension() === $this->dimension){
				$player->sendDataPacket($this->createDataPacket($flags));
			}
		}
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->dimension = $nbt->getByte("dimension", 0);
		$this->xCenter = $nbt->getInt("xCenter", 0);
		$this->zCenter = $nbt->getInt("zCenter", 0);
		$this->scale = $nbt->getByte("scale", 0);
		$this->fullyExplored = boolval($nbt->getByte("fullyExplored", 1));
		if($this->scale > 4) $this->scale = 0;

		if($nbt->hasTag("colors", IntArrayTag::class)){
			$colors = $nbt->getIntArray("colors");

			for($y = 0; $y < 128; $y++){
				for($x = 0; $x < 128; $x++){
					$this->colors[$y][$x] = Color::fromABGR($colors[$x + $y * 128] ?? 0);
				}
			}
		}
	}

	public function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setByte("dimension", $this->dimension);
		$nbt->setInt("xCenter", $this->xCenter);
		$nbt->setInt("zCenter", $this->zCenter);
		$nbt->setByte("scale", $this->scale);
		$nbt->setByte("fullyExplored", intval($this->fullyExplored));

		if(count($this->colors) > 0){
			$colors = [];
			for($y = 0; $y < 128; $y++){
				for($x = 0; $x < 128; $x++){
					$color = $this->colors[$y][$x] ?? new Color(0, 0, 0);
					$colors[$x + $y * 128] = $color->toABGR();
				}
			}

			$nbt->setIntArray("colors", $colors, true);
		}
	}

	/**
	 * @return MapDecoration[]
	 */
	public function getDecorations() : array{
		return $this->decorations;
	}

	/**
	 * @param MapDecoration[] $decorations
	 */
	public function setDecorations(array $decorations) : void{
		$this->decorations = array_keys($decorations);
		$this->updateMap(ClientboundMapItemDataPacket::BITFLAG_DECORATION_UPDATE);
	}

	/**
	 * @return MapTrackedObject[]
	 */
	public function getTrackedObjects() : array{
		return $this->trackedObjects;
	}

	/**
	 * @param MapTrackedObject[] $trackedObjects
	 */
	public function setTrackedObjects(array $trackedObjects) : void{
		$this->trackedObjects = $trackedObjects;
		$this->updateMap(ClientboundMapItemDataPacket::BITFLAG_DECORATION_UPDATE);
	}

	public function createDataPacket(int $flags) : ClientboundMapItemDataPacket{
		$pk = new ClientboundMapItemDataPacket();
		$pk->mapId = $this->mapId;
		$pk->dimensionId = $this->dimension;
		$pk->scale = $this->scale;
		$pk->width = 128;
		$pk->height = 128;

		if(($flags & ClientboundMapItemDataPacket::BITFLAG_DECORATION_UPDATE) !== 0){
			$pk->decorations = $this->decorations;
			$pk->trackedEntities = $this->trackedObjects;
		}

		if(($flags & ClientboundMapItemDataPacket::BITFLAG_TEXTURE_UPDATE) !== 0){
			$pk->colors = $this->colors;
		}

		return $pk;
	}

	/**
	 * @return bool
	 */
	public function isFullyExplored() : bool{
		return $this->fullyExplored;
	}

	/**
	 * @param bool $fullyExplored
	 */
	public function setFullyExplored(bool $fullyExplored) : void{
		$this->fullyExplored = $fullyExplored;
	}

	public function getMapInfo(Player $player) : MapInfo{
		if(!isset($this->playersMap[spl_object_hash($player)])){
			$this->playersMap[spl_object_hash($player)] = new MapInfo($player);
			$mo = new MapTrackedObject();
			$mo->entityUniqueId = $player->getId();
			$mo->type = MapTrackedObject::TYPE_PLAYER;
			$this->trackedObjects[$player->getName()] = $mo;
		}
		return $this->playersMap[spl_object_hash($player)];
	}

	/**
	 * @param int $x
	 * @param int $y
	 */
	public function updateTextureAt(int $x, int $y) : void{
		$this->updateMap(ClientboundMapItemDataPacket::BITFLAG_TEXTURE_UPDATE);

		foreach($this->playersMap as $info){
			$info->updateTextureAt($x, $y);
		}
	}

	/**
	 * Adds the player passed to the list of visible players and checks to see which players are visible
	 *
	 * @param Player $player
	 * @param Map   $mapStack
	 */
	public function updateVisiblePlayers(Player $player, Map $mapStack){
		if(!isset($this->playersMap[$hash = spl_object_hash($player)])){
			$this->playersMap[$hash] = new MapInfo($player);

			$mo = new MapTrackedObject();
			$mo->entityUniqueId = $player->getId();
			$mo->type = MapTrackedObject::TYPE_PLAYER;
			$this->trackedObjects[$player->getName()] = $mo;
		}

		if(!$player->getInventory()->contains($mapStack)){
			unset($this->decorations[$player->getName()]);
		}

		if($mapStack->isMapDisplayPlayers()){
			foreach($this->playersMap as $info){
				$pi = $info->player;
				if($pi->isOnline() and $pi->isAlive() and $pi->level->getDimension() === $this->dimension and $info->packetSendTimer++ % 5 === 0){
					if(!$mapStack->isOnItemFrame() and $pi->getInventory()->contains($mapStack)){
						$this->updateDecorations(MapDecoration::TYPE_PLAYER, $pi->level, $pi->getName(), $pi->getFloorX(), $pi->getFloorZ(), $pi->getYaw());
					}
				}else{
					unset($this->playersMap[spl_object_hash($pi)]);
				}
			}
		}

		if($mapStack->isOnItemFrame()){
			$frame = $mapStack->getItemFrame();
			$this->updateDecorations(MapDecoration::TYPE_FRAME, $player->level, "frame-" . spl_object_hash($frame), $frame->getFloorX(), $frame->getFloorZ(), $frame->getBlock()->getDamage() * 90);
		}

		$mapNbt = $mapStack->getNamedTag();
		if($mapNbt->hasTag("Decorations", ListTag::class)){
			$decos = $mapNbt->getListTag("Decorations");
			foreach($decos->getValue() as $v){
				if($v instanceof CompoundTag){
					if(!isset($this->decorations[$v->getString("id")])){
						$this->updateDecorations($v->getByte("type"), $player->level, $v->getString("id"), (int) $v->getDouble("x"), (int) $v->getDouble("z"), $v->getDouble("rot"));
					}
				}
			}
		}
	}

	/**
	 * @param int    $type
	 * @param Level  $worldIn
	 * @param String $entityIdentifier
	 * @param int    $worldX
	 * @param int    $worldZ
	 * @param float  $rotation
	 */
	public function updateDecorations(int $type, Level $worldIn, String $entityIdentifier, int $worldX, int $worldZ, float $rotation){
		$i = 1 << $this->scale;
		$f = ($worldX - $this->xCenter) / $i;
		$f1 = ($worldZ - $this->zCenter) / $i;
		$b0 = (int) (($f * 2.0) + 0.5);
		$b1 = (int) (($f1 * 2.0) + 0.5);
		$j = 63;

		if($f >= (-$j) and $f1 >= (-$j) and $f <= $j and $f1 <= $j){
			$rotation = $rotation + ($rotation < 0.0 ? -8.0 : 8.0);
			$b2 = ((int) ($rotation * 16.0 / 360.0));

			if($this->dimension > DimensionIds::OVERWORLD){
				$k = (int) ($worldIn->getTime() / 10);
				$b2 = (int) ($k * $k * 34187121 + $k * 121 >> 15 & 15);
			}
		}else{
			if(abs($f) >= 320.0 or abs($f1) >= 320.0){
				unset($this->decorations[$entityIdentifier]);
				return;
			}
			if($type === MapDecoration::TYPE_PLAYER){
				$type = MapDecoration::TYPE_PLAYER_OFF_MAP;
			}
			$b2 = 0;

			if($f <= -$j){
				$b0 = (int) (($j * 2) + 2.5);
			}

			if($f1 <= -$j){
				$b1 = (int) (($j * 2) + 2.5);
			}

			if($f >= $j){
				$b0 = (int) ($j * 2 + 1);
			}

			if($f1 >= $j){
				$b1 = (int) ($j * 2 + 1);
			}
		}

		$deco = new MapDecoration();
		$deco->icon = $type;
		$deco->rot = $b2;
		$deco->xOffset = $b0;
		$deco->yOffset = $b1;
		$deco->color = new Color(255, 255, 255);
		$deco->label = $entityIdentifier;

		$this->decorations[$entityIdentifier] = $deco;

		$this->updateMap(ClientboundMapItemDataPacket::BITFLAG_DECORATION_UPDATE);
	}
}
