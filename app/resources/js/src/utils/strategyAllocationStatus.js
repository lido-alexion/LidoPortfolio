export function strategyAllocationStatus(row) {
    const allocatedRaw = row?.strategy_capital_allocation;
    const deployedRaw = row?.strategy_deployed_capital;
    const allocated = Number(allocatedRaw);
    const deployed = Number(deployedRaw);
    if (allocatedRaw == null || allocatedRaw === '' || deployedRaw == null || deployedRaw === ''
        || !Number.isFinite(allocated) || !Number.isFinite(deployed)) {
        return { status: 'unavailable', amount: null };
    }

    const variance = deployed - allocated;
    if (variance > 0.0001) {
        return { status: 'above_allocation', amount: variance };
    }
    if (allocated - deployed > 0.0001) {
        return { status: 'unused_allocation', amount: allocated - deployed };
    }

    return { status: 'within_allocation', amount: 0 };
}
