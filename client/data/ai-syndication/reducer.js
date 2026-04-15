import ACTION_TYPES from './action-types';

const defaultState = {
	settings: {},
	isSaving: false,
	savingError: null,
	bots: [],
	isLoadingBots: false,
	stats: null,
	endpoints: {},
	newBotKey: null,
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

		case ACTION_TYPES.SET_BOTS:
			return {
				...state,
				bots: Array.isArray( action.data )
					? action.data
					: Object.values( action.data || {} ),
			};

		case ACTION_TYPES.SET_IS_LOADING_BOTS:
			return { ...state, isLoadingBots: action.isLoading };

		case ACTION_TYPES.SET_STATS:
			return { ...state, stats: action.data };

		case ACTION_TYPES.SET_ENDPOINTS:
			return { ...state, endpoints: action.data };

		case ACTION_TYPES.SET_NEW_BOT_KEY:
			return { ...state, newBotKey: action.data };

		default:
			return state;
	}
};

export default reducer;
