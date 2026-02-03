# SimplePlayerData

A lightweight PocketMine‑MP plugin that stores basic player information in a single SQLite 3 database:

- **UUID** (index)
- Last known username
- First‑seen timestamp
- Last‑seen timestamp  

The plugin is intended for servers that have Xbox Live authentication turned on
and the built‑in player‑data saving setting (`player.save-player-data`)
disabled, providing a compact alternative to the default per‑player file
storage.

---

## Features
| Feature | Description |
|---------|-------------|
| **Single DB** | All records are kept in one `players.db` SQLite 3 file. |
| **Automatic updates** | On player join the plugin records first‑seen (if new) and updates last‑seen and username. |
| **Zero configuration** | Works out‑of‑the‑box; only ensure the PocketMine‑MP player‑data saving option is turned off. |

---

## Limitations

SimplePlayerData does not save all the information that is stored in PocketMine-created `.dat` files, like inventory, position, etc.
This is intended and will not be changed.

---

## License

This project is licensed under the **GNU Affero General Public License, version 3**. See `COPYING` for details.
