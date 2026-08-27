<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use pocketmine\plugin\PluginBase;
use pocketmine\YmlServerProperties;
use Symfony\Component\Filesystem\Path;

use function sprintf;

final class Main extends PluginBase {
    private PlayerDataApi $api;

    public function getApi(): PlayerDataApi {
        return $this->api;
    }

    protected function onLoad(): void {
        if ($this->getServer()->shouldSavePlayerData()) {
            $this->getLogger()->warning(sprintf(
                'Server property "%s" is enabled',
                YmlServerProperties::PLAYER_SAVE_PLAYER_DATA,
            ));
            $this->getLogger()->warning('Disable it from the pocketmine.yml file');
        }
        $this->api = new PlayerDataApi(Path::join($this->getDataFolder(), 'players.db'), $this->getLogger());
    }

    protected function onEnable(): void {
        $this->api->registerEvents($this);
    }

    protected function onDisable(): void {
        if (isset($this->api)) {
            $this->api->close();
        }
    }
}
