const EMPTY_OBJ = {};

export const getSettings = ( state ) => state?.settings || EMPTY_OBJ;
export const isSaving = ( state ) => state?.isSaving || false;
export const getSavingError = ( state ) => state?.savingError || null;
export const getStats = ( state ) => state?.stats || null;
export const getEndpoints = ( state ) => state?.endpoints || EMPTY_OBJ;
export const getEndpointStatus = ( state ) =>
	state?.endpointStatus || EMPTY_OBJ;
export const getRecentOrders = ( state ) => state?.recentOrders || null;
