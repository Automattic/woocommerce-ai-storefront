const EMPTY_OBJ = {};
const EMPTY_ARR = [];

export const getSettings = ( state ) => state?.settings || EMPTY_OBJ;
export const isSaving = ( state ) => state?.isSaving || false;
export const getSavingError = ( state ) => state?.savingError || null;
export const getBots = ( state ) => state?.bots || EMPTY_ARR;
export const isLoadingBots = ( state ) => state?.isLoadingBots || false;
export const getStats = ( state ) => state?.stats || null;
export const getEndpoints = ( state ) => state?.endpoints || EMPTY_OBJ;
export const getNewBotKey = ( state ) => state?.newBotKey || null;
