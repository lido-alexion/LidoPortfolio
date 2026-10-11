import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import BulkTransactionImport from '../../../resources/js/src/components/BulkTransactionImport.jsx';
import { apiMock } from './helpers/mockApi.js';

it('treats an uncertain import response as unconfirmed and retries the same idempotent batch', async () => {
    const onSaved = vi.fn(); apiMock.post.mockRejectedValueOnce(new Error('Synthetic transport failure')).mockResolvedValueOnce({ data: { status: 'already_committed', row_count: 1 } });
    render(<BulkTransactionImport feeComponents={[]} onSaved={onSaved} />);
    fireEvent.change(screen.getByLabelText('Bulk transaction CSV'), { target: { value: 'Stock,Quantity,Average Price,Transaction Type\nINFY,2,100,BUY' } });
    fireEvent.click(screen.getByRole('button', { name: 'Parse & review' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save all (1)' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Import could not be confirmed');
    expect(screen.getByRole('status')).not.toHaveTextContent('no transactions were committed'); expect(onSaved).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Save all (1)' }));
    await vi.waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
    expect(apiMock.post.mock.calls[0][1]).toEqual(apiMock.post.mock.calls[1][1]);
    expect(screen.queryByRole('button', { name: 'Save all (1)' })).not.toBeInTheDocument(); expect(screen.getByRole('status')).toHaveTextContent('This batch is committed');
});
