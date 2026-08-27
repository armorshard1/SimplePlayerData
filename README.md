# SimplePlayerData

A lightweight PocketMine‑MP plugin that stores basic player information in a
single SQLite3 database:

- **UUID** (index)
- Last known username
- First‑seen timestamp
- Last‑seen timestamp  

The plugin is intended for servers that have Xbox Live authentication enabled
and the built‑in player‑data saving setting (`player.save-player-data`)
disabled, providing a compact alternative to the default per‑player file
storage.

## API

SimplePlayerData provides a simple API in the [PlayerDataApi
class](https://github.com/armorshard1/SimplePlayerData/blob/master/src/PlayerDataApi.php).
It has two main methods:
- `getUuid(string $username): ?UuidInterface` returns the UUID of the player
  with the given username, or `null` if that username was never seen on the
  server.
- `getPlayerData(UuidInterface|string $uuidOrUsername): ?PlayerData` returns
  saved player data (or `null` if not found).

Both methods throw a `PlayerDataApiException` on IO errors.

The API object can be obtained like this:
```php
$plugin = $this->getServer()->getPluginManager()->getPlugin('SimplePlayerData');
if ($plugin instanceof \armorshard\simpleplayerdata\Main) {
    //get the PlayerDataApi object
    $playerDataApi = $plugin->getApi();
    //do whatever you want with it
    //...
}
```

## Limitations

SimplePlayerData does not save all the information that is stored in
PocketMine-created `.dat` files, like inventory, position, etc. This is
intended and will not be changed.

## License

This project is licensed under the **GNU Affero General Public License, version
3**. See `COPYING` for details.
