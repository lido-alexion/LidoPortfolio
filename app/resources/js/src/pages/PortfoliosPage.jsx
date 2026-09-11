import React, { useCallback, useEffect, useState } from 'react';
import api from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import { showToast } from '../toast';
import { validatePortfolioName } from '../utils/portfolioName';
import { notifyPortfolioDeleted } from '../utils/portfolioEvents';
import PortfolioReplayPanel from '../components/portfolio/PortfolioReplayPanel';

function validationMessage(error) {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) {
            return first;
        }
    }
    return error?.response?.data?.message || 'Something went wrong. Please try again.';
}

export default function PortfoliosPage() {
    const {
        portfolios,
        activePortfolio,
        refreshPortfolios,
        setActivePortfolio,
        bootstrap,
    } = usePortfolio();
    const [newName, setNewName] = useState('');
    const [newNameTouched, setNewNameTouched] = useState(false);
    const [newType, setNewType] = useState('live');
    const [startingCash, setStartingCash] = useState('100000');
    const [priceMethod, setPriceMethod] = useState('next_open');
    const [creating, setCreating] = useState(false);
    const [renameId, setRenameId] = useState(null);
    const [renameName, setRenameName] = useState('');
    const [renameTouched, setRenameTouched] = useState(false);
    const [savingRename, setSavingRename] = useState(false);
    const [defaultingId, setDefaultingId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const [simulationBusyId, setSimulationBusyId] = useState(null);
    const [cloneId, setCloneId] = useState(null);
    const [cloneName, setCloneName] = useState('');
    const [cloneCopyHoldings, setCloneCopyHoldings] = useState(true);
    const [cloneCash, setCloneCash] = useState('100000');
    const [clonePriceMethod, setClonePriceMethod] = useState('next_open');
    const [cloning, setCloning] = useState(false);

    const newNameError = newNameTouched ? validatePortfolioName(newName) : null;
    const renameNameError = renameTouched ? validatePortfolioName(renameName) : null;

    const load = useCallback(async () => {
        try {
            await refreshPortfolios();
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        }
    }, [refreshPortfolios]);

    useEffect(() => {
        load();
    }, [load]);

    const createPortfolio = async (e) => {
        e.preventDefault();
        setNewNameTouched(true);
        const name = newName.trim();
        const error = validatePortfolioName(name);
        if (error) {
            return;
        }
        setCreating(true);
        try {
            await api.post('/portfolios', {
                name,
                portfolio_type: newType,
                ...(newType === 'paper' ? {
                    starting_cash: Number(startingCash),
                    simulation_price_method: priceMethod,
                } : {}),
            });
            setNewName('');
            setNewNameTouched(false);
            await bootstrap();
            showToast('Portfolio created');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setCreating(false);
        }
    };

    const changeSimulationState = async (portfolio) => {
        setSimulationBusyId(portfolio.id);
        try {
            const action = portfolio.simulation_state === 'paused' ? 'resume' : 'pause';
            await api.post(`/portfolios/${portfolio.id}/simulation/${action}`);
            await bootstrap();
            showToast(`Paper simulation ${action === 'pause' ? 'paused' : 'resumed'}`);
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setSimulationBusyId(null);
        }
    };

    const startRename = (portfolio) => {
        setRenameId(portfolio.id);
        setRenameName(portfolio.name);
        setRenameTouched(false);
    };

    const cancelRename = () => {
        setRenameId(null);
        setRenameName('');
        setRenameTouched(false);
    };

    const saveRename = async (portfolioId) => {
        setRenameTouched(true);
        const name = renameName.trim();
        const error = validatePortfolioName(name);
        if (error) {
            return;
        }
        setSavingRename(true);
        try {
            await api.put(`/portfolios/${portfolioId}`, { name });
            cancelRename();
            await bootstrap();
            showToast('Portfolio renamed');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setSavingRename(false);
        }
    };

    const setDefault = async (portfolioId) => {
        setDefaultingId(portfolioId);
        try {
            await api.post(`/portfolios/${portfolioId}/set-default`);
            await bootstrap();
            showToast('Default portfolio updated');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setDefaultingId(null);
        }
    };

    const deletePortfolio = async (portfolio) => {
        const confirmed = window.confirm(
            `Delete portfolio "${portfolio.name}"?\n\n`
            + 'This permanently removes all its transactions, holdings, alerts, snapshots, and settings. '
            + 'This cannot be undone.',
        );
        if (!confirmed) {
            return;
        }
        setDeletingId(portfolio.id);
        try {
            await api.delete(`/portfolios/${portfolio.id}`);
            notifyPortfolioDeleted(portfolio.id);
            await bootstrap();
            showToast('Portfolio deleted');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setDeletingId(null);
        }
    };

    const startClone = (portfolio) => {
        setCloneId(portfolio.id);
        setCloneName(`${portfolio.name} Paper`);
        setCloneCopyHoldings(true);
        setCloneCash('100000');
        setClonePriceMethod('next_open');
    };

    const cloneAsPaper = async (portfolio) => {
        const name = cloneName.trim();
        const error = validatePortfolioName(name);
        if (error || Number(cloneCash) <= 0) {
            showToast(error || 'Enter simulated cash greater than zero.', 'danger');
            return;
        }
        setCloning(true);
        try {
            await api.post(`/portfolios/${portfolio.id}/clone-as-paper`, {
                name,
                copy_holdings: cloneCopyHoldings,
                starting_cash: Number(cloneCash),
                simulation_price_method: clonePriceMethod,
            });
            setCloneId(null);
            await bootstrap();
            showToast('Paper portfolio cloned');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setCloning(false);
        }
    };

    return (
        <div className="container py-4">
            <div className="mb-4">
                <h2 className="h4 mb-1">Portfolios</h2>
                <p className="text-muted small mb-0">
                    Each portfolio has its own transactions, holdings, alerts, and Telegram settings.
                    The active portfolio is remembered per browser tab.
                </p>
            </div>

            <div className="card mb-4">
                <div className="card-body">
                    <h3 className="h6 mb-3">Create portfolio</h3>
                    <form className="row g-2 align-items-end" onSubmit={createPortfolio}>
                        <div className="col-sm-4">
                            <label htmlFor="new-portfolio-name" className="form-label small mb-1">Name</label>
                            <input
                                id="new-portfolio-name"
                                type="text"
                                className={`form-control form-control-sm${newNameError ? ' is-invalid' : ''}`}
                                value={newName}
                                onChange={(e) => setNewName(e.target.value)}
                                onBlur={() => setNewNameTouched(true)}
                                maxLength={120}
                                placeholder="e.g. Retirement"
                            />
                            {newNameError && (
                                <div className="invalid-feedback d-block">{newNameError}</div>
                            )}
                        </div>
                        <div className="col-sm-2"><label htmlFor="new-portfolio-type" className="form-label small mb-1">Type</label><select id="new-portfolio-type" className="form-select form-select-sm" value={newType} onChange={(event) => setNewType(event.target.value)}><option value="live">Live</option><option value="paper">Paper</option></select></div>
                        {newType === 'paper' ? <><div className="col-sm-2"><label htmlFor="paper-starting-cash" className="form-label small mb-1">Simulated cash</label><input id="paper-starting-cash" type="number" min="0.01" step="0.01" className="form-control form-control-sm" value={startingCash} onChange={(event) => setStartingCash(event.target.value)} /></div><div className="col-sm-2"><label htmlFor="paper-price-method" className="form-label small mb-1">Fill price</label><select id="paper-price-method" className="form-select form-select-sm" value={priceMethod} onChange={(event) => setPriceMethod(event.target.value)}><option value="next_open">Next open</option><option value="next_close">Next close</option><option value="ohlc_average">OHLC average</option><option value="high_low_midpoint">High/low midpoint</option></select></div></> : null}
                        <div className="col-sm-2">
                            <button
                                type="submit"
                                className="btn btn-primary btn-sm w-100"
                                disabled={creating || Boolean(validatePortfolioName(newName)) || (newType === 'paper' && Number(startingCash) <= 0)}
                            >
                                {creating ? 'Creating…' : 'Create'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {activePortfolio && <PortfolioReplayPanel portfolio={activePortfolio} />}

            <div className="card">
                <div className="card-body p-0">
                    <ul className="list-group list-group-flush">
                        {portfolios.map((portfolio) => {
                            const isActive = String(portfolio.id) === String(activePortfolio?.id);
                            const isRenaming = renameId === portfolio.id;
                            const isCloning = cloneId === portfolio.id;
                            return (
                                <li
                                    key={portfolio.id}
                                    className="list-group-item"
                                >
                                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <div className="flex-grow-1">
                                            {isRenaming ? (
                                                <div className="d-flex flex-wrap gap-2 align-items-start">
                                                    <div style={{ maxWidth: 240 }}>
                                                        <input
                                                            type="text"
                                                            className={`form-control form-control-sm${renameNameError ? ' is-invalid' : ''}`}
                                                            value={renameName}
                                                            onChange={(e) => setRenameName(e.target.value)}
                                                            onBlur={() => setRenameTouched(true)}
                                                            maxLength={120}
                                                        />
                                                        {renameNameError && (
                                                            <div className="invalid-feedback d-block">
                                                                {renameNameError}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <button
                                                        type="button"
                                                        className="btn btn-primary btn-sm"
                                                        disabled={savingRename || Boolean(validatePortfolioName(renameName))}
                                                        onClick={() => saveRename(portfolio.id)}
                                                    >
                                                        Save
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-outline-secondary btn-sm"
                                                        onClick={cancelRename}
                                                    >
                                                        Cancel
                                                    </button>
                                                </div>
                                            ) : (
                                                <>
                                                    <span className="fw-semibold">{portfolio.name}</span>
                                                    <span className={`badge ms-2 ${portfolio.portfolio_type === 'paper' ? 'text-bg-warning' : 'text-bg-success'}`}>{portfolio.portfolio_type === 'paper' ? 'PAPER' : 'LIVE'}</span>
                                                    {portfolio.is_default && (
                                                        <span className="badge text-bg-secondary ms-2">Default</span>
                                                    )}
                                                    {isActive && (
                                                        <span className="badge text-bg-info ms-2">Active in this tab</span>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                        {!isRenaming && (
                                            <div className="d-flex flex-wrap gap-2">
                                                {portfolio.portfolio_type === 'paper' && <button type="button" className="btn btn-outline-warning btn-sm" disabled={simulationBusyId === portfolio.id} onClick={() => changeSimulationState(portfolio)}>{portfolio.simulation_state === 'paused' ? 'Resume simulation' : 'Pause simulation'}</button>}
                                                {!isActive && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-outline-info btn-sm"
                                                        onClick={() => setActivePortfolio(portfolio.id)}
                                                    >
                                                        Use in this tab
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-secondary btn-sm"
                                                    onClick={() => startRename(portfolio)}
                                                >
                                                    Rename
                                                </button>
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-secondary btn-sm"
                                                    onClick={() => startClone(portfolio)}
                                                >
                                                    Clone as Paper
                                                </button>
                                                {!portfolio.is_default && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-outline-primary btn-sm"
                                                        disabled={defaultingId === portfolio.id}
                                                        onClick={() => setDefault(portfolio.id)}
                                                    >
                                                        {defaultingId === portfolio.id ? 'Saving…' : 'Set default'}
                                                    </button>
                                                )}
                                                {portfolios.length > 1 && !portfolio.is_default && !isActive && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-outline-danger btn-sm"
                                                        disabled={deletingId === portfolio.id}
                                                        onClick={() => deletePortfolio(portfolio)}
                                                    >
                                                        {deletingId === portfolio.id ? 'Deleting…' : 'Delete'}
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                    {isCloning && (
                                        <div className="row g-2 align-items-end mt-3">
                                            <div className="col-md-3">
                                                <label className="form-label small mb-1" htmlFor={`clone-name-${portfolio.id}`}>Paper name</label>
                                                <input id={`clone-name-${portfolio.id}`} className="form-control form-control-sm" value={cloneName} maxLength={120} onChange={(event) => setCloneName(event.target.value)} />
                                            </div>
                                            <div className="col-md-2">
                                                <label className="form-label small mb-1" htmlFor={`clone-cash-${portfolio.id}`}>Simulated cash</label>
                                                <input id={`clone-cash-${portfolio.id}`} type="number" min="0.01" step="0.01" className="form-control form-control-sm" value={cloneCash} onChange={(event) => setCloneCash(event.target.value)} />
                                            </div>
                                            <div className="col-md-3">
                                                <label className="form-label small mb-1" htmlFor={`clone-price-${portfolio.id}`}>Fill price</label>
                                                <select id={`clone-price-${portfolio.id}`} className="form-select form-select-sm" value={clonePriceMethod} onChange={(event) => setClonePriceMethod(event.target.value)}>
                                                    <option value="next_open">Next open</option>
                                                    <option value="next_close">Next close</option>
                                                    <option value="ohlc_average">OHLC average</option>
                                                    <option value="high_low_midpoint">High/low midpoint</option>
                                                </select>
                                            </div>
                                            <div className="col-md-2 form-check mb-1">
                                                <input id={`clone-holdings-${portfolio.id}`} type="checkbox" className="form-check-input" checked={cloneCopyHoldings} onChange={(event) => setCloneCopyHoldings(event.target.checked)} />
                                                <label className="form-check-label small" htmlFor={`clone-holdings-${portfolio.id}`}>Copy holdings</label>
                                            </div>
                                            <div className="col-md-2 d-flex gap-2">
                                                <button type="button" className="btn btn-primary btn-sm" disabled={cloning} onClick={() => cloneAsPaper(portfolio)}>{cloning ? 'Cloning…' : 'Clone'}</button>
                                                <button type="button" className="btn btn-outline-secondary btn-sm" disabled={cloning} onClick={() => setCloneId(null)}>Cancel</button>
                                            </div>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                        {!portfolios.length && (
                            <li className="list-group-item text-muted">
                                No portfolios yet.
                            </li>
                        )}
                    </ul>
                </div>
            </div>
        </div>
    );
}
