import ACTION_TYPES from './action-types';

const defaultState = {
	settings: {},
	isSaving: false,
	savingError: null,
	stats: null,
	endpoints: {},
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
			return { ...state, settings: action.data };

		case ACTION_TYPES.SET_SETTINGS_VALUES:
			return {
				...state,
				savingError: null,
				settings: { ...state.settings, ...action.payload },
			};

		case ACTION_TYPES.SET_IS_SAVING:
			return {
				...state,
				isSaving: action.isSaving,
				savingError: action.error || null,
			};

		case ACTION_TYPES.SET_STATS:
			return { ...state, stats: action.data };

		case ACTION_TYPES.SET_ENDPOINTS:
			return { ...state, endpoints: action.data };

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
