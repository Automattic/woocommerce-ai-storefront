import ACTION_TYPES from './action-types';

const defaultState = {
	settings: {},
	isSaving: false,
	savingError: null,
	stats: null,
	endpoints: {},
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

		default:
			return state;
	}
};

export default reducer;
