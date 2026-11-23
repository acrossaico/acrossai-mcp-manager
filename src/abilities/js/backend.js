/**
 * Admin entry point for the absorbed Abilities screens.
 *
 * Deliberately empty. `abilities/admin/Main.php` reads this entry's generated
 * `backend.asset.php` to resolve its script handle and dependency list, so the
 * entry has to exist — but every Abilities screen renders through its own
 * React app (`abilities.js`, `access-control.js`, `tools.js`, `embeds.js`),
 * and none of them load through here.
 *
 * It arrived from acrossai-abilities-manager carrying five lines of
 * `wp-scripts` scaffold — a `for` loop over `[ 1, 2, 3, 4, 5 ]` that assigned
 * a variable and did nothing with it. That shipped in the bundle; this comment
 * does not.
 */
