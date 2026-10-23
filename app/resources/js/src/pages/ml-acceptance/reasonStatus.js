export function canAdvance(status) { return status === 'complete' || status === 'not_required'; }
export function isMutationAllowed(wizard, number) { return wizard?.current_step === number; }
export function isServerResumable(wizard, operation) { return wizard?.resumable?.[operation] === true; }
