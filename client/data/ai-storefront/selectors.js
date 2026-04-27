const EMPTY_OBJ = {};

export const getSettings = ( state ) => state?.settings || EMPTY_OBJ;
export const getSavedSettings = ( state ) => state?.savedSettings || EMPTY_OBJ;
export const isSaving = ( state ) => state?.isSaving || false;
export const getSavingError = ( state ) => state?.savingError || null;
export const getStats = ( state ) => state?.stats || null;
export const getEndpoints = ( state ) => state?.endpoints || EMPTY_OBJ;
export const getEndpointStatus = ( state ) =>
	state?.endpointStatus || EMPTY_OBJ;
export const getRecentOrders = ( state ) => state?.recentOrders || null;

/**
 * Whether the merchant has unsaved changes — drives the Save button's
 * enabled state across tabs. Compares the working `settings` draft to
 * the last-known-saved snapshot (`savedSettings`).
 *
 * Mirrors WooCommerce Settings + Block Editor's `isEditedPostDirty`:
 * "did the user actually change something away from the saved state?"
 * Type-then-undo correctly returns to clean (because the resulting
 * draft equals the saved snapshot once again).
 *
 * Comparison strategy: `JSON.stringify` round-trip on both sides.
 *
 *   - Cheap (no extra dependency, no recursive helper).
 *   - Order-stable for plain objects in modern JS engines (V8 / SpiderMonkey
 *     preserve insertion order, and our settings shape is built by the
 *     same reducer in both write paths, so key order matches).
 *   - Order-SENSITIVE for arrays (`['a','b']` ≠ `['b','a']`). That's
 *     the right behavior here: every array in our settings has a
 *     meaningful order — `selected_categories` is a list the merchant
 *     curated, `allowed_crawlers` is shown in display order, etc.
 *     A list reordered by a future "drag to reorder" UI SHOULD register
 *     as dirty.
 *
 * Empty-saved handling: until the initial fetch resolves,
 * `savedSettings` is `{}`. Any merchant edit during that window will
 * register as dirty against the empty snapshot — which is correct: the
 * merchant did type something, the Save button should reflect that.
 *
 * Server-derived field exclusion: none today. `state.settings` only
 * contains merchant-editable fields. If a future PR adds a
 * server-mirror field, exclude it here rather than letting it
 * pollute the comparison.
 *
 * @param {Object} state Redux store state.
 * @return {boolean}     True when draft differs from saved.
 */
export const isDirty = ( state ) => {
	const draft = getSettings( state );
	const saved = getSavedSettings( state );
	return JSON.stringify( draft ) !== JSON.stringify( saved );
};
