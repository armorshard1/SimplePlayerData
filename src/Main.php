<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use pocketmine\plugin\PluginBase;

final class Main extends PluginBase {
    private PlayerDataApi $api;

    public function getApi(): PlayerDataApi {
        return $this->api;
    }

    protected function onEnable(): void {
        $this->api = new PlayerDataApi($this);
    }

    protected function onDisable(): void {
        if (isset($this->api)) {
            $this->api->close();
        }
    }
}
