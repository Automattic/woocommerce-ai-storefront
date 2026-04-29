const EMPTY_OBJ = {};

export const getSettings = ( state ) => state?.settings || EMPTY_OBJ;
export const getSavedSettings = ( state ) => state?.savedSettings || EMPTY_OBJ;
export const isSaving = ( state ) => state?.isSaving || false;
export const getSavingError = ( state ) => state?.savingError || null;
export const getStats = ( state ) => state?.stats || null;
export const getStatsError = ( state ) => state?.statsError || null;
export const getEndpoints = ( state ) => state?.endpoints || EMPTY_OBJ;
export const getEndpointsError = ( state ) => state?.endpointsError || null;
export const getEndpointStatus = ( state ) =>
	state?.endpointStatus || EMPTY_OBJ;
export const getRecentOrders = ( state ) => state?.recentOrders || null;

/**
 * Whether the merchant has unsaved changes — drives the Save button's
 * enabled state across tabs. Compares the working `settings` draft to
 * the last-known-saved snapshot (`savedSettings`).
 *
 * Conceptually mirrors WooCommerce Settings + the Block Editor's
 * `isEditedPostDirty`: "did the user actually change something away
 * from the saved state?" Type-then-undo correctly returns to clean.
 * (Implementation differs — Block Editor tracks edits in a separate
 * slice and checks `Object.keys(edits).length`; we round-trip both
 * snapshots through JSON.stringify. The user-facing behavior is the
 * same; the mechanism is not.)
 *
 * Comparison strategy: `JSON.stringify` round-trip on both sides.
 *
 *   - Cheap (no extra dependency, no recursive helper).
 *   - Order-stable for plain objects in modern JS engines (V8 /
 *     SpiderMonkey preserve insertion order per ES2015 for non-integer
 *     string keys). Both snapshots originate from the same source: the
 *     server's REST response (initial resolver + post-save refetch
 *     both write `action.data` straight into the reducer). As long as
 *     the server returns a stable key order across those two endpoints
 *     — which the WC REST controllers do today by serializing from a
 *     fixed PHP array — comparison is stable. If a future endpoint
 *     ever returns the same fields in a different order, this selector
 *     would false-positive dirty on a clean form. Watch for that when
 *     adding new server endpoints to the load path.
 *   - Order-SENSITIVE for arrays (`['a','b']` ≠ `['b','a']`). That's
 *     the right behavior here: every array in our settings has a
 *     meaningful order — `selected_categories` is a list the merchant
 *     curated, `allowed_crawlers` is shown in display order, etc.
 *     A list reordered by a future "drag to reorder" UI SHOULD register
 *     as dirty.
 *
 * Empty-saved handling: until the initial fetch resolves,
 * `savedSettings` is `{}`. The form is gated by `isLoading` in
 * `settings-page.js` so the merchant can't edit during that window in
 * practice — but if they ever could, a draft against an empty saved
 * snapshot reads as dirty (correct: they did type something).
 *
 * Server-derived field exclusion: none today. `state.settings` only
 * contains merchant-editable fields. If a future PR adds a
 * server-mirror field, exclude it here rather than letting it
 * pollute the comparison.
 *
 * Defensive note: `JSON.stringify` throws on circular references and
 * `BigInt` values. Neither shape exists in `settings` today (the
 * source of truth is a flat WP option blob with primitive values and
 * shallow object/array fields), so we don't pay the cost of a
 * try/catch on every render. If a future field could carry either
 * shape, wrap the comparison and default to `true` on throw (safer to
 * show enabled Save than crash the React tree).
 *
 * Performance: full-object stringify on every store update is
 * acceptable for the current settings shape — the blob is bounded
 * (~10–20 top-level keys, mostly primitives) and the largest array
 * (`selected_products`) is rarely edited interactively (the UI is
 * a multi-select with explicit add/remove, not per-keystroke
 * mutation). `useSelect` memoizes the result by reference equality
 * on the returned value, so React only re-renders subscribed
 * components when the boolean actually flips. If a future surface
 * adds large arrays edited per-keystroke (e.g. a search-as-you-type
 * picker that mutates `selected_products` on every input event),
 * switch to a per-key dirty bitmap maintained in the reducer
 * (set on `SET_SETTINGS_VALUES`, cleared on `SET_SETTINGS`) — that
 * sidesteps the serialize cost without changing the merchant-facing
 * contract.
 *
 * @param {Object} state Redux store state.
 * @return {boolean}     True when draft differs from saved.
 */
export const isDirty = ( state ) => {
	const draft = getSettings( state );
	const saved = getSavedSettings( state );
	return JSON.stringify( draft ) !== JSON.stringify( saved );
};
