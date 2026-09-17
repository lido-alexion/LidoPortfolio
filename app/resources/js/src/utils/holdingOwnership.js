export function holdingOwnershipLabel(holding) {
    if (holding?.is_unmanaged || holding?.strategy_id == null) {
        return 'Unmanaged';
    }

    const name = String(holding.strategy_name || '').trim();
    return name || 'Strategy-managed';
}

export function holdingOwnershipTypeLabel(holding) {
    if (holding?.is_unmanaged || holding?.strategy_id == null) {
        return 'Unmanaged';
    }

    return 'Strategy-managed';
}

export function ownershipEpisodeCount(holding, holdings) {
    const stockId = holding?.stock_id == null ? null : Number(holding.stock_id);
    if (stockId == null || !Array.isArray(holdings)) {
        return 1;
    }

    return new Set(
        holdings
            .filter((row) => row?.stock_id != null && Number(row.stock_id) === stockId)
            .map((row) => row?.is_unmanaged || row?.strategy_id == null
                ? 'unmanaged'
                : `strategy:${row.strategy_id}`),
    ).size;
}

export function siblingOwnershipNames(holding, holdings) {
    const stockId = holding?.stock_id == null ? null : Number(holding.stock_id);
    if (stockId == null || !Array.isArray(holdings)) {
        return [];
    }

    return holdings
        .filter((row) => row?.stock_id != null && Number(row.stock_id) === stockId)
        .filter((row) => Number(row?.id) !== Number(holding?.id))
        .filter((row) => !(row?.is_unmanaged || row?.strategy_id == null))
        .map(holdingOwnershipLabel)
        .filter((label) => label !== 'Strategy-managed')
        .filter((label, index, labels) => labels.indexOf(label) === index);
}
