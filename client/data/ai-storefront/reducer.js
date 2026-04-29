import ACTION_TYPES from './action-types';

const defaultState = {
	settings: {},
	// Last-known-saved snapshot of `settings`. Drives the dirty-aware
	// Save button via the `isDirty` selector — empty until the initial
	// SET_SETTINGS load resolves, and resynced after every successful
	// save. Diverges from `settings` whenever the merchant edits a
	// field; converges back when they save (or undo back to the
	// saved values, since the comparison is value-based).
	savedSettings: {},
	isSaving: false,
	savingError: null,
	stats: null,
	statsError: null,
	endpoints: {},
	endpointsError: null,
	// Per-endpoint reachability status. Shape:
	//   { llms_txt: 'checking' | 'reachable' | 'unreachable' | 'disabled',
	//     ucp:      'checking' | 'reachable' | 'unreachable' | 'disabled',
	//     ucp_api:  ...  }
	// Empty object means "not yet probed" — the UI treats that as
	// equivalent to 'checking' for display purposes.
	endpointStatus: {},
	// Recent AI-attributed orders for the Overview tab's AI Orders
	// table. `null` = "not fetched yet" so the component can render
	// a skeleton/empty state rather than flashing an empty table.
	recentOrders: null,
};

const reducer = ( state = defaultState, action ) => {
	switch ( action.type ) {
		case ACTION_TYPES.SET_SETTINGS:
			// SET_SETTINGS is the "draft equals saved" sync point.
			// Fired by (a) the initial REST load on tab mount, and
			// (b) the post-save refetch in `saveSettings()`. Both
			// moments are when the server's view IS the merchant's
			// committed state — write to both copies so the dirty
			// selector reads as clean immediately afterward.
			return {
				...state,
				settings: action.data,
				savedSettings: action.data,
			};

		case ACTION_TYPES.SET_SETTINGS_VALUES:
			return {
				...state,
				savingError: null,
				// Only the draft updates here. `savedSettings` stays
				// pinned to the last-known-saved snapshot, which is
				// what the `isDirty` selector compares against.
				settings: { ...state.settings, ...action.payload },
			};

		case ACTION_TYPES.SET_IS_SAVING:
			return {
				...state,
				isSaving: action.isSaving,
				savingError: action.error || null,
			};

		case ACTION_TYPES.SET_STATS:
			return { ...state, stats: action.data, statsError: null };

		case ACTION_TYPES.SET_STATS_ERROR:
			return { ...state, statsError: action.error };

		case ACTION_TYPES.SET_ENDPOINTS:
			return { ...state, endpoints: action.data, endpointsError: null };

		case ACTION_TYPES.SET_ENDPOINTS_ERROR:
			return { ...state, endpointsError: action.error };

		case ACTION_TYPES.SET_ENDPOINT_STATUS:
			return {
				...state,
				endpointStatus: {
					...state.endpointStatus,
					[ action.key ]: action.status,
				},
			};

		case ACTION_TYPES.RESET_ENDPOINT_STATUS:
			return { ...state, endpointStatus: {} };

		case ACTION_TYPES.SET_RECENT_ORDERS:
			return { ...state, recentOrders: action.data };

		default:
			return state;
	}
};

export default reducer;
