/**
 * Feature 099 — the wizard's instructional recordings.
 *
 * Gathered here rather than exported from whichever step happened to need one
 * first. Step 3 used to import its video from Step 2, which meant retargeting
 * Step 2's recording silently retargeted Step 3's as well — the screens are
 * independent, so their content should be too.
 *
 * Each screen names its own constant. Two screens sharing a value is a fact
 * about today's recordings, not a coupling between the screens.
 *
 * @package
 */

/**
 * Editing a single ability — screen 2.
 *
 * @type {string}
 */
export const EDIT_ABILITY_VIDEO_ID = 'b4IEgJ0-1H8';

/**
 * Acting on many abilities at once — screen 3.
 *
 * @type {string}
 */
export const BULK_ACTIONS_VIDEO_ID = 'bTCvGbMe30o';

/**
 * Placeholder walkthrough still standing in for the screens without a
 * purpose-made recording (screen 7).
 *
 * @type {string}
 */
export const WALKTHROUGH_VIDEO_ID = '6nDuURDNmLc';

/**
 * Playlist the placeholder walkthrough belongs to.
 *
 * @type {string}
 */
export const WALKTHROUGH_PLAYLIST = 'PLL-i34ne1J0c';
