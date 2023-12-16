# CAseco
CAseco (**C**oxcopi's **Aseco**) is a fork of the widely-used Trackmania Server Control Software [XAseco](https://www.xaseco.org/) rewritten in modern PHP.
Countless issues and security concerns aswell as compatability problems with PHP versions newer than 5.6 have led to the decission of starting this project.
CAseco maintains the same set of plugins as the original.

#### PHP Version Compatability
|        | v5.6* | v7.x | v8.x | 
|:------:|:-----:|:----:|:----:|
| CAseco | ❌   | ➖  | ✔️   |
| XAseco | ✔️   | ❌  | ❌   |

✔️ Compatible, ➖ Not tested, ❌ Incompatible

#### TODO
- [X] Update constructor syntax
- [X] Fix array string index access changes
- [X] Replace mysql_*() with PDOs
- [ ] update uft8 encoding/decoding
- [ ] (update MySQL defaults to comply with strict mode) (?)
