import React from 'react';

export default function TablePagination({ meta, onPageChange }) {
    if (!meta || !meta.total || meta.last_page <= 1) {
        return null;
    }

    const page = meta.current_page;
    const last = meta.last_page;

    return (
        <nav className="d-flex justify-content-between align-items-center gap-2 mt-3" aria-label="Table pagination">
            <div className="small text-muted">
                {meta.total != null && (
                    <>
                        {meta.from}–{meta.to} of {meta.total}
                    </>
                )}
            </div>
            <ul className="pagination pagination-sm mb-0">
                <li className={`page-item${page <= 1 ? ' disabled' : ''}`}>
                    <button
                        type="button"
                        className="page-link"
                        disabled={page <= 1}
                        onClick={() => onPageChange(page - 1)}
                    >
                        Previous
                    </button>
                </li>
                <li className="page-item disabled">
                    <span className="page-link">
                        Page {page} of {last}
                    </span>
                </li>
                <li className={`page-item${page >= last ? ' disabled' : ''}`}>
                    <button
                        type="button"
                        className="page-link"
                        disabled={page >= last}
                        onClick={() => onPageChange(page + 1)}
                    >
                        Next
                    </button>
                </li>
            </ul>
        </nav>
    );
}
