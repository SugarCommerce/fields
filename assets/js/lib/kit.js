/**
 * The kit's modules, by name: `window.SugarCommerceFieldsModules`.
 *
 * Filled by the entry (field-kit.js) in the order the modules have always
 * been published, and put on the window while the script runs. Code
 * outside the library reads it -- a panel calls `init()` on markup it has
 * just put in the page and `destroy()` before taking it out, and a plugin
 * adds rows through `Repeater.add()`, moves them with `Reorder.move()` and
 * starts its media buttons with `Media.init()`.
 *
 * A module that reaches another one here, rather than importing it, does
 * so on purpose: the two need each other, and asking at the moment it is
 * wanted is what keeps either from needing the other to have loaded first.
 */
export const kit = {};
